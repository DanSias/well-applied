import { Head, Link, router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    DescriptionCompletenessBadge,
    InspectionStateBadge,
    TriageCoverageGroup,
    TriageStageBadge,
} from '@/components/badges';
import { cn } from '@/lib/utils';
import {
    index as jobsIndex,
    create as createJob,
    show as showJob,
} from '@/routes/jobs';
import { store as inspectStore } from '@/routes/jobs/applications';
import { show as applicationsShow } from '@/routes/applications';
import AppShell from '@/layouts/app-shell';
import type {
    JobPostingDiscoveryMetadata,
    JobPostingSummary,
    JobsIndexProps,
    OpportunityTriageFilter,
} from '@/types/job-posting';

const DISCOVERY_SOURCE_LABELS: Record<string, string> = {
    himalayas: 'Himalayas',
    adzuna: 'Adzuna',
    jobicy: 'Jobicy',
};

function discoverySourceLabel(source: string): string {
    return DISCOVERY_SOURCE_LABELS[source] ?? source;
}

function formatCompensation(
    discovery: JobPostingDiscoveryMetadata,
): string | null {
    if (
        discovery.compensation_min === null &&
        discovery.compensation_max === null
    ) {
        return null;
    }

    const currency = discovery.compensation_currency ?? '';
    const min = discovery.compensation_min?.toLocaleString();
    const max = discovery.compensation_max?.toLocaleString();

    if (min && max) {
        return `${currency} ${min}–${max}`.trim();
    }

    return `${currency} ${min ?? max}`.trim();
}

const STATUS_TABS: {
    value: 'all' | 'not_inspected' | 'inspected';
    label: string;
}[] = [
    { value: 'all', label: 'All' },
    { value: 'not_inspected', label: 'Not inspected' },
    { value: 'inspected', label: 'Inspected' },
];

const TRIAGE_STAGES: {
    value: OpportunityTriageFilter;
    label: string;
}[] = [
    { value: 'all', label: 'All stages' },
    { value: 'raw', label: 'Raw' },
    { value: 'in-progress', label: 'In progress' },
    { value: 'analyzed', label: 'Analyzed' },
    { value: 'matched', label: 'Matched' },
    { value: 'failed', label: 'Failed' },
];

/** Pause between the last keystroke and the search hitting the server. */
const SEARCH_DEBOUNCE_MS = 300;

function InspectAction({ job }: { job: JobPostingSummary }) {
    const form = useForm({});

    if (!job.has_source_url) {
        return null;
    }

    if (job.inspection.application_id !== null) {
        return (
            <Link
                href={applicationsShow.url({
                    application: job.inspection.application_id,
                })}
                onClick={(e) => e.stopPropagation()}
                className="inline-flex items-center rounded-md border border-neutral-300 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
            >
                View results
            </Link>
        );
    }

    return (
        <button
            type="button"
            disabled={form.processing}
            onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                form.post(inspectStore.url({ jobPosting: job.id }), {
                    showProgress: false,
                });
            }}
            className="inline-flex items-center rounded-md border border-neutral-300 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            Inspect Application
        </button>
    );
}

export default function JobsIndex({ jobs, filters }: JobsIndexProps) {
    const [q, setQ] = useState(filters.q);
    const searchInputRef = useRef<HTMLInputElement>(null);

    // The input text is local typing state, while `filters.q` is what the
    // server is actually filtering on. The refs below keep the two from
    // fighting:
    //
    // - `qRef` mirrors `q` so the sync effect can compare them without
    //   re-running on every keystroke.
    // - `sentQ` holds the search text of the last navigation we started.
    //   A new visit supersedes the in-flight one — Inertia drops the stale
    //   response — so a `filters.q` update matching `sentQ` is always our
    //   own (possibly late) response and must not clobber text the user is
    //   still typing.
    const qRef = useRef(q);
    const sentQ = useRef(filters.q);
    const pendingSearchId = useRef<number | null>(null);

    const cancelPendingSearch = useCallback(() => {
        if (pendingSearchId.current !== null) {
            window.clearTimeout(pendingSearchId.current);
            pendingSearchId.current = null;
        }
    }, []);

    const applyFilters = (next: Partial<typeof filters>) => {
        cancelPendingSearch();
        sentQ.current = next.q ?? q;
        router.get(
            jobsIndex.url(),
            { q, status: filters.status, triage: filters.triage, ...next },
            { preserveState: true, replace: true },
        );
    };

    const scheduleSearch = (value: string) => {
        cancelPendingSearch();
        pendingSearchId.current = window.setTimeout(() => {
            pendingSearchId.current = null;
            applyFilters({ q: value });
        }, SEARCH_DEBOUNCE_MS);
    };

    const clearSearch = () => {
        qRef.current = '';
        setQ('');
        searchInputRef.current?.focus();
        applyFilters({ q: '' });
    };

    // Drop any pending search if the page unmounts mid-debounce.
    useEffect(() => cancelPendingSearch, [cancelPendingSearch]);

    // A `filters.q` change that did not come from this input (browser
    // back/forward) is adopted; our own responses are skipped so a slow
    // server can't clobber text the user is still typing.
    useEffect(() => {
        if (filters.q !== qRef.current && filters.q !== sentQ.current) {
            cancelPendingSearch();
            qRef.current = filters.q;
            setQ(filters.q);
        }
    }, [cancelPendingSearch, filters.q]);

    return (
        <AppShell>
            <Head title="Opportunities" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Opportunities
                </h1>
                <Link
                    href={createJob.url()}
                    className="inline-flex items-center rounded-md bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                >
                    Add Job
                </Link>
            </div>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                <div className="relative">
                    <input
                        ref={searchInputRef}
                        type="text"
                        value={q}
                        onChange={(e) => {
                            const value = e.target.value;
                            qRef.current = value;
                            setQ(value);
                            scheduleSearch(value);
                        }}
                        placeholder="Search title or company…"
                        aria-label="Search opportunities"
                        className="w-64 rounded-md border border-neutral-300 bg-white py-1.5 pr-8 pl-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                    />
                    {q !== '' && (
                        <button
                            type="button"
                            onClick={clearSearch}
                            aria-label="Clear search"
                            className="absolute inset-y-0 right-0 flex w-7 items-center justify-center text-sm text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300"
                        >
                            ×
                        </button>
                    )}
                </div>

                <div className="flex items-center gap-1 text-sm">
                    {STATUS_TABS.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => applyFilters({ status: tab.value })}
                            className={cn(
                                'rounded-md px-2.5 py-1 font-medium',
                                filters.status === tab.value
                                    ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900'
                                    : 'text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800',
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                <div className="h-4 w-px bg-neutral-200 dark:bg-neutral-800" />

                <div className="flex items-center gap-1 text-sm">
                    {TRIAGE_STAGES.map((stage) => (
                        <button
                            key={stage.value}
                            type="button"
                            onClick={() =>
                                applyFilters({ triage: stage.value })
                            }
                            className={cn(
                                'rounded-md px-2.5 py-1 font-medium',
                                filters.triage === stage.value
                                    ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900'
                                    : 'text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800',
                            )}
                        >
                            {stage.label}
                        </button>
                    ))}
                </div>
            </div>

            {jobs.length === 0 ? (
                <div className="mt-6 rounded-lg border border-dashed border-neutral-300 p-8 text-center dark:border-neutral-700">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        {filters.q ||
                        filters.status !== 'all' ||
                        filters.triage !== 'all'
                            ? 'No opportunities match this search/filter.'
                            : 'No jobs captured yet.'}
                    </p>
                    {!filters.q &&
                        filters.status === 'all' &&
                        filters.triage === 'all' && (
                            <Link
                                href={createJob.url()}
                                className="mt-3 inline-flex items-center text-sm font-medium text-neutral-900 underline underline-offset-2 dark:text-neutral-100"
                            >
                                Add your first job
                            </Link>
                        )}
                </div>
            ) : (
                <ul className="mt-6 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                    {jobs.map((job) => (
                        <li key={job.id}>
                            <Link
                                href={showJob.url(job.id)}
                                className="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                            >
                                <div>
                                    <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                        {job.title}{' '}
                                        <span className="font-normal text-neutral-500 dark:text-neutral-400">
                                            at {job.company}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                        {job.location && (
                                            <span>{job.location} · </span>
                                        )}
                                        {job.discovery.source === 'manual'
                                            ? `Captured ${job.captured_at}`
                                            : `Discovered via ${discoverySourceLabel(job.discovery.source)}`}
                                        {job.discovery.remote_status && (
                                            <span>
                                                {' '}
                                                · {job.discovery.remote_status}
                                            </span>
                                        )}
                                        {formatCompensation(job.discovery) && (
                                            <span>
                                                {' '}
                                                ·{' '}
                                                {formatCompensation(
                                                    job.discovery,
                                                )}
                                            </span>
                                        )}
                                    </p>
                                    {job.triage.coverage !== null && (
                                        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                            <span className="text-neutral-400 dark:text-neutral-500">
                                                Coverage:
                                            </span>{' '}
                                            <TriageCoverageGroup
                                                coverage={job.triage.coverage}
                                            />
                                        </p>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center justify-end gap-x-3 gap-y-1.5">
                                    <TriageStageBadge
                                        value={job.triage.stage}
                                    />
                                    <DescriptionCompletenessBadge
                                        value={
                                            job.discovery
                                                .description_completeness
                                        }
                                    />
                                    <InspectionStateBadge
                                        summary={job.inspection}
                                    />
                                    <InspectAction job={job} />
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
