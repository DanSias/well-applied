// What is sent back to Laravel, and how, without ever re-running the browser.
//
// buildDelivery() projects the engine's report onto exactly the fields Laravel's contract keeps (the same whitelist its
// request validation applies), bounds every string and list, and drops everything else (controls, option lists,
// selected values, the final-state dump). It never adds anything: in particular nothing that says an application was
// submitted. The projection is kept on disk (delivery.json, 0600) until Laravel confirms it.
//
// deliver() uploads the screenshots, then posts the result. Every operation is idempotent on Laravel's side (a repeated
// screenshot or result is a "duplicate"), so only transport failures and 5xx are retried; a definitive answer (accepted,
// stale, rejected) ends the attempt. It reports how it ended; it decides nothing about the browser.
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { PreparerClient } from './client.ts';
import type { Log } from './log.ts';

type Json = Record<string, unknown>;

export interface Delivery {
    version: 1;
    runId: number;
    kind: 'report' | 'failure';
    payload: Json;
    screenshots: Array<{ file: string; sha256: string }>;
}

const REPORT_OUTCOMES = new Set([
    'ready_for_review',
    'input_required',
    'blocked',
    'unsupported',
    'safety_failure',
]);

const str = (v: unknown, max = 500): string | null =>
    typeof v === 'string' ? v.slice(0, max) : null;
const bool = (v: unknown): boolean | null =>
    typeof v === 'boolean' ? v : null;
const int = (v: unknown): number | null =>
    Number.isInteger(v) && (v as number) >= 0 ? (v as number) : null;
const list = (v: unknown, max: number): unknown[] =>
    Array.isArray(v) ? v.slice(0, max) : [];
const obj = (v: unknown): Json =>
    v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Json) : {};
const strings = (v: unknown, max: number): string[] =>
    list(v, max).flatMap((s) =>
        typeof s === 'string' ? [s.slice(0, 500)] : [],
    );
const ints = (v: unknown): Record<string, number> =>
    Object.fromEntries(
        Object.entries(obj(v)).flatMap(([k, n]) =>
            int(n) === null ? [] : [[k, n as number]],
        ),
    );

export function failureDelivery(
    runId: number,
    category: 'navigation_timeout' | 'unsupported_ats' | 'unexpected_error',
    message: string,
): Delivery {
    return {
        version: 1,
        runId,
        kind: 'failure',
        payload: {
            status: 'failed',
            failure_category: category,
            failure_message: message.slice(0, 900),
        },
        screenshots: [],
    };
}

/** An engine that finished with `failed` (or no usable report) is a failed execution, not a report. */
export async function buildDelivery(
    runId: number,
    runDir: string,
    reportJson: unknown,
): Promise<Delivery> {
    const r = obj(reportJson);
    const outcome = str(r.outcome);
    const ats = str(r.ats);

    if (outcome === null || !REPORT_OUTCOMES.has(outcome)) {
        const reasons = strings(r.reasons, 3)
            .map((s) => s.slice(0, 160))
            .join('; ');
        return failureDelivery(
            runId,
            'unexpected_error',
            `the preparation engine did not complete${reasons ? `: ${reasons}` : ''}`,
        );
    }
    if (ats !== 'lever' && ats !== 'greenhouse')
        return failureDelivery(
            runId,
            'unsupported_ats',
            'the engine did not recognise the application site',
        );

    const warnings = strings(r.warnings, 100);
    const fieldsIn = list(r.fields, 400);
    const questionsIn = list(r.questions, 300);
    if (Array.isArray(r.fields) && r.fields.length > 400)
        warnings.push('report truncated: more fields than the contract keeps');

    const fields = fieldsIn.map((f) => {
        const o = obj(f);
        const sensitive = bool(o.sensitive);
        return {
            label: str(o.label),
            kind: str(o.kind),
            required: bool(o.required),
            status: str(o.status),
            classification: str(o.classification, 20),
            mappedTo: str(o.mappedTo, 50),
            sensitive,
            note: str(o.note),
            presence: str(o.presence, 50),
            expected: sensitive ? null : str(o.expected),
            actual: sensitive ? null : str(o.actual),
        };
    });
    // Review evidence, allow-listed. A question may carry (all bounded, all dropped for a sensitive question):
    //  - entered: the value the worker typed and READ BACK from the control (never the intended value): only for a
    //    verified question that came from the profile or an approved answer, with exactly one control;
    //  - intended: the mapped value that was NOT entered (a typeahead a person must complete, or a value that could
    //    not be confirmed), and the resume's file name;
    //  - page_selected: options the PAGE had already selected (a default the worker did not choose).
    // Never sent: option lists, other selected values, control details, or anything for a sensitive question.
    const fieldById = new Map(
        fieldsIn.map((f) => [str(obj(f).id, 40), obj(f)] as const),
    );
    const questions = questionsIn.map((q) => {
        const o = obj(q);
        const sensitive = o.sensitive === true;
        const source = str(obj(o.provenance).source, 30);
        const kind = str(o.kind);
        const status = str(o.status);
        const controls = Array.isArray(o.controls) ? o.controls : [];
        const control =
            controls.length === 1
                ? fieldById.get(str(controls[0], 40))
                : undefined;
        const fromWorker =
            source === 'applicant_fact' || source === 'approved_answer';

        const entered =
            !sensitive &&
            fromWorker &&
            status === 'filled_verified' &&
            kind !== 'file' &&
            kind !== 'checkbox'
                ? str(control?.actual)
                : null;
        const mapped = !sensitive && (fromWorker || source === 'chosen_resume');
        const intended =
            mapped &&
            (status !== 'filled_verified' || source === 'chosen_resume')
                ? str(o.expected)
                : null;
        const pageSelected =
            !sensitive && source === 'none' && Array.isArray(o.selected)
                ? o.selected
                      .flatMap((v) =>
                          typeof v === 'string' && v !== ''
                              ? [v.slice(0, 200)]
                              : [],
                      )
                      .slice(0, 20)
                : [];

        return {
            label: str(o.label),
            labelSource: str(o.labelSource, 50),
            kind,
            required: bool(o.required),
            status,
            review: str(o.review, 30),
            sensitive: bool(o.sensitive),
            provenance: { source, key: str(obj(o.provenance).key, 30) },
            entered,
            intended,
            page_selected: pageSelected,
            note: sensitive ? null : str(o.note, 300),
            presence: str(o.presence, 50),
        };
    });

    const resume = obj(r.resume);
    const upload = obj(resume.upload);
    const response = obj(upload.response);
    const screenshotsIn = list(r.screenshots, 60).map(obj);

    const report: Json = {
        schemaVersion: r.schemaVersion,
        runId: str(r.runId, 64),
        ats,
        requestedUrl: str(r.requestedUrl, 2048),
        finalUrl: str(r.finalUrl, 2048),
        outcome,
        reasons: strings(r.reasons, 100),
        warnings,
        summary: ints(r.summary),
        questionSummary: ints(r.questionSummary),
        fields,
        questions,
        resume: {
            sha256: str(resume.sha256, 64),
            bytes: int(resume.bytes),
            uploaded: bool(resume.uploaded),
            verified: bool(resume.verified),
            verification: str(resume.verification, 40),
            inputHeldChosenResume: bool(resume.inputHeldChosenResume),
            upload:
                Object.keys(upload).length === 0
                    ? null
                    : {
                          outcome: str(upload.outcome, 40),
                          artifactSha256Matches: bool(
                              upload.artifactSha256Matches,
                          ),
                          filenameVisible: bool(upload.filenameVisible),
                          filenameVisibleBefore: bool(
                              upload.filenameVisibleBefore,
                          ),
                          detail: str(upload.detail),
                          refusals: strings(upload.refusals, 20),
                          response:
                              Object.keys(response).length === 0
                                  ? null
                                  : {
                                        exception: str(response.exception, 100),
                                        destination: str(
                                            response.destination,
                                            300,
                                        ),
                                        status: int(response.status),
                                        statusText: str(
                                            response.statusText,
                                            100,
                                        ),
                                        contentLength: int(
                                            response.contentLength,
                                        ),
                                        failure: str(response.failure, 200),
                                        durationMs: int(response.durationMs),
                                    },
                      },
            parser: { status: str(obj(resume.parser).status, 30) },
        },
        blockers: {
            captcha: str(obj(r.blockers).captcha, 30),
            authentication: bool(obj(r.blockers).authentication),
            multistep: bool(obj(r.blockers).multistep),
        },
        // Copied through as the engine stated it; Laravel accepts only "worker_did_not_submit".
        submission: {
            assertion: str(obj(r.submission).assertion, 40),
            suspected: bool(obj(r.submission).suspected),
            blockedAttempts: int(obj(r.submission).blockedAttempts),
        },
        safetyEvents: list(r.safetyEvents, 300).map((e) => {
            const o = obj(e);
            return {
                kind: str(o.kind, 40),
                detail: str(o.detail),
                at: str(o.at, 40),
                phase: str(o.phase, 20),
            };
        }),
        diagnostics: {
            durationMs: int(obj(r.diagnostics).durationMs),
            headless: bool(obj(r.diagnostics).headless),
            held: bool(obj(r.diagnostics).held),
        },
        screenshots: screenshotsIn.map((s) => ({
            file: str(s.file, 80),
            phase: str(s.phase, 20),
            index: int(s.index),
            of: int(s.of),
            scrollY: int(s.scrollY),
            viewportWidth: int(s.viewportWidth),
            viewportHeight: int(s.viewportHeight),
            documentHeight: int(s.documentHeight),
        })),
    };

    const manifest: Delivery['screenshots'] = [];
    for (const s of screenshotsIn) {
        const file = str(s.file, 80);
        if (
            file === null ||
            !/^[a-z][a-z_]{0,19}-\d{2}of\d{2}-y\d{5}\.png$/.test(file)
        )
            continue;
        const bytes = await readFile(join(runDir, file)).catch(() => null);
        if (bytes)
            manifest.push({
                file,
                sha256: createHash('sha256').update(bytes).digest('hex'),
            });
    }

    return {
        version: 1,
        runId,
        kind: 'report',
        payload: { status: 'succeeded', report, screenshots: manifest },
        screenshots: manifest,
    };
}

export type DeliveryEnd =
    | { kind: 'confirmed'; duplicate: boolean }
    /** Laravel no longer accepts anything from this claim (lapsed, finished or superseded). Nothing was overwritten. */
    | { kind: 'stale' }
    /** Laravel refused the report's shape. It will not be retried as sent. */
    | { kind: 'rejected' }
    /** The transport never gave a definitive answer. The delivery is kept for a later, safe retry. */
    | { kind: 'unconfirmed' };

export interface DeliverOptions {
    client: PreparerClient;
    runDir: string;
    attempts: number;
    backoffMs: number;
    log: Log;
    sleep?: (ms: number) => Promise<void>;
    /** Stops delivery at once (lease lost, shutdown). */
    isCancelled?: () => boolean;
}

const wait = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

/** Retry only what is safe to repeat; a definitive HTTP answer is returned, never retried. */
async function retrying<T extends { status: number }>(
    o: DeliverOptions,
    op: () => Promise<T>,
): Promise<T | null> {
    const sleep = o.sleep ?? wait;
    for (let attempt = 1; attempt <= o.attempts; attempt++) {
        if (o.isCancelled?.()) return null;
        try {
            const r = await op();
            if (r.status < 500) return r;
            o.log('delivery_server_error', { status: r.status, attempt });
        } catch {
            o.log('delivery_network_error', { attempt });
        }
        if (attempt < o.attempts) await sleep(o.backoffMs * 2 ** (attempt - 1));
    }
    return null;
}

export async function deliver(
    d: Delivery,
    o: DeliverOptions,
): Promise<DeliveryEnd> {
    // 1. Evidence first. A duplicate is success; a conflict or a bad file is skipped, never overwritten.
    for (const shot of d.screenshots) {
        const bytes = await readFile(join(o.runDir, shot.file)).catch(
            () => null,
        );
        if (!bytes) continue;
        const r = await retrying(o, () =>
            o.client.putScreenshot(d.runId, shot.file, bytes),
        );
        if (r === null) return { kind: 'unconfirmed' };
        if (r.status === 403 || r.status === 404) return { kind: 'stale' };
        if (r.status === 409 && r.body?.reason === 'agent_run_not_running')
            return { kind: 'stale' };
        if (r.status !== 200)
            o.log('screenshot_not_kept', { status: r.status });
    }

    // 2. Then the result.
    const res = await retrying(o, () => o.client.result(d.runId, d.payload));
    if (res === null) return { kind: 'unconfirmed' };
    if (res.status === 200 && res.body?.accepted)
        return { kind: 'confirmed', duplicate: res.body.duplicate === true };
    if (res.status === 409 || res.status === 403 || res.status === 404)
        return { kind: 'stale' };
    if (res.status === 413 || res.status === 422) return { kind: 'rejected' };
    return { kind: 'unconfirmed' };
}
