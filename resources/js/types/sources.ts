export type AttemptStatus =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'partial'
    | 'failed';

export type SourceSchedule = 'automatic' | 'manual_only' | 'unavailable';

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
    effective_schedule: SourceSchedule;
    manual_run_available: boolean;
    stale: boolean;
    /** Scheduled, has succeeded before, and its last success is more than twice the normal cadence old. */
    overdue: boolean;
    /** Automatic and configured, but no attempt has ever been made. Not a failure. */
    never_run: boolean;
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
    unavailable: number;
    needs_attention: number;
    attention_providers: string[];
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
