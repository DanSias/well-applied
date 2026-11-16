import type { Tone } from '@/components/ui/badge';
import type {
    AttemptStatus,
    RunFlash,
    ScheduleFlash,
    SchedulerStatus,
    SourceAttempt,
    SourceConfiguration,
    SourceProvider,
    SourceRun,
    SourceSchedule,
    SourcesSummary,
} from '@/types/sources';

type Chip = { text: string; tone: Tone };

/** Whether the provider has what it needs to run: separate from how it is scheduled and from how its last run went. */
export function availabilityChip(provider: SourceProvider): Chip {
    return provider.configured
        ? { text: 'Configured', tone: 'ok' }
        : { text: 'Not configured', tone: 'warn' };
}

const SCHEDULE_TEXT: Record<SourceSchedule, string> = {
    automatic: 'Automatic',
    manual_only: 'Manual only',
    paused: 'Paused',
    unavailable: 'Unavailable',
};

export function scheduleChip(provider: SourceProvider): Chip {
    return {
        text: SCHEDULE_TEXT[provider.effective_schedule],
        tone: provider.effective_schedule === 'paused' ? 'warn' : 'neutral',
    };
}

/** The toast after a scheduling change, from the schedule it resulted in. */
export function scheduleToast(flash: ScheduleFlash): {
    title: string;
    description: string;
} {
    switch (flash.schedule) {
        case 'automatic':
            return {
                title: `${flash.provider} scheduling is on`,
                description:
                    'It runs automatically when due, while the scheduler is running.',
            };
        case 'paused':
            return {
                title: `${flash.provider} scheduling paused`,
                description:
                    'It will not be picked up automatically. A run already in progress is not stopped, and you can still run it manually.',
            };
        default:
            return {
                title: `${flash.provider} scheduling updated`,
                description: 'It runs only when you run it manually.',
            };
    }
}

/** A run or attempt status as a labelled chip. A successful run that found nothing is still a success, and says so. */
export function statusChip(status: AttemptStatus, noResults = false): Chip {
    switch (status) {
        case 'succeeded':
            return noResults
                ? { text: 'Succeeded · no results', tone: 'ok' }
                : { text: 'Succeeded', tone: 'ok' };
        case 'partial':
            return { text: 'Partial', tone: 'warn' };
        case 'failed':
            return { text: 'Failed', tone: 'bad' };
        case 'running':
            return { text: 'Running', tone: 'accent' };
        case 'queued':
            return { text: 'Queued', tone: 'accent' };
    }
}

/**
 * Failures the system itself records, each with its own words: what happened, in terms of the run rather than the
 * code. A stopped or abandoned run is never shown as a success, and says that what it already added was kept. These are
 * the primary text; the stored message and the exception stay in the attempt's diagnostics.
 */
const SYSTEM_FAILURES: Record<string, { chip: Chip; explanation: string }> = {
    interrupted: {
        chip: { text: 'Interrupted', tone: 'bad' },
        explanation:
            'The run was abandoned: the process or worker stopped before it finished. Anything it had already added was kept.',
    },
    lease_lost: {
        chip: { text: 'Stopped early', tone: 'bad' },
        explanation:
            'The run stopped because it no longer held its claim on this provider (it expired, or another run took over). Anything it had already added was kept.',
    },
    deadline_exceeded: {
        chip: { text: 'Time limit reached', tone: 'warn' },
        explanation:
            'The run reached its time limit and was stopped, so it is incomplete. Anything it had already added was kept; run it again to continue.',
    },
    not_started: {
        chip: { text: 'Not started', tone: 'warn' },
        explanation:
            'The request never began: no queue worker picked it up, or its job could not start. Nothing was run.',
    },
    provider_unavailable: {
        chip: { text: 'Not started', tone: 'warn' },
        explanation:
            'The provider was no longer set up when a worker reached the request, so nothing was run.',
    },
};

/** The user-facing explanation of a failure category the system records, or null for an ordinary provider failure. */
export function systemFailureExplanation(
    category: string | null,
): string | null {
    return category !== null && category in SYSTEM_FAILURES
        ? SYSTEM_FAILURES[category].explanation
        : null;
}

/** How the most recent attempt went; a provider that has never run is its own state. */
export function outcomeChip(attempt: SourceAttempt | null): Chip {
    if (attempt === null) {
        return { text: 'Never run', tone: 'neutral' };
    }

    const category = attempt.failure?.category ?? null;

    if (
        attempt.status === 'failed' &&
        category !== null &&
        category in SYSTEM_FAILURES
    ) {
        return SYSTEM_FAILURES[category].chip;
    }

    return statusChip(attempt.status, attempt.retrieved === 0);
}

/** The provider's outcome chip: a never-run automatic source is flagged as warn, a manual one is just new. */
export function providerOutcomeChip(provider: SourceProvider): Chip {
    const chip = outcomeChip(provider.last_attempt);

    return provider.last_attempt === null && provider.never_run
        ? { ...chip, tone: 'warn' }
        : chip;
}

/** "42" or "—" for a counter that was never recorded. Never turns null into 0. */
export function count(value: number | null): string {
    return value === null ? '—' : String(value);
}

export function formatDuration(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    return `${minutes}m ${seconds % 60}s`;
}

/** The label a count is shown under: Eightfold's is post-prefilter and not comparable with a raw feed count. */
export function retrievedLabel(
    attempt: Pick<SourceAttempt, 'retrieved_basis'>,
): string {
    return attempt.retrieved_basis === 'after_prefilter'
        ? 'Retrieved (after prefilter)'
        : 'Retrieved';
}

const humanize = (key: string) =>
    key.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

/** Provider-reported retrieval diagnostics as label/value rows; nested maps (prefilter reasons) become one row each. */
export function diagnosticRows(
    diagnostics: SourceAttempt['retrieval_diagnostics'],
): { label: string; value: string }[] {
    if (diagnostics === null) {
        return [];
    }

    const rows: { label: string; value: string }[] = [];

    for (const [key, value] of Object.entries(diagnostics)) {
        if (typeof value === 'number') {
            rows.push({ label: humanize(key), value: String(value) });
            continue;
        }

        for (const [inner, n] of Object.entries(value)) {
            rows.push({
                label: `${humanize(key)}: ${humanize(inner)}`,
                value: String(n),
            });
        }
    }

    return rows;
}

/** A run's created / updated across the providers it attempted; null when no attempt recorded the counter. */
export function runTotals(run: SourceRun): {
    created: number | null;
    updated: number | null;
} {
    const sum = (pick: (a: SourceAttempt) => number | null) => {
        const values = run.attempts
            .map(pick)
            .filter((v): v is number => v !== null);

        return values.length === 0
            ? null
            : values.reduce((total, v) => total + v, 0);
    };

    return { created: sum((a) => a.created), updated: sum((a) => a.updated) };
}

/** The sources that need initial setup, kept apart from those with operational trouble. */
export function setupNeeded(providers: SourceProvider[]): string[] {
    return providers.filter((p) => !p.configured).map((p) => p.label);
}

export function attentionNeeded(
    providers: SourceProvider[],
    summary: SourcesSummary,
): string[] {
    return providers
        .filter((p) => summary.attention_providers.includes(p.provider))
        .map((p) => p.label);
}

export function neverRunProviders(
    providers: SourceProvider[],
    summary: SourcesSummary,
): string[] {
    return providers
        .filter((p) => summary.never_run_providers.includes(p.provider))
        .map((p) => p.label);
}

/** Short failure text for a list line: our own wording for system failures, otherwise the message the provider recorded. */
export function failureLine(attempt: SourceAttempt): string | null {
    if (attempt.failure === null) {
        return null;
    }

    return (
        systemFailureExplanation(attempt.failure.category) ??
        attempt.failure.message ??
        attempt.failure.category
    );
}

export type SourcesQuery = {
    provider?: string | null;
    page?: number;
    history_page?: number;
};

/** Query params for the Sources page; defaults are left out so URLs stay clean. */
export function buildSourcesParams(
    query: SourcesQuery,
): Record<string, string | number> {
    const params: Record<string, string | number> = {};

    if (query.provider) {
        params.provider = query.provider;
    }
    if (query.page !== undefined && query.page > 1) {
        params.page = query.page;
    }
    // History paging only means something while a provider is open.
    if (
        query.provider &&
        query.history_page !== undefined &&
        query.history_page > 1
    ) {
        params.history_page = query.history_page;
    }

    return params;
}

/** The Jobs list for every posting found by one source, in every view (not just the Inbox). */
export function discoveredJobsHref(provider: string): string {
    return `/jobs?${new URLSearchParams({ source: provider, disposition: 'all' })}`;
}

export function filteredCandidatesHref(provider: string): string {
    return `/discovery/rejections?${new URLSearchParams({ provider })}`;
}

const CONFIG_LABELS: Record<string, string> = {
    credentials_present: 'Credentials',
    scheduled_by_config: 'Scheduled by configuration',
    feed_host: 'Feed',
    country: 'Country',
    enabled: 'Enabled',
    category: 'Job category',
    hiring_path: 'Hiring path',
    per_page: 'Results per page',
    max_pages: 'Maximum pages',
    hybrid_regions: 'Hybrid / telework regions',
};

/** Read-only configuration as label/value rows. Credentials are only ever "Present" or "Missing". */
export function configurationRows(
    configuration: SourceConfiguration,
): { label: string; value: string }[] {
    const rows: { label: string; value: string }[] = [];

    for (const [key, value] of Object.entries(configuration)) {
        if (key === 'tenants' || key === 'budget') {
            continue;
        }

        let text: string;

        if (key === 'credentials_present') {
            text = value === true ? 'Present' : 'Missing';
        } else if (typeof value === 'boolean') {
            text = value ? 'Yes' : 'No';
        } else if (Array.isArray(value)) {
            text = value.length === 0 ? 'None' : value.join(', ');
        } else if (value === null || value === undefined || value === '') {
            text = '—';
        } else {
            text = String(value);
        }

        rows.push({ label: CONFIG_LABELS[key] ?? humanize(key), value: text });
    }

    return rows;
}

/** The toast after a Run Now request. Only the request is acknowledged here; the outcome arrives when the run finishes. */
export function runRequestToast(flash: RunFlash): {
    kind: 'success' | 'info' | 'error';
    title: string;
    description: string;
} {
    switch (flash.outcome) {
        case 'queued':
            return {
                kind: 'success',
                title: `${flash.provider} run queued`,
                description:
                    'It starts when a queue worker picks it up. This page updates as it runs.',
            };
        case 'already_active':
            return {
                kind: 'info',
                title: `${flash.provider} is already queued or running`,
                description: 'Nothing new was started.',
            };
        case 'unavailable':
            return {
                kind: 'error',
                title: `${flash.provider} can't run yet`,
                description: 'It needs setup first.',
            };
        default:
            return {
                kind: 'error',
                title: `Couldn't queue ${flash.provider}`,
                description:
                    'The queue did not accept the request, so nothing was started. Try again.',
            };
    }
}

/** What a finished run produced, for the toast that closes a Run Now the page was watching. */
export function runFinishedToast(provider: SourceProvider): {
    kind: 'success' | 'warning' | 'error';
    title: string;
    description: string | undefined;
} | null {
    const attempt = provider.last_attempt;

    if (
        attempt === null ||
        attempt.status === 'queued' ||
        attempt.status === 'running'
    ) {
        return null;
    }

    if (attempt.status === 'failed') {
        return {
            kind: 'error',
            title: `${provider.label} run failed`,
            description: failureLine(attempt) ?? undefined,
        };
    }

    const found = `Created ${count(attempt.created)}, updated ${count(attempt.updated)}`;

    return attempt.status === 'partial'
        ? {
              kind: 'warning',
              title: `${provider.label} finished with problems`,
              description: `${found}. ${failureLine(attempt) ?? ''}`.trim(),
          }
        : {
              kind: 'success',
              title: `${provider.label} run finished`,
              description: found,
          };
}

function ago(seconds: number): string {
    if (seconds < 90) {
        return 'less than 2 minutes';
    }
    if (seconds < 5400) {
        return `${Math.round(seconds / 60)} minutes`;
    }

    return `${Math.round(seconds / 3600)} hours`;
}

/**
 * One honest line about the scheduler, from its heartbeat only. It says nothing about queue workers or providers, and
 * it only warns when something is supposed to be automatic.
 */
export function schedulerNote(
    scheduler: SchedulerStatus,
    automaticProviders: number,
): { tone: 'ok' | 'warn' | 'neutral'; text: string } | null {
    if (scheduler.state === 'recent') {
        return {
            tone: 'ok',
            text: 'Scheduler running: last seen less than 3 minutes ago.',
        };
    }

    if (automaticProviders === 0) {
        return null;
    }

    if (scheduler.state === 'stale') {
        return {
            tone: 'warn',
            text: `The scheduler was last seen ${ago(scheduler.age_seconds ?? 0)} ago, so automatic discovery is not running. Start it with php artisan schedule:work (php artisan dev does).`,
        };
    }

    return {
        tone: 'neutral',
        text: 'The scheduler has not been seen yet. Automatic discovery only runs while it is running (php artisan dev starts it).',
    };
}
