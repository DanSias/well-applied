// Laravel's frozen input snapshot -> the engine's existing PrepareInput. Strict and allow-listed: only the keys the
// engine understands survive, only as strings, and the URL must be the one that was claimed. Nothing is invented: a key
// Laravel left out (LinkedIn, current company, an ambiguous first/last name) stays absent, and the engine reports the
// field as unanswered for a person.
import { createHash } from 'node:crypto';
import type { ApplicantKey, PrepareInput } from '../contracts.ts';

const APPLICANT_KEYS: ReadonlySet<string> = new Set<ApplicantKey>([
    'full_name',
    'first_name',
    'last_name',
    'email',
    'phone',
    'location',
    'current_company',
    'linkedin_url',
    'github_url',
    'portfolio_url',
]);

export class InputError extends Error {
    readonly reason: string;

    constructor(reason: string) {
        super(reason);
        this.reason = reason;
    }
}

export const sha256 = (bytes: Buffer): string =>
    createHash('sha256').update(bytes).digest('hex');

export function safeResumeName(name: unknown): string {
    const s = typeof name === 'string' ? name.replace(/^.*[\\/]/, '') : '';
    return /^[A-Za-z0-9][A-Za-z0-9._-]{0,98}\.pdf$/.test(s) ? s : 'resume.pdf';
}

const originAndPath = (raw: string): string => {
    const u = new URL(raw);
    return `${u.origin}${u.pathname}`;
};

export interface ClaimFacts {
    agentRunId: number;
    applicationUrl: string | null;
    resumeSha256: string | null;
    resumeBytes: number | null;
}

/**
 * Verifies the downloaded resume against everything Laravel pinned it to: the claim's hash and size, the input
 * snapshot's hash, and the hash header on the response. Any disagreement means do not use it.
 */
export function verifyResume(
    bytes: Buffer,
    headerSha: string | null,
    claim: ClaimFacts,
    inputResume: { sha256?: unknown; bytes?: unknown },
): void {
    const actual = sha256(bytes);
    const pinned = [claim.resumeSha256, inputResume.sha256, headerSha];
    if (pinned.some((p) => typeof p !== 'string' || p !== actual))
        throw new InputError('resume_hash_mismatch');
    if (
        (claim.resumeBytes !== null && claim.resumeBytes !== bytes.length) ||
        (typeof inputResume.bytes === 'number' &&
            inputResume.bytes !== bytes.length)
    )
        throw new InputError('resume_size_mismatch');
    if (bytes.subarray(0, 5).toString('latin1') !== '%PDF-')
        throw new InputError('resume_not_pdf');
}

export function toPrepareInput(
    claim: ClaimFacts,
    input: Record<string, unknown>,
    resumePdfPath: string,
): { prepare: PrepareInput; sent: string[]; ignored: number } {
    if (input.schema_version !== 1)
        throw new InputError('input_schema_unsupported');
    if (input.agent_run_id !== claim.agentRunId)
        throw new InputError('input_run_mismatch');

    const url = input.application_url;
    if (typeof url !== 'string') throw new InputError('input_url_missing');
    try {
        if (
            claim.applicationUrl === null ||
            originAndPath(url) !== originAndPath(claim.applicationUrl)
        )
            throw new Error('mismatch');
    } catch {
        throw new InputError('input_url_mismatch');
    }

    const raw = input.applicant;
    if (raw === null || typeof raw !== 'object' || Array.isArray(raw))
        throw new InputError('input_applicant_invalid');

    const applicant: Partial<Record<ApplicantKey, string>> = {};
    let ignored = 0;
    for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
        if (
            APPLICANT_KEYS.has(key) &&
            typeof value === 'string' &&
            value.trim() !== ''
        )
            applicant[key as ApplicantKey] = value;
        else ignored++;
    }

    const answers: Record<string, string> = {};
    const rawAnswers = input.answers;
    if (
        rawAnswers !== null &&
        typeof rawAnswers === 'object' &&
        !Array.isArray(rawAnswers)
    )
        for (const [k, v] of Object.entries(
            rawAnswers as Record<string, unknown>,
        ))
            if (typeof v === 'string') answers[k] = v;

    return {
        prepare: {
            schemaVersion: 1,
            runId: `wa-${claim.agentRunId}`,
            applicationUrl: url,
            resumePdfPath,
            applicant,
            answers,
        },
        sent: Object.keys(applicant).sort(),
        ignored,
    };
}
