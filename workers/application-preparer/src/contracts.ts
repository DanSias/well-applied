// Input/report contracts. Hand-maintained (no codegen), the same
// convention as workers/browser-inspector/src/result.js. The label-source
// vocabulary below is copied from that worker / app/Enums/
// ApplicationQuestionLabelSource.php so a later mapping onto
// ApplicationQuestion needs no translation.

/** Input schema version. */
export const SCHEMA_VERSION = 1;
/** Report schema version. 2: screenshots are structured records (was a list of file names). */
export const REPORT_SCHEMA_VERSION = 2;

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

export type FieldKind =
    | 'text'
    | 'textarea'
    | 'select'
    | 'radio'
    | 'checkbox'
    | 'file';

/** known = mapped to a supplied value; uncertain = ambiguous match; unknown = no mapping. */
export type Classification = 'known' | 'uncertain' | 'unknown';

/**
 * filled_verified: value written and read back equal.
 * unanswered_optional: nothing to enter, field is not required. Not a problem.
 * unresolved_required: nothing (or nothing trustworthy) to enter, field IS required.
 * unresolved_uncertain: an ambiguous mapping was refused rather than guessed.
 * unverified: a value was entered but what a person sees could not be confirmed
 * (control not visible, or a composite/autocomplete widget whose committed value
 * could not be established). Never reported as filled_verified.
 * manual_action_required: deliberately NOT filled. The control is a typeahead/
 * combobox where a suggestion must be chosen, which this worker cannot do (it
 * has no click). The intended value stays in `expected`; a person completes it.

 * error: an attempted fill failed, or read-back did not match.
 *
 * The four are distinct: a missing approved answer is unanswered_optional /
 * unresolved_required; an intentionally skipped interactive control is
 * manual_action_required; a value written but not confirmed is unverified; an
 * execution failure or changed value is error.
 */
export type FieldStatus =
    | 'filled_verified'
    | 'unanswered_optional'
    | 'unresolved_required'
    | 'unresolved_uncertain'
    | 'unverified'
    | 'manual_action_required'
    | 'error';

export interface DiscoveredField {
    /**
     * Identifies this DOM element across discoveries within a run, e.g. "f3". Never
     * positional: an element keeps its id when others are inserted, removed or
     * reordered, and an id is never reused for a different element.
     */
    id: string;
    /** The HTML autocomplete attribute (lower-cased), when present. Structural evidence of purpose. */
    autocomplete: string | null;
    /**
     * Logical identity independent of the DOM node: kind, input type, name, id attribute and
     * normalised label. Lets a field be re-found if a framework re-renders its element.
     */
    key: string;
    /** Nth field (document order) with the same key. */
    occurrence: number;
    /** More than one field currently shares this key: it cannot be tracked by key alone. */
    ambiguous: boolean;
    /** Order in the document (changes when fields are inserted or reordered; use `id`/`key` for identity). */
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
    /**
     * Set when the field is no longer (unambiguously) on the page after the upload: it is still
     * reported, attributed to its own logical identity, never to whatever now has its old position.
     */
    presence?: 'removed_after_upload' | 'ambiguous_after_upload';
    /** Evidence about the user-visible control behind a text field (no values). */
    control?: {
        visible: boolean;
        composite: boolean;
        signals: string[];
        hiddenValueMatches: boolean | null;
    };
}

export type SafetyEventKind =
    | 'blocked_mutating_request'
    | 'blocked_third_party_request'
    | 'blocked_bot_detection_request'
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
 * exception), blocked_third_party_request (a non-ATS host such as an
 * analytics beacon) and blocked_bot_detection_request (one specific
 * Cloudflare beacon). The last two are still blocked, but are routine page
 * noise, not submission signals.
 */
export const SAFETY_FAILURE_KINDS: ReadonlySet<SafetyEventKind> = new Set([
    'blocked_mutating_request',
    'submit_event_blocked',
    'unexpected_navigation',
    'suspected_submission',
]);

/**
 * none: no captcha markup. inactive_markup: present but hidden/zero-size/
 * off-screen/declared invisible (a page initialising a widget; nothing for a
 * person to do yet). challenge_visible: a displayed challenge or checkbox
 * that needs a person. uncertain: displayed markup that could not be
 * classified; reported as such, never as a confirmed blocker or a confirmed
 * absence.
 */
export type CaptchaState =
    | 'none'
    | 'inactive_markup'
    | 'challenge_visible'
    | 'uncertain';

export interface CaptchaDetail {
    tag: string;
    role:
        | 'badge'
        | 'challenge_frame'
        | 'checkbox_widget'
        | 'frame_other'
        | 'container';
    title: string;
    srcHost: string;
    /** Path of the frame's src (no query), e.g. /recaptcha/enterprise/anchor. */
    srcPath: string;
    /** The `size` query parameter of the frame's src, when present (reCAPTCHA: invisible | normal | compact). */
    sizeParam: string | null;
    /** Inside a .grecaptcha-badge container. */
    inBadge: boolean;
    width: number;
    height: number;
    signals: string[];
    verdict: 'inactive' | 'challenge' | 'uncertain';
}

export type ScreenshotPhase = 'before' | 'after_fill' | 'end_of_hold';

/** A sticky/fixed element on screen when a shot was taken (what could obscure content). */
export interface FixedOverlay {
    tag: string;
    id: string | null;
    className: string | null;
    position: 'fixed' | 'sticky';
    /** Viewport-relative top and height in px. */
    top: number;
    height: number;
    anchored: 'top' | 'bottom' | 'floating';
}

/** One viewport-sized capture: which shot it is and exactly where on the page. */
export interface ScreenshotRecord {
    file: string;
    phase: ScreenshotPhase;
    /** 1-based position within this phase's sequence, and the sequence length. */
    index: number;
    of: number;
    scrollY: number;
    viewportWidth: number;
    viewportHeight: number;
    documentHeight: number;
    fixedOverlays: FixedOverlay[];
}

/**
 * What the page visibly says about the resume parser, never inferred from the
 * request being allowed or from the file hash:
 *  success: a visible success indicator.   failure: a visible failure indicator.
 *  unknown: no terminal indicator within the bounded wait (still working, none
 *           visible, or conflicting indicators): neither success nor failure is claimed.
 *  not_observable: this ATS has no known parser indicator.
 *  not_attempted: no resume was attached, or no parse request was allowed and nothing appeared.
 */
export type ResumeParserStatus =
    | 'success'
    | 'failure'
    | 'unknown'
    | 'not_observable'
    | 'not_attempted';

export interface ResumeParserReport {
    /** The adapter knows how to read this ATS's parser indicator. */
    observable: boolean;
    /** The parse request was permitted by the adapter's named exception (counted by the guard). */
    requestAllowed: boolean;
    /** Latest observed state (updated at the end of a hold). */
    status: ResumeParserStatus;
    detail: string;
    /** Milliseconds spent waiting for a terminal state in the latest observation. */
    waitedMs: number;
    /** The state when preparation ended, kept after the hold updates `status`. */
    atPreparation: ResumeParserStatus;
}

export type HoldEnd =
    | 'enter'
    | 'stop_file'
    | 'window_closed'
    | 'browser_disconnected'
    | 'callback';

/** The applicant-facing state of the form when a hold ended (values of non-sensitive fields only). */
export interface FinalState {
    capturedAt: string;
    url: string;
    fieldValues: Array<{
        id: string;
        label: string | null;
        kind: FieldKind;
        /** The field's status at the end of preparation (unchanged by anything a person did). */
        statusAtPreparation: FieldStatus;
        /** What the form displays now; null if it could not be read. */
        displayed: string | null;
    }>;
}

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
    schemaVersion: 2;
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
        unverified: number;
        manualAction: number;
        errors: number;
    };
    resume: {
        sha256: string;
        bytes: number;
        fieldId: string | null;
        uploaded: boolean;
        /** The guard let the adapter's resume-upload request through (structure, destination, window and count validated). */
        uploadRequestAllowed: boolean;
        /** Right after selection, the browser's own copy of the file in the input hashed equal to the chosen resume. */
        inputHeldChosenResume: boolean;
        /**
         * content_sha256: hash re-computed from the browser's copy of the file input.
         * upload_request: the widget removed its input after the upload. Verified from three facts: the input held
         * exactly the chosen resume (SHA-256) when the upload was triggered, the guard allowed the one validated
         * upload request, and the page shows the resume's file name. Not a content hash of the bytes in transit
         * (the guard cannot see them).
         * input_only: the input holds the chosen resume, but for an ATS whose attachment is a separate upload step the
         * upload was not confirmed (no allowed upload request, or the file name never appeared); NOT verified.
         * name_size: weaker. page_text: input emptied, name shown, no validated upload request; NOT verified.
         */
        verification:
            | 'content_sha256'
            | 'name_size'
            | 'upload_request'
            | 'input_only'
            | 'page_text'
            | 'none';
        verified: boolean;
        observedFilename: string | null;
        /**
         * Four separate facts: bytes attached and verified (`verified` above), the parse
         * request allowed, the parser visibly succeeded, or it failed / is unresolved.
         */
        parser: ResumeParserReport;
        /** How the resume control was tracked across the DOM changes its upload may cause. */
        identity: {
            logicalKey: string | null;
            tracking:
                | 'same_element'
                | 'rematched_by_key'
                | 'ambiguous'
                | 'removed'
                | 'not_found';
            note: string;
        };
    };
    controls: Array<{
        text: string;
        type: string;
        role: 'submit' | 'navigation' | 'other';
        /** Not rendered visible (e.g. a hidden type=submit a page script clicks). Reported, never activated. */
        hidden: boolean;
    }>;
    blockers: {
        captcha: CaptchaState;
        /** Per-element evidence behind `captcha` (tag, title, host, size, why inactive). No page text. */
        captchaDetails: CaptchaDetail[];
        authentication: boolean;
        multistep: boolean;
    };
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
    screenshots: ScreenshotRecord[];
    warnings: string[];
    diagnostics: {
        durationMs: number;
        headless: boolean;
        held: boolean;
        /** How and when a hold ended; null when there was no hold. */
        hold: { endedBy: HoldEnd; durationMs: number } | null;
    };
    /** Form state captured when a hold ended gracefully; null if there was no hold or the page was already closed. */
    finalState: FinalState | null;
}
