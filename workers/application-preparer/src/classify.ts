// Deterministic field classification. No model, no fuzzy matching: a field
// is mapped only when its name attribute or its whole normalised label is
// a known phrase. Anything ambiguous is `uncertain` and is NOT filled.
import type {
    ApplicantKey,
    Classification,
    DiscoveredField,
    PrepareInput,
} from './contracts.ts';

export function normalizeLabel(label: string | null): string {
    return (label ?? '')
        .replace(/[✱*]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

const SENSITIVE =
    /gender|\brace\b|ethnic|veteran|disabilit|pronoun|sexual orientation|hispanic|latino|transgender|religio|\beeo\b|demographic/i;

// Lever / Greenhouse `name` attributes that identify a standard field.
const NAME_HINTS: Record<string, ApplicantKey> = {
    name: 'full_name',
    email: 'email',
    phone: 'phone',
    org: 'current_company',
    location: 'location',
    'urls[LinkedIn]': 'linkedin_url',
    'urls[GitHub]': 'github_url',
    'urls[Github]': 'github_url', // the spelling observed on a live Lever form
    'urls[Portfolio]': 'portfolio_url',
    first_name: 'first_name',
    last_name: 'last_name',
};

// The HTML autocomplete attribute is deliberate, standardised, machine-readable evidence of what a
// field is for. Only these unambiguous tokens are used; "off", "on" and every other token are ignored.
const AUTOCOMPLETE_HINTS: Record<string, ApplicantKey> = {
    'given-name': 'first_name',
    'family-name': 'last_name',
    name: 'full_name',
    email: 'email',
    tel: 'phone',
    'tel-national': 'phone',
    organization: 'current_company',
};

const LABEL_PATTERNS: Array<[RegExp, ApplicantKey]> = [
    [/^(legal )?first name$/, 'first_name'],
    [/^(legal )?(last|family) name$/, 'last_name'],
    [/^(full|legal full|your) name$|^name$/, 'full_name'],
    [/^(e-?mail)( address)?$/, 'email'],
    [/^(phone|mobile|telephone)( number)?$/, 'phone'],
    [/^(current )?(location|city)( \(.*\))?$/, 'location'],
    [
        /^current (company|employer)$|^(company|employer|organization)$/,
        'current_company',
    ],
    [/^linkedin( profile)?( url| link)?$/, 'linkedin_url'],
    [/^github( profile)?( url| link)?$/, 'github_url'],
    [/^(portfolio|website|personal website)( url| link)?$/, 'portfolio_url'],
];

export interface Classified {
    classification: Classification;
    mappedTo: ApplicantKey | 'resume' | 'answer' | null;
    sensitive: boolean;
    /** The text to enter, or the option label to choose. */
    value?: string;
    /** For radio groups: index into field.options. */
    optionIndex?: number;
    note?: string;
}

const STANDARD_FIELD_WORD =
    /\bname\b|phone|e-?mail|address|location|company|employer/;
const QUESTION_OPENER =
    /^(are|is|was|were|do|does|did|have|has|had|will|would|can|could|should|may|what|which|who|whom|whose|why|how|when|where|if|please|tell|describe|explain|list|provide|select|choose|indicate|confirm|by)\b/;

/** A sentence-like label: a question, an instruction, or too long to be a field name. */
export function looksLikeQuestion(label: string): boolean {
    const words = label.split(/\s+/).filter(Boolean);
    return (
        label.includes('?') || QUESTION_OPENER.test(label) || words.length > 5
    );
}

export function applicantValue(
    input: Pick<PrepareInput, 'applicant'>,
    key: ApplicantKey,
): string | null {
    const a = input.applicant;
    const direct = a[key]?.trim();
    if (direct) return direct;
    if (key === 'full_name') {
        const joined = [a.first_name, a.last_name]
            .map((s) => s?.trim())
            .filter(Boolean)
            .join(' ');
        return joined || null;
    }
    return null;
}

function keysFor(field: DiscoveredField): Set<ApplicantKey> {
    const keys = new Set<ApplicantKey>();
    const hint = field.name ? NAME_HINTS[field.name] : undefined;
    if (hint) keys.add(hint);
    const label = normalizeLabel(field.label);
    for (const [pattern, key] of LABEL_PATTERNS)
        if (pattern.test(label)) keys.add(key);
    if (field.kind === 'text') {
        if (field.inputType === 'email') keys.add('email');
        if (field.inputType === 'tel') keys.add('phone');
        // The last token of a space-separated autocomplete value is the field's purpose
        // ("section-x shipping email" -> email).
        const token = field.autocomplete?.split(/\s+/).filter(Boolean).at(-1);
        const hinted = token ? AUTOCOMPLETE_HINTS[token] : undefined;
        if (hinted) keys.add(hinted);
    }
    return keys;
}

export function classify(
    field: DiscoveredField,
    input: PrepareInput,
): Classified {
    const label = normalizeLabel(field.label);
    const identity = `${label} ${field.name ?? ''} ${field.domId ?? ''}`;

    if (SENSITIVE.test(identity)) {
        return {
            classification: 'unknown',
            mappedTo: null,
            sensitive: true,
            note: 'demographic/EEO question: never auto-filled',
        };
    }

    if (field.kind === 'file') {
        if (/cover/i.test(identity))
            return {
                classification: 'unknown',
                mappedTo: null,
                sensitive: false,
                note: 'cover letter: not supplied in Phase 0',
            };
        if (/resume|\bcv\b|curriculum/i.test(identity))
            return {
                classification: 'known',
                mappedTo: 'resume',
                sensitive: false,
            };
        return {
            classification: 'unknown',
            mappedTo: null,
            sensitive: false,
            note: 'unrecognised file upload',
        };
    }

    if (field.kind === 'select' || field.kind === 'radio') {
        const answer = input.answers?.[label];
        if (answer === undefined)
            return {
                classification: 'unknown',
                mappedTo: null,
                sensitive: false,
                note: 'no approved answer supplied',
            };
        const index = field.options?.findIndex((o) => o === answer) ?? -1;
        if (index < 0)
            return {
                classification: 'uncertain',
                mappedTo: null,
                sensitive: false,
                note: 'approved answer is not exactly one of the options',
            };
        return {
            classification: 'known',
            mappedTo: 'answer',
            sensitive: false,
            value: answer,
            optionIndex: index,
        };
    }

    if (field.kind === 'checkbox') {
        return {
            classification: 'unknown',
            mappedTo: null,
            sensitive: false,
            note: 'checkboxes are never auto-filled in Phase 0',
        };
    }

    // text / textarea
    const keys = keysFor(field);
    if (keys.size > 1)
        return {
            classification: 'uncertain',
            mappedTo: null,
            sensitive: false,
            note: `conflicting matches: ${[...keys].join(', ')}`,
        };
    const [key] = [...keys];
    if (key === undefined) {
        // "Resembles a standard field" only for a short label that is not a question or a sentence. A
        // custom question that merely mentions "Company" or "address" ("Are you authorized to work
        // for our Company?") is just an unmapped question, not a possible contact field.
        const loose =
            STANDARD_FIELD_WORD.test(label) && !looksLikeQuestion(label);
        return loose
            ? {
                  classification: 'uncertain',
                  mappedTo: null,
                  sensitive: false,
                  note: 'label resembles a standard field but is not an exact match',
              }
            : { classification: 'unknown', mappedTo: null, sensitive: false };
    }
    const value = applicantValue(input, key);
    if (value === null)
        return {
            classification: 'unknown',
            mappedTo: null,
            sensitive: false,
            note: `no applicant value supplied for ${key}`,
        };
    return { classification: 'known', mappedTo: key, sensitive: false, value };
}
