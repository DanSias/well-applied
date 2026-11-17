// Input/report contracts. Hand-maintained (no codegen), the same
// convention as workers/browser-inspector/src/result.js. The label-source
// vocabulary below is copied from that worker / app/Enums/
// ApplicationQuestionLabelSource.php so a later mapping onto
// ApplicationQuestion needs no translation.

export const SCHEMA_VERSION = 1;

export const LabelSource = {
    DomLabelFor: 'dom_label_for',
    DomWrappingLabel: 'dom_wrapping_label',
    DomAriaLabel: 'dom_aria_label',
    DomAriaLabelledby: 'dom_aria_labelledby',
    DomPlaceholder: 'dom_placeholder',
    Unresolved: 'unresolved',
} as const;
export type LabelSource = (typeof LabelSource)[keyof typeof LabelSource];

export type AtsName = 'lever' | 'greenhouse';

export type ApplicantKey =
    | 'full_name'
    | 'first_name'
    | 'last_name'
    | 'email'
    | 'phone'
    | 'location'
    | 'current_company'
    | 'linkedin_url'
    | 'github_url'
    | 'portfolio_url';

export interface PrepareInput {
    schemaVersion: 1;
    /** Used as the run directory name; [A-Za-z0-9_-] only. Generated if absent. */
    runId?: string;
    applicationUrl: string;
    /** Local path to the PDF to attach; checked and hashed before the browser opens. */
    resumePdfPath: string;
    /** Approved applicant facts. A missing/null key means "I have no answer". */
    applicant: Partial<Record<ApplicantKey, string | null>>;
    /**
     * Pre-approved answers to specific questions, keyed by the question's
     * normalised label (lower-case, collapsed whitespace, trailing `*`/`✱`
     * removed). Only applied to select and radio fields, and only when the
     * answer exactly equals one of the field's option labels.
     */
    answers?: Record<string, string>;
}

export type FieldKind = 'text' | 'textarea' | 'select' | 'radio' | 'checkbox' | 'file';

/** known = mapped to a supplied value; uncertain = ambiguous match; unknown = no mapping. */
export type Classification = 'known' | 'uncertain' | 'unknown';

/**
 * filled_verified: value written and read back equal.
 * unanswered_optional: nothing to enter, field is not required. Not a problem.
 * unresolved_required: nothing (or nothing trustworthy) to enter, field IS required.
 * unresolved_uncertain: an ambiguous mapping was refused rather than guessed.
 * error: an attempted fill failed, or read-back did not match.
 */
export type FieldStatus = 'filled_verified' | 'unanswered_optional' | 'unresolved_required' | 'unresolved_uncertain' | 'error';

export interface DiscoveredField {
    /** Stable within a run; assigned by discovery, e.g. "f3". */
    id: string;
    position: number;
    kind: FieldKind;
    tag: string;
    inputType: string | null;
    name: string | null;
    domId: string | null;
    label: string | null;
    labelSource: LabelSource;
    required: boolean | null;
    options: string[] | null;
    /** true for demographic/EEO style questions: never auto-filled, values never recorded. */
    sensitive: boolean;
}

export interface FieldResult extends DiscoveredField {
    classification: Classification;
    mappedTo: ApplicantKey | 'resume' | 'answer' | null;
    status: FieldStatus;
    expected?: string;
    actual?: string;
    note?: string;
}

export type SafetyEventKind =
    | 'blocked_mutating_request'
    | 'blocked_third_party_request'
    | 'submit_event_blocked'
    | 'unexpected_navigation'
    | 'suspected_submission'
    | 'allowed_exception_used';

export interface SafetyEvent {
    kind: SafetyEventKind;
    detail: string;
    at: string;
    phase: 'preparation' | 'human_hold';
}

/**
 * Failures: every kind except allowed_exception_used (a tested, named
 * exception) and blocked_third_party_request (a non-ATS host such as an
 * analytics beacon; still blocked, but routine page noise, not a
 * submission signal).
 */
export const SAFETY_FAILURE_KINDS: ReadonlySet<SafetyEventKind> = new Set([
    'blocked_mutating_request',
    'submit_event_blocked',
    'unexpected_navigation',
    'suspected_submission',
]);

export type Outcome =
    /** Every required field verified. Optional fields may be unanswered (see summary). */
    | 'ready_for_review'
    /** Form understood and filled as far as possible, but required answers are missing. */
    | 'input_required'
    /** A human must act first: captcha, login, multistep, unverifiable upload. */
    | 'blocked'
    | 'unsupported'
    /** Something touched, or tried to touch, submission. Never ready. */
    | 'safety_failure'
    | 'failed';

export interface PrepareReport {
    schemaVersion: 1;
    runId: string;
    startedAt: string;
    finishedAt: string;
    ats: AtsName | null;
    requestedUrl: string;
    finalUrl: string | null;
    outcome: Outcome;
    /** Why the outcome is not ready_for_review (empty when it is). */
    reasons: string[];
    fields: FieldResult[];
    summary: {
        total: number;
        filledVerified: number;
        unansweredOptional: number;
        unresolvedRequired: number;
        unresolvedUncertain: number;
        errors: number;
    };
    resume: {
        sha256: string;
        bytes: number;
        fieldId: string | null;
        uploaded: boolean;
        /** content_sha256: hash re-computed from the browser's copy. name_size: weaker. page_text: input was emptied but the name is shown; NOT counted as verified. */
        verification: 'content_sha256' | 'name_size' | 'page_text' | 'none';
        verified: boolean;
        observedFilename: string | null;
    };
    controls: Array<{ text: string; type: string; role: 'submit' | 'navigation' | 'other' }>;
    blockers: { captcha: boolean; authentication: boolean; multistep: boolean };
    /**
     * `assertion` records only what THIS worker did: it never invoked a
     * submit capability (none exists). It is not proof that nothing was
     * submitted; `safetyEvents` and `suspected` are the evidence to read.
     */
    submission: {
        assertion: 'worker_did_not_submit';
        blockedAttempts: number;
        suspected: boolean;
    };
    safetyEvents: SafetyEvent[];
    screenshots: string[];
    warnings: string[];
    diagnostics: { durationMs: number; headless: boolean; held: boolean };
}
