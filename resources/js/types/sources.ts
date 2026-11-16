export type AttemptStatus =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'partial'
    | 'failed';

export type SourceSchedule =
    | 'automatic'
    | 'manual_only'
    | 'paused'
    | 'unavailable';

export type ScheduleOverride = 'default' | 'paused' | 'enabled';

/** The one scheduling action the server offers for a provider; null when it needs setup first. */
export type ScheduleControl = { label: string; override: ScheduleOverride };

/** Run Now state: what the server will accept, and the queued or running execution that rules it out. */
export type RunNow = {
    available: boolean;
    active: 'queued' | 'running' | null;
    attempt_id: number | null;
    since: string | null;
    unavailable_reason: string | null;
};

export type RunOutcome =
    | 'queued'
    | 'already_active'
    | 'unavailable'
    | 'not_queued';

/** Flashed once after a Run Now request. */
export type RunFlash = { provider: string; outcome: RunOutcome };

export type SchedulerStatus = {
    state: 'recent' | 'stale' | 'unknown';
    last_observed_at: string | null;
    age_seconds: number | null;
};

/** Flashed once after a scheduling change. `schedule` is the resulting effective schedule. */
export type ScheduleFlash = { provider: string; schedule: SourceSchedule };

export type SourceFailure = {
    category: string | null;
    message: string | null;
    exception_class: string | null;
};

/** One provider's attempt inside one run. A null counter means "not recorded"; 0 means none. */
export type SourceAttempt = {
    id: number;
    run_id: number;
    provider: string;
    status: AttemptStatus;
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
    retrieved: number | null;
    accepted: number | null;
    created: number | null;
    updated: number | null;
    canonicalized: number | null;
    filtered: number | null;
    filtered_by_reason: { code: string; label: string; count: number }[];
    /** Eightfold's retrieved count is what survived its own prefilter, not a raw feed count. */
    retrieved_basis: 'feed' | 'after_prefilter';
    retrieval_diagnostics: Record<
        string,
        number | Record<string, number>
    > | null;
    failure: SourceFailure | null;
};

export type SourceProvider = {
    provider: string;
    label: string;
    registered: boolean;
    configured: boolean;
    unavailable_reason: string | null;
    default_schedule: 'automatic' | 'manual_only';
    schedule_override: ScheduleOverride;
    effective_schedule: SourceSchedule;
    schedule_control: ScheduleControl | null;
    manual_run_available: boolean;
    stale: boolean;
    /** Scheduled, has succeeded before, and its last success is more than twice the normal cadence old. */
    overdue: boolean;
    /** Automatic and configured, but no attempt has ever been made. Not a failure. */
    never_run: boolean;
    run_now: RunNow;
    needs_attention: boolean;
    last_attempt: SourceAttempt | null;
    last_success: SourceAttempt | null;
    last_failure: SourceAttempt | null;
    jobs_added: number;
    window: { attempts: number; failed: number; partial: number };
};

export type SourcesSummary = {
    window_days: number;
    since: string;
    total: number;
    configured: number;
    automatic: number;
    manual_only: number;
    paused: number;
    unavailable: number;
    needs_attention: number;
    attention_providers: string[];
    scheduler: SchedulerStatus;
    never_run: number;
    never_run_providers: string[];
    runs_in_window: number;
    last_run_at: string | null;
    ever_ran: boolean;
    jobs_added: number;
};

export type SourcesPagination = {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number;
    to: number;
};

export type SourceRun = {
    id: number;
    status: AttemptStatus;
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
    needs_attention: boolean;
    attempts: SourceAttempt[];
};

export type SourceConfiguration = Record<string, unknown>;

export type SourceDetail = {
    provider: string;
    configuration: SourceConfiguration;
    history: { attempts: SourceAttempt[]; pagination: SourcesPagination };
};

export type SourcesProps = {
    summary: SourcesSummary;
    providers: SourceProvider[];
    activity: { runs: SourceRun[]; pagination: SourcesPagination };
    filters: { provider: string | null; history_page: number };
    /** Loaded on demand for the drawer; null when none is open. */
    selected: SourceDetail | null;
};
