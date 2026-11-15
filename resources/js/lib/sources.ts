import type { Tone } from '@/components/ui/badge';
import type {
    AttemptStatus,
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
    unavailable: 'Unavailable',
};

export function scheduleChip(provider: SourceProvider): Chip {
    return {
        text: SCHEDULE_TEXT[provider.effective_schedule],
        tone: 'neutral',
    };
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

/** How the most recent attempt went; a provider that has never run is its own state. */
export function outcomeChip(attempt: SourceAttempt | null): Chip {
    return attempt === null
        ? { text: 'Never run', tone: 'neutral' }
        : statusChip(attempt.status, attempt.retrieved === 0);
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

/** Short failure text for a list line: category-free, the message the provider recorded. */
export function failureLine(attempt: SourceAttempt): string | null {
    if (attempt.failure === null) {
        return null;
    }

    return attempt.failure.message ?? attempt.failure.category;
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
