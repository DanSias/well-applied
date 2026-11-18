/**
 * Matches App\Support\ApplicationPreparation\PresentPreparationPanel, PresentApplicationPreparation and
 * PresentPreparationReview exactly: the only shapes the browser is ever handed for application preparation. None of them
 * carries an applicant value, a path or a resume byte, and none can say an application was submitted.
 */
export type PreparationExecution =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

export type PreparationOutcome =
    | 'ready_for_review'
    | 'input_required'
    | 'blocked'
    | 'unsupported'
    | 'safety_failure';

export type PreparationPhase =
    | 'opening'
    | 'filling'
    | 'uploading'
    | 'verifying'
    | 'capturing'
    | 'delivering';

export type PreparationInputs = {
    resume_variant_id: number | null;
    resume_sha256: string | null;
    applicant_keys: string[];
    omitted_keys: Record<string, string>;
};

export type PreparationWorkflowRun = {
    id: number;
    status: 'pending' | 'running' | 'succeeded' | 'failed' | 'cancelled';
    failure_message: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type PreparationAttemptNow = {
    agent_run_id: number;
    status: PreparationExecution;
    queued_at: string | null;
    started_at: string | null;
    phase: PreparationPhase | null;
    failure_category: string | null;
    inputs: PreparationInputs | null;
};

export type PreparationAttemptRow = {
    agent_run_id: number;
    workflow_run_id: number;
    status: PreparationExecution;
    failure_category: string | null;
    outcome: PreparationOutcome | null;
    reviewable: boolean;
    queued_at: string | null;
    finished_at: string | null;
    resume_variant_id: number | null;
    resume_sha256: string | null;
};

export type PreparationLatestResult = {
    agent_run_id: number;
    finished_at: string | null;
    outcome: PreparationOutcome;
    reviewable: boolean;
    integrity_flags: string[];
    counts: Record<string, number>;
};

export type PreparationState = {
    application_id: number;
    workflow_run: PreparationWorkflowRun | null;
    attempt: PreparationAttemptNow | null;
    attempts: PreparationAttemptRow[];
    latest_result: PreparationLatestResult | null;
};

export type PreparationResumeOption = {
    id: number;
    label: string;
    summary: string;
    generated_at: string;
    is_final: boolean;
    is_current: boolean;
};

export type PreparationPanel = {
    job_id: number;
    can_prepare: boolean;
    problem: { code: string; message: string } | null;
    destination: { host: string; path: string } | null;
    resumes: {
        default_id: number | null;
        default_kind: 'final' | 'current' | null;
        options: PreparationResumeOption[];
    };
    state: PreparationState | null;
};

export type ReviewQuestion = {
    /** Null when the page gave the question no label: never borrowed from an option. */
    label: string | null;
    required: boolean | null;
    kind: string | null;
    status: string | null;
    review: string | null;
    sensitive: boolean;
    /** Where a value came from; `key` is the profile fact, never a value. */
    provenance: { source: string | null; key: string | null };
    /** What the worker read back from the form (verified, from your profile or an approved answer). */
    entered: string | null;
    /** What it meant to enter but did not (a person must complete it), or the resume's file name. */
    intended: string | null;
    /** Options the page had already selected itself. */
    page_selected: string[];
    note: string | null;
};

export type ReviewScreenshot = {
    file: string;
    phase: string | null;
    index: number | null;
    of: number | null;
    stored: boolean;
    url: string | null;
};

export type ReviewSafetyEvent = {
    kind: string;
    detail: string;
    failure: boolean;
};

export type PreparationReview = {
    agent_run_id: number;
    status: PreparationExecution;
    submission: { occurred: false; statement: string };
    resume: {
        variant_id: number | null;
        sha256: string | null;
        bytes: number | null;
        verified?: boolean;
        verification?: string | null;
        reported_sha256_matches?: boolean;
        upload?: {
            outcome: string | null;
            http_status: number | null;
            filename_visible: boolean | null;
            detail: string | null;
        } | null;
    };
} & (
    | {
          available: false;
          failure_category: string | null;
          failure_message: string | null;
      }
    | {
          available: true;
          /** The values are what the worker saw while preparing; the page's final state is not captured. */
          evidence: { observed_at: 'preparation'; final_state_captured: false };
          outcome: PreparationOutcome;
          reported_outcome: PreparationOutcome;
          reviewable: boolean;
          integrity_flags: string[];
          ats: string | null;
          reasons: string[];
          warnings: string[];
          counts: Record<string, number>;
          question_summary: Record<string, number>;
          verified: ReviewQuestion[];
          needs_answer: ReviewQuestion[];
          optional_skipped: ReviewQuestion[];
          needs_person: ReviewQuestion[];
          needs_check: ReviewQuestion[];
          failed: ReviewQuestion[];
          safety_events: ReviewSafetyEvent[];
          blockers: {
              captcha?: string | null;
              authentication?: boolean | null;
              multistep?: boolean | null;
          };
          screenshots: ReviewScreenshot[];
      }
);
