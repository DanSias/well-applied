import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import {
    analyze as batchAnalyze,
    match as batchMatch,
} from '@/routes/jobs/batch';
import { show as applicationsShow } from '@/routes/applications';
import AppShell from '@/layouts/app-shell';
import type {
    BatchOperation,
    BatchResult,
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

/** Hard cap on how many opportunities one batch may process. */
const BATCH_MAX_ITEMS = 50;

/**
 * Readable labels for the backend's fixed set of batch reason literals.
 * Anything not listed here is rendered with underscores replaced by
 * spaces so a future literal still reads as a sentence fragment.
 */
const BATCH_REASON_LABELS: Record<string, string> = {
    in_flight: 'already in flight',
    claim_failed: 'could not be queued',
    analysis_unavailable: 'no analysis available',
};

/**
 * "3 started, 2 skipped, 1 failed" — zero-count buckets omitted, fixed
 * order, read straight off the result's counts.
 */
function batchSummaryText(result: BatchResult): string {
    const parts: string[] = [];
    if (result.started > 0) {
        parts.push(`${result.started} started`);
    }
    if (result.skipped > 0) {
        parts.push(`${result.skipped} skipped`);
    }
    if (result.failed > 0) {
        parts.push(`${result.failed} failed`);
    }
    if (result.not_found > 0) {
        parts.push(`${result.not_found} not found`);
    }
    return parts.length > 0 ? parts.join(', ') : 'no items processed';
}

/**
 * Grouped reason counts over the result's items — "2 already in flight",
 * "1 no analysis available" — in first-seen order, null reasons skipped.
 */
function batchReasonLines(result: BatchResult): string[] {
    const counts = new Map<string, number>();
    for (const item of result.items) {
        if (item.reason === null) {
            continue;
        }
        counts.set(item.reason, (counts.get(item.reason) ?? 0) + 1);
    }
    const lines: string[] = [];
    counts.forEach((count, reason) => {
        lines.push(
            `${count} ${BATCH_REASON_LABELS[reason] ?? reason.replaceAll('_', ' ').trim()}`,
        );
    });
    return lines;
}

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

    // E4 batch selection — ephemeral, local state. `selectedIds` holds
    // the checked job ids regardless of their presence in the current
    // list; an effect below prunes ids that no longer resolve to a
    // visible row so filtered-out rows can never be (re-)submitted.
    const [selectedIds, setSelectedIds] = useState<Set<number>>(
        () => new Set(),
    );
    const [pendingOp, setPendingOp] = useState<BatchOperation | null>(null);
    const batchForm = useForm<{ job_ids: number[] }>({ job_ids: [] });

    // The batch result is one-shot Inertia flash data: read at the top
    // level, never copied into local state. A new arrival (a new object
    // from the server) re-shows the panel after a prior dismissal.
    const batchResult = usePage().flash?.batch_result;
    const [batchResultDismissed, setBatchResultDismissed] = useState(false);
    const seenBatchResult = useRef(batchResult);
    useEffect(() => {
        if (seenBatchResult.current !== batchResult) {
            seenBatchResult.current = batchResult;
            setBatchResultDismissed(false);
        }
    }, [batchResult]);

    const selectedVisible = useMemo(
        () =>
            new Set(
                jobs.map((job) => job.id).filter((id) => selectedIds.has(id)),
            ),
        [jobs, selectedIds],
    );

    // Anti-resurrection: drop checked ids that are no longer visible
    // (e.g. after a search/filter navigation removed their rows).
    useEffect(() => {
        setSelectedIds((prev) => {
            if (prev.size === 0) {
                return prev;
            }
            const visible = new Set(jobs.map((job) => job.id));
            let pruned = false;
            const next = new Set<number>();
            for (const id of prev) {
                if (visible.has(id)) {
                    next.add(id);
                } else {
                    pruned = true;
                }
            }
            return pruned ? next : prev;
        });
    }, [jobs]);

    const selectAllRef = useRef<HTMLInputElement>(null);
    const allSelected = jobs.length > 0 && selectedVisible.size === jobs.length;
    const anySelected = selectedVisible.size > 0;

    // "Some but not all visible rows checked" is DOM-only state — expose
    // it straight on the input's indeterminate property.
    useEffect(() => {
        if (selectAllRef.current !== null) {
            selectAllRef.current.indeterminate = anySelected && !allSelected;
        }
    }, [anySelected, allSelected]);

    const toggleJob = (jobId: number, checked: boolean) => {
        if (pendingOp !== null) {
            return;
        }
        // A re-selection invalidates any error from the prior attempt; drop it
        // so a message about the old selection can't linger after it changes.
        batchForm.clearErrors();
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (checked) {
                if (next.has(jobId) || next.size >= BATCH_MAX_ITEMS) {
                    return prev; // cap: no silent truncation
                }
                next.add(jobId);
            } else {
                next.delete(jobId);
            }
            return next;
        });
    };

    const toggleAll = () => {
        if (pendingOp !== null || jobs.length > BATCH_MAX_ITEMS) {
            return;
        }
        // Selection changed — a stale error about the prior selection is no
        // longer accurate, so clear it.
        batchForm.clearErrors();
        if (selectAllRef.current !== null) {
            selectAllRef.current.indeterminate = false;
        }
        // Toggle-all: everything visible already checked → clear,
        // otherwise check everything visible (guarded above to ≤ 50).
        setSelectedIds(
            allSelected
                ? new Set<number>()
                : new Set(jobs.map((job) => job.id)),
        );
    };

    const submitBatch = (operation: BatchOperation) => {
        const ids = [...selectedVisible];
        if (ids.length < 1 || ids.length > BATCH_MAX_ITEMS) {
            return;
        }
        setBatchResultDismissed(false);
        setPendingOp(operation);
        batchForm.clearErrors();
        batchForm.setData({ job_ids: ids });
        batchForm.post(
            operation === 'analyze' ? batchAnalyze.url() : batchMatch.url(),
            {
                showProgress: false,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSelectedIds(new Set<number>());
                    batchForm.clearErrors();
                },
                onFinish: () => {
                    setPendingOp(null);
                },
            },
        );
    };

    const batchErrors = batchForm.errors as unknown as Record<
        string,
        string | undefined
    >;
    const hasIndexedBatchError = Object.keys(batchErrors).some((key) =>
        key.startsWith('job_ids.'),
    );
    const batchError =
        batchErrors['job_ids'] ??
        (hasIndexedBatchError
            ? 'One of the selected opportunities could not be processed.'
            : undefined);
    const reasonLines =
        batchResult === undefined ? [] : batchReasonLines(batchResult);

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

            {batchResult !== undefined && !batchResultDismissed && (
                <div
                    role="status"
                    aria-live="polite"
                    className="mt-4 rounded-lg border border-neutral-300 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                {batchResult.operation === 'analyze'
                                    ? 'Analyze'
                                    : 'Match'}{' '}
                                batch — {batchSummaryText(batchResult)}
                            </p>
                            {reasonLines.length > 0 && (
                                <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                    {reasonLines.join(' · ')}
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => setBatchResultDismissed(true)}
                            aria-label="Dismiss batch result"
                            className="rounded-md px-1.5 text-base leading-none text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300"
                        >
                            ×
                        </button>
                    </div>
                </div>
            )}

            {jobs.length > 0 && (
                <div className="mt-4 rounded-lg border border-neutral-200 bg-white px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <label
                            className={
                                jobs.length > BATCH_MAX_ITEMS
                                    ? 'flex cursor-not-allowed items-center gap-2 opacity-50'
                                    : 'flex cursor-pointer items-center gap-2'
                            }
                        >
                            <input
                                ref={selectAllRef}
                                type="checkbox"
                                checked={allSelected}
                                disabled={
                                    pendingOp !== null ||
                                    jobs.length > BATCH_MAX_ITEMS
                                }
                                onChange={toggleAll}
                                aria-label="Select all visible opportunities"
                                className="h-4 w-4 accent-neutral-900 dark:accent-neutral-100"
                            />
                            <span className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                Select all visible opportunities
                            </span>
                        </label>
                        {jobs.length > BATCH_MAX_ITEMS && (
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                Only 50 can be selected per batch. Narrow the
                                list with search or filters
                            </p>
                        )}
                    </div>

                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3 dark:border-neutral-800">
                        <p
                            aria-live="polite"
                            className="text-sm text-neutral-500 dark:text-neutral-400"
                        >
                            {selectedVisible.size} of {BATCH_MAX_ITEMS} selected
                        </p>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                disabled={pendingOp !== null || !anySelected}
                                onClick={() => submitBatch('analyze')}
                                className="inline-flex items-center rounded-md bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                            >
                                {pendingOp === 'analyze'
                                    ? 'Analyzing…'
                                    : 'Analyze selected'}
                            </button>
                            <button
                                type="button"
                                disabled={pendingOp !== null || !anySelected}
                                onClick={() => submitBatch('match')}
                                className="inline-flex items-center rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            >
                                {pendingOp === 'match'
                                    ? 'Matching…'
                                    : 'Match selected'}
                            </button>
                            <button
                                type="button"
                                disabled={pendingOp !== null || !anySelected}
                                onClick={() => {
                                    setSelectedIds(new Set<number>());
                                    // Selection changed — clear a stale error
                                    // from the prior attempt.
                                    batchForm.clearErrors();
                                }}
                                className="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 disabled:opacity-50 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-300"
                            >
                                Clear
                            </button>
                        </div>
                    </div>

                    {batchError !== undefined && (
                        <p
                            role="alert"
                            className="mt-3 text-sm text-red-600 dark:text-red-400"
                        >
                            {batchError}
                        </p>
                    )}
                </div>
            )}

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
                        <li key={job.id} className="flex items-stretch">
                            <div className="flex items-start pt-4 pl-4">
                                <input
                                    type="checkbox"
                                    checked={selectedVisible.has(job.id)}
                                    disabled={
                                        pendingOp !== null ||
                                        (!selectedVisible.has(job.id) &&
                                            selectedVisible.size >=
                                                BATCH_MAX_ITEMS)
                                    }
                                    onChange={(e) =>
                                        toggleJob(job.id, e.target.checked)
                                    }
                                    aria-label={`Select ${job.title} at ${job.company}`}
                                    className="mt-0.5 h-4 w-4 shrink-0 accent-neutral-900 dark:accent-neutral-100"
                                />
                            </div>
                            <Link
                                href={showJob.url(job.id)}
                                className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3 p-4 hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
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
