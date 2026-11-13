import {
    Head,
    Link,
    router,
    useForm,
    usePage,
    usePoll,
} from '@inertiajs/react';
import {
    Fragment,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import {
    DescriptionCompletenessBadge,
    InspectionStateBadge,
    TriageCoverageGroup,
    TriageStageBadge,
} from '@/components/badges';
import {
    DecisionActions,
    DecisionResultPanel,
    DismissStrip,
    DispositionBadge,
    type DecideFn,
} from '@/components/job-decisions';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { TextInput } from '@/components/ui/field';
import { Pagination } from '@/components/ui/pagination';
import { SegmentedControl } from '@/components/ui/segmented';
import { Select } from '@/components/ui/select';
import { Card } from '@/components/ui/surface';
import AppShell from '@/layouts/app-shell';
import {
    BATCH_MAX_ITEMS,
    DECISION_MAX_ITEMS,
    bulkDecisionsFor,
    buildListParams,
    nextListState,
    pruneSelection,
    toggleAllSelection,
    toggleSelection,
} from '@/lib/opportunities-list';
import { formatCompensation } from '@/lib/job-display';
import { cn } from '@/lib/utils';
import { show as applicationsShow } from '@/routes/applications';
import {
    index as jobsIndex,
    create as createJob,
    show as showJob,
} from '@/routes/jobs';
import { store as inspectStore } from '@/routes/jobs/applications';
import {
    analyze as batchAnalyze,
    decision as batchDecision,
    match as batchMatch,
} from '@/routes/jobs/batch';
import { store as decisionStore } from '@/routes/jobs/decision';
import { undo as decisionsUndo } from '@/routes/jobs/decisions';
import type {
    BatchOperation,
    BatchResult,
    DecisionResult,
    JobDisposition,
    JobDispositionFilter,
    DismissalReason,
    JobPostingSummary,
    JobsIndexProps,
    OpportunityResumeFilter,
    OpportunityTriageFilter,
} from '@/types/job-posting';

const DISCOVERY_SOURCE_LABELS: Record<string, string> = {
    himalayas: 'Himalayas',
    adzuna: 'Adzuna',
    jobicy: 'Jobicy',
    eightfold: 'Eightfold',
};

function discoverySourceLabel(source: string): string {
    return DISCOVERY_SOURCE_LABELS[source] ?? source;
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
    { value: 'raw', label: 'Not analyzed' },
    { value: 'in-progress', label: 'In progress' },
    { value: 'analyzed', label: 'Analyzed' },
    { value: 'matched', label: 'Matched' },
    { value: 'failed', label: 'Failed' },
];

const DISPOSITION_TABS: { value: JobDispositionFilter; label: string }[] = [
    { value: 'inbox', label: 'Inbox' },
    { value: 'shortlist', label: 'Shortlist' },
    { value: 'dismissed', label: 'Dismissed' },
    { value: 'all', label: 'All' },
];

const VIEW_NAMES: Record<JobDispositionFilter, string> = {
    inbox: 'the Inbox',
    shortlist: 'the Shortlist',
    dismissed: 'Dismissed',
    all: 'All jobs',
};

const RESUME_TABS: { value: OpportunityResumeFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'in-progress', label: 'In progress' },
    { value: 'final', label: 'Final' },
];

/** What the active filters mean in words, for the empty state. */
function describeFilters(filters: JobsIndexProps['filters']): string {
    const parts: string[] = [];
    const stage = TRIAGE_STAGES.find((s) => s.value === filters.triage);

    if (filters.resume === 'in-progress') {
        parts.push('a resume in progress (none marked final)');
    } else if (filters.resume === 'final') {
        parts.push('a final resume chosen');
    }

    if (stage !== undefined && stage.value !== 'all') {
        parts.push(`the stage “${stage.label}”`);
    }

    if (filters.status !== 'all') {
        parts.push(
            filters.status === 'inspected'
                ? 'an inspected application'
                : 'no application inspected yet',
        );
    }

    if (filters.q !== '') {
        parts.push(`“${filters.q}” in the title or company`);
    }

    return parts.length > 0
        ? `Nothing in ${VIEW_NAMES[filters.disposition]} has ${parts.join(' and ')}.`
        : `Nothing in ${VIEW_NAMES[filters.disposition]} matches.`;
}

/** Pause between the last keystroke and the search hitting the server. */
const SEARCH_DEBOUNCE_MS = 300;

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

/** A small caption beside one filter row, so the groups read as separate filters. */
function FilterGroup({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex max-w-full min-w-0 items-center gap-2">
            <span className="text-fg-subtle text-xs font-medium">{label}</span>
            {children}
        </div>
    );
}

function InspectAction({ job }: { job: JobPostingSummary }) {
    const form = useForm({});

    if (!job.has_source_url) {
        return null;
    }

    if (job.inspection.application_id !== null) {
        return (
            <ButtonLink
                variant="ghost"
                size="sm"
                href={applicationsShow.url({
                    application: job.inspection.application_id,
                })}
            >
                View results
            </ButtonLink>
        );
    }

    // Not inspected is the common case, so this stays quiet until the row
    // is hovered or focused (always visible on small and touch screens).
    return (
        <Button
            variant="ghost"
            size="sm"
            disabled={form.processing}
            className="sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100 pointer-coarse:opacity-100"
            onClick={() =>
                form.post(inspectStore.url({ jobPosting: job.id }), {
                    showProgress: false,
                })
            }
        >
            Inspect Application
        </Button>
    );
}

/**
 * One job. The whole row shares one hover/selected surface; the
 * title link is stretched over the row (so any click opens the job) and
 * the checkbox and actions sit above it, independent of that link.
 */
function JobRow({
    job,
    selected,
    checkboxDisabled,
    decisionsDisabled,
    dismissing,
    onToggle,
    onDecide,
    onStartDismiss,
    onCancelDismiss,
}: {
    job: JobPostingSummary;
    selected: boolean;
    checkboxDisabled: boolean;
    decisionsDisabled: boolean;
    dismissing: boolean;
    onToggle: (checked: boolean) => void;
    onDecide: DecideFn;
    onStartDismiss: () => void;
    onCancelDismiss: () => void;
}) {
    const meta = [
        job.location,
        job.discovery.source === 'manual'
            ? `Captured ${job.captured_at}`
            : `Discovered via ${discoverySourceLabel(job.discovery.source)}`,
        job.discovery.remote_status,
        formatCompensation(job.discovery),
    ].filter((item): item is string => Boolean(item));
    const inspection = job.inspection;
    const showInspection =
        inspection.state !== 'not_inspected' ||
        inspection.latest_attempt_failed;

    return (
        <li
            data-selected={selected || undefined}
            className={cn(
                'group relative grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-2 px-4 py-3 transition-colors md:grid-cols-[auto_minmax(0,1fr)_auto]',
                'has-[a.row-link:focus-visible]:ring-focus has-[a.row-link:focus-visible]:ring-2 has-[a.row-link:focus-visible]:ring-inset',
                selected
                    ? 'bg-accent-soft/60 hover:bg-accent-soft'
                    : 'hover:bg-raised/70',
            )}
        >
            {selected && (
                <span
                    aria-hidden="true"
                    className="bg-accent absolute inset-y-0 left-0 w-0.5"
                />
            )}
            <label className="relative z-10 -mx-2 -mt-1.5 -mb-2 flex cursor-pointer p-2 pt-2.5">
                <Checkbox
                    checked={selected}
                    disabled={checkboxDisabled}
                    onChange={(e) => onToggle(e.target.checked)}
                    aria-label={`Select ${job.title} at ${job.company}`}
                />
            </label>

            <div className="min-w-0">
                <p className="text-sm leading-snug break-words">
                    <Link
                        href={showJob.url(job.id)}
                        className="row-link text-fg font-medium after:absolute after:inset-0 focus-visible:outline-none"
                    >
                        {job.title}
                    </Link>
                    <span className="text-fg-muted"> · {job.company}</span>
                </p>
                <p className="text-fg-subtle mt-0.5 text-xs leading-relaxed break-words">
                    {meta.map((item, i) => (
                        <Fragment key={i}>
                            {i > 0 && ' · '}
                            {item}
                        </Fragment>
                    ))}
                </p>
                {job.triage.coverage !== null && (
                    <p className="text-fg-subtle mt-1 text-xs">
                        <TriageCoverageGroup coverage={job.triage.coverage} />
                    </p>
                )}
            </div>

            <div className="relative z-10 col-start-2 flex flex-col gap-1.5 md:col-start-3 md:row-start-1 md:items-end">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5 md:justify-end">
                    <DispositionBadge disposition={job.disposition} />
                    <TriageStageBadge value={job.triage.stage} />
                    {job.discovery.description_completeness === 'preview' && (
                        <DescriptionCompletenessBadge
                            value={job.discovery.description_completeness}
                        />
                    )}
                    {showInspection && (
                        <InspectionStateBadge summary={inspection} />
                    )}
                </div>
                <div className="-ml-2.5 flex flex-wrap items-center gap-x-1 gap-y-1 md:ml-0 md:justify-end">
                    <DecisionActions
                        disposition={job.disposition.value}
                        jobLabel={`${job.title} at ${job.company}`}
                        disabled={decisionsDisabled}
                        onDecide={onDecide}
                        onStartDismiss={onStartDismiss}
                    />
                    <InspectAction job={job} />
                </div>
            </div>

            {dismissing && (
                <div className="border-line relative z-10 col-span-full border-t pt-2.5">
                    <DismissStrip
                        heading="Dismiss because…"
                        disabled={decisionsDisabled}
                        onPick={(reason, note) =>
                            onDecide('dismissed', { reason, note })
                        }
                        onCancel={onCancelDismiss}
                    />
                </div>
            )}
        </li>
    );
}

export default function JobsIndex({
    jobs,
    filters,
    pagination,
    disposition_counts,
}: JobsIndexProps) {
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

    /**
     * Every list-changing navigation goes through here so the URL always
     * carries search, filters, page and page size (defaults omitted).
     * Anything except a page change resets to page 1.
     */
    const navigate = (
        next: Partial<{
            q: string;
            status: string;
            triage: string;
            resume: string;
            disposition: string;
            page: number;
            per_page: number;
        }>,
        options: { replace?: boolean; preserveScroll?: boolean } = {},
    ) => {
        cancelPendingSearch();
        sentQ.current = next.q ?? q;
        const state = nextListState(
            {
                q,
                status: filters.status,
                triage: filters.triage,
                resume: filters.resume,
                disposition: filters.disposition,
                per_page: pagination.per_page,
                page: pagination.page,
            },
            next,
        );
        const params = buildListParams(state);
        router.get(jobsIndex.url(), params, {
            preserveState: true,
            replace: options.replace ?? true,
            preserveScroll: options.preserveScroll ?? true,
        });
    };

    const applyFilters = (next: {
        q?: string;
        status?: string;
        triage?: string;
        resume?: string;
    }) => navigate(next);

    // The decision view is the page's primary navigation, so each one is a history entry: Back returns to the last view.
    const showView = (disposition: JobDispositionFilter) =>
        navigate({ disposition }, { replace: false });

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

    const goToPage = (page: number) =>
        navigate({ page }, { replace: false, preserveScroll: false });

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

    // Batch selection — ephemeral, local state, scoped to the rows on the
    // current page. An effect below prunes ids that no longer resolve to a
    // visible row, so changing page, search or filters always clears the
    // selection and a row that is not on screen can never be submitted.
    const [selectedIds, setSelectedIds] = useState<Set<number>>(
        () => new Set(),
    );
    const [pendingOp, setPendingOp] = useState<BatchOperation | null>(null);

    // Decisions (shortlist / dismiss / pursue / restore, one job or a selection). The result arrives as flash data and is
    // copied into page state for the same reason the batch result is (a poll clears the flash).
    const [decisionPending, setDecisionPending] = useState(false);
    const [dismissing, setDismissing] = useState<number | 'selection' | null>(
        null,
    );
    const flashDecisionResult = usePage().flash?.decision_result;
    const [decisionResult, setDecisionResult] = useState<
        DecisionResult | undefined
    >(flashDecisionResult);
    const seenDecisionResult = useRef<DecisionResult | undefined>(
        flashDecisionResult,
    );
    useEffect(() => {
        if (
            flashDecisionResult !== undefined &&
            seenDecisionResult.current !== flashDecisionResult
        ) {
            seenDecisionResult.current = flashDecisionResult;
            setDecisionResult(flashDecisionResult);
        }
    }, [flashDecisionResult]);
    const batchForm = useForm<{ job_ids: number[] }>({ job_ids: [] });

    // The batch result arrives as one-shot Inertia flash data. E5
    // intentionally copies the latest arrival into page-local state: a
    // jobs-only poll response clears `page.flash` (to `{}`), which would
    // otherwise make the banner vanish ~4s after the batch completes. The
    // copy is ephemeral page state — no storage of any kind, and leaving
    // /jobs loses it with the unmount. A later new arrival (a new object
    // in the flash) replaces it and re-shows the panel after a dismissal.
    const flashBatchResult = usePage().flash?.batch_result;
    const [batchResult, setBatchResult] = useState<BatchResult | undefined>(
        flashBatchResult,
    );
    const [batchResultDismissed, setBatchResultDismissed] = useState(false);
    const seenBatchResult = useRef<BatchResult | undefined>(flashBatchResult);
    useEffect(() => {
        // Copy only genuine new arrivals — an object currently present in
        // the flash and different from the previous arrival. Ignoring the
        // flash clearing (undefined) is deliberate: the poll clearing
        // `page.flash` must not dismiss a result the user is looking at.
        if (
            flashBatchResult !== undefined &&
            seenBatchResult.current !== flashBatchResult
        ) {
            seenBatchResult.current = flashBatchResult;
            setBatchResult(flashBatchResult);
            setBatchResultDismissed(false);
        }
    }, [flashBatchResult]);

    // E5 lightweight polling: while any *visible* opportunity is actively
    // analyzing or matching, refresh only the list every few seconds so
    // rows settle (analyzing → analyzed / analysis-failed, matching →
    // matched / match-failed) without a manual refresh. Derived purely from
    // the current visible jobs — hidden rows, batch-form state and history
    // never drive the polling.
    const hasInFlight = jobs.some(
        (job) =>
            job.triage.stage === 'analyzing' || job.triage.stage === 'matching',
    );

    // A poll fired while a *synchronous* user visit (search, filter, batch
    // post, navigation) is still in flight would target the pre-navigation
    // window.location.href and could apply stale rows on top of it. Inertia
    // fires exactly one `finish` per `start` — success, network error and
    // cancellation all funnel through it — so a plain start/finish pair
    // cannot leave this flag stuck. The same pair drives the loading state.
    const userVisitInFlight = useRef(false);
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const onStart = router.on('start', (event) => {
            if (event.detail.visit.async === false) {
                userVisitInFlight.current = true;
                setLoading(true);
            }
        });
        const onFinish = router.on('finish', (event) => {
            if (event.detail.visit.async === false) {
                userVisitInFlight.current = false;
                setLoading(false);
            }
        });
        return () => {
            onStart();
            onFinish();
        };
    }, []);

    // `cancel` mode prevents overlapping poll requests and stale poll
    // responses; `autoStart: false` because starts/stops below are the sole
    // gate. `router.reload` already implies preserveState/preserveScroll, so
    // rows keep their scroll position and selection.
    const { start: startPolling, stop: stopPolling } = usePoll(
        4000,
        () => ({
            only: ['jobs', 'pagination', 'disposition_counts'],
            showProgress: false,
            onBefore: () => !userVisitInFlight.current,
        }),
        {
            autoStart: false,
            keepAlive: false,
            mode: 'cancel',
        },
    );

    // keepAlive: false only *throttles* hidden-tab polling; E5 requires a
    // real pause, so the start/stop gate on tab visibility too.
    useEffect(() => {
        if (hasInFlight && !document.hidden) {
            startPolling();
        } else {
            stopPolling();
        }

        const onVisibilityChange = () => {
            if (document.hidden) {
                stopPolling();
                return;
            }
            if (!hasInFlight) {
                return;
            }
            // Back on the tab: one immediate refresh, then let the interval
            // take over. A new visit supersedes any in-flight poll request,
            // so nothing overlaps.
            router.reload({
                only: ['jobs', 'pagination', 'disposition_counts'],
                showProgress: false,
                onBefore: () => !userVisitInFlight.current,
            });
            startPolling();
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        return () =>
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
    }, [hasInFlight, startPolling, stopPolling]);

    const selectedVisible = useMemo(
        () =>
            new Set(
                jobs.map((job) => job.id).filter((id) => selectedIds.has(id)),
            ),
        [jobs, selectedIds],
    );

    // Anti-resurrection: drop checked ids that are no longer visible
    // (a new page, or a search/filter navigation that removed their rows).
    useEffect(() => {
        setSelectedIds(
            (prev) =>
                pruneSelection(
                    prev,
                    jobs.map((job) => job.id),
                ) as Set<number>,
        );
    }, [jobs]);

    const selectAllRef = useRef<HTMLInputElement>(null);
    const allSelected = jobs.length > 0 && selectedVisible.size === jobs.length;
    const anySelected = selectedVisible.size > 0;
    const tooManyToSelectAll = jobs.length > DECISION_MAX_ITEMS;
    // Analysis and matching take up to 50 jobs; shortlisting and dismissing take more.
    const tooManyToProcess = selectedVisible.size > BATCH_MAX_ITEMS;

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
        setSelectedIds((prev) =>
            toggleSelection(prev, jobId, checked, DECISION_MAX_ITEMS),
        );
    };

    const toggleAll = () => {
        if (pendingOp !== null || tooManyToSelectAll) {
            return;
        }
        // Selection changed — a stale error about the prior selection is no
        // longer accurate, so clear it.
        batchForm.clearErrors();
        if (selectAllRef.current !== null) {
            selectAllRef.current.indeterminate = false;
        }
        setSelectedIds((prev) =>
            toggleAllSelection(
                prev,
                jobs.map((job) => job.id),
                DECISION_MAX_ITEMS,
            ),
        );
    };

    const clearSelection = () => {
        setSelectedIds(new Set<number>());
        // Selection changed — clear a stale error from the prior attempt.
        batchForm.clearErrors();
    };

    const post = (
        url: string,
        data: Record<string, unknown>,
        onSuccess?: () => void,
    ) => {
        setDecisionPending(true);
        router.post(url, data as Parameters<typeof router.post>[1], {
            showProgress: false,
            preserveScroll: true,
            preserveState: true,
            onSuccess,
            onFinish: () => setDecisionPending(false),
        });
    };

    const decideOne =
        (job: JobPostingSummary): DecideFn =>
        (to, options) => {
            setDismissing(null);
            post(decisionStore.url({ jobPosting: job.id }), {
                disposition: to,
                reason: options?.reason,
                note: options?.note,
            });
        };

    const decideSelected: DecideFn = (to, options) => {
        const ids = [...selectedVisible];
        if (ids.length < 1 || ids.length > DECISION_MAX_ITEMS) {
            return;
        }
        setDismissing(null);
        post(
            batchDecision.url(),
            {
                job_ids: ids,
                disposition: to,
                reason: options?.reason,
                note: options?.note,
            },
            () => setSelectedIds(new Set<number>()),
        );
    };

    const undoDecision = () => {
        if (decisionResult === undefined) {
            return;
        }
        post(decisionsUndo.url(), { decisions: decisionResult.decision_ids });
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
            ? 'One of the selected jobs could not be processed.'
            : undefined);
    const reasonLines =
        batchResult === undefined ? [] : batchReasonLines(batchResult);
    const filtersActive =
        filters.q !== '' ||
        filters.status !== 'all' ||
        filters.triage !== 'all' ||
        filters.resume !== 'all';
    const bulkDecisions = bulkDecisionsFor(filters.disposition);
    const viewTabs = DISPOSITION_TABS.map((tab) => ({
        value: tab.value,
        label: `${tab.label} ${disposition_counts[tab.value]}`,
    }));
    const emptyView = {
        inbox: 'Your Inbox is empty: every job has been shortlisted, dismissed or pursued.',
        shortlist:
            'Nothing is shortlisted yet. Shortlist jobs from the Inbox to keep them in view.',
        dismissed: 'No jobs have been dismissed.',
        all: 'No jobs captured yet.',
    }[filters.disposition];

    return (
        <AppShell>
            <Head title="Jobs" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-fg text-xl font-semibold tracking-tight sm:text-2xl">
                        Jobs
                    </h1>
                    <p
                        aria-live="polite"
                        className="text-fg-muted mt-1 text-sm"
                    >
                        {pagination.total === 0
                            ? filtersActive
                                ? 'No matches'
                                : 'Nothing captured yet'
                            : `Showing ${pagination.from}–${pagination.to} of ${pagination.total}${filtersActive ? ' matching' : ''}`}
                    </p>
                </div>
                <ButtonLink variant="primary" href={createJob.url()}>
                    Add Job
                </ButtonLink>
            </div>

            <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-3">
                <div className="relative w-full lg:w-72">
                    <TextInput
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
                        aria-label="Search jobs"
                        className="pr-8"
                    />
                    {q !== '' && (
                        <button
                            type="button"
                            onClick={clearSearch}
                            aria-label="Clear search"
                            className="rounded-control text-fg-subtle hover:text-fg absolute inset-y-0 right-0 flex w-8 items-center justify-center"
                        >
                            ×
                        </button>
                    )}
                </div>

                <FilterGroup label="Show">
                    <SegmentedControl
                        label="Decision view"
                        items={viewTabs}
                        value={filters.disposition}
                        onChange={showView}
                    />
                </FilterGroup>
                <FilterGroup label="Stage">
                    <SegmentedControl
                        label="Triage stage"
                        items={TRIAGE_STAGES}
                        value={filters.triage}
                        onChange={(triage) => applyFilters({ triage })}
                    />
                </FilterGroup>
                <FilterGroup label="Resume">
                    <SegmentedControl
                        label="Resume status"
                        items={RESUME_TABS}
                        value={filters.resume}
                        onChange={(resume) => applyFilters({ resume })}
                    />
                </FilterGroup>
                <FilterGroup label="Inspection">
                    <SegmentedControl
                        label="Inspection status"
                        items={STATUS_TABS}
                        value={filters.status}
                        onChange={(status) => applyFilters({ status })}
                    />
                </FilterGroup>
            </div>

            {decisionResult !== undefined && (
                <DecisionResultPanel
                    result={decisionResult}
                    busy={decisionPending}
                    onUndo={undoDecision}
                    onDismiss={() => setDecisionResult(undefined)}
                />
            )}

            {batchResult !== undefined && !batchResultDismissed && (
                <Alert
                    tone="neutral"
                    role="status"
                    className="mt-4 flex items-start justify-between gap-3"
                >
                    <div>
                        <p className="text-fg font-medium">
                            {batchResult.operation === 'analyze'
                                ? 'Analyze'
                                : 'Match'}{' '}
                            batch — {batchSummaryText(batchResult)}
                        </p>
                        {reasonLines.length > 0 && (
                            <p className="text-fg-subtle mt-1 text-xs">
                                {reasonLines.join(' · ')}
                            </p>
                        )}
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Dismiss batch result"
                        onClick={() => setBatchResultDismissed(true)}
                    >
                        ×
                    </Button>
                </Alert>
            )}

            {jobs.length === 0 ? (
                <Card className="mt-5 border-dashed p-10 text-center">
                    <p className="text-fg-muted text-sm">
                        {filtersActive ? describeFilters(filters) : emptyView}
                    </p>
                    {filtersActive ? (
                        <Button
                            className="mt-4"
                            onClick={() => {
                                qRef.current = '';
                                setQ('');
                                navigate({
                                    q: '',
                                    status: 'all',
                                    triage: 'all',
                                    resume: 'all',
                                });
                            }}
                        >
                            Clear search and filters
                        </Button>
                    ) : filters.disposition !== 'all' ? (
                        <Button
                            className="mt-4"
                            onClick={() => showView('all')}
                        >
                            Show all jobs
                        </Button>
                    ) : (
                        <ButtonLink
                            variant="primary"
                            className="mt-4"
                            href={createJob.url()}
                        >
                            Add your first job
                        </ButtonLink>
                    )}
                </Card>
            ) : (
                <Card
                    aria-busy={loading}
                    className={cn(
                        'mt-5 overflow-hidden transition-opacity',
                        loading && 'opacity-60',
                    )}
                >
                    <div className="border-line bg-sunken/40 flex min-h-12 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b px-4 py-2">
                        <label
                            className={cn(
                                'flex items-center gap-3 text-sm',
                                tooManyToSelectAll
                                    ? 'text-fg-subtle cursor-not-allowed'
                                    : 'text-fg-muted cursor-pointer',
                            )}
                        >
                            <Checkbox
                                ref={selectAllRef}
                                checked={allSelected}
                                disabled={
                                    pendingOp !== null || tooManyToSelectAll
                                }
                                onChange={toggleAll}
                                aria-label="Select all visible jobs"
                            />
                            <span aria-live="polite">
                                {anySelected
                                    ? `${selectedVisible.size} selected on this page`
                                    : 'Select all on this page'}
                            </span>
                        </label>

                        {anySelected ? (
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <div
                                    role="group"
                                    aria-label="Decide on selected jobs"
                                    className="flex flex-wrap items-center gap-1.5"
                                >
                                    {bulkDecisions.includes('shortlisted') && (
                                        <Button
                                            size="sm"
                                            disabled={decisionPending}
                                            aria-label="Shortlist selected"
                                            onClick={() =>
                                                decideSelected('shortlisted')
                                            }
                                        >
                                            Shortlist
                                        </Button>
                                    )}
                                    {bulkDecisions.includes('dismissed') && (
                                        <Button
                                            size="sm"
                                            disabled={decisionPending}
                                            aria-label="Dismiss selected"
                                            onClick={() =>
                                                setDismissing('selection')
                                            }
                                        >
                                            Dismiss
                                        </Button>
                                    )}
                                    {bulkDecisions.includes('new') && (
                                        <Button
                                            size="sm"
                                            disabled={decisionPending}
                                            aria-label="Restore selected to Inbox"
                                            onClick={() =>
                                                decideSelected('new')
                                            }
                                        >
                                            Restore to Inbox
                                        </Button>
                                    )}
                                </div>
                                <div
                                    role="group"
                                    aria-label="Process selected jobs"
                                    title={
                                        tooManyToProcess
                                            ? `Analyze and match handle up to ${BATCH_MAX_ITEMS} jobs at a time`
                                            : undefined
                                    }
                                    className="flex flex-wrap items-center gap-1.5"
                                >
                                    <Button
                                        variant="primary"
                                        size="sm"
                                        disabled={
                                            pendingOp !== null ||
                                            tooManyToProcess
                                        }
                                        onClick={() => submitBatch('analyze')}
                                    >
                                        {pendingOp === 'analyze'
                                            ? 'Analyzing…'
                                            : 'Analyze selected'}
                                    </Button>
                                    <Button
                                        size="sm"
                                        disabled={
                                            pendingOp !== null ||
                                            tooManyToProcess
                                        }
                                        onClick={() => submitBatch('match')}
                                    >
                                        {pendingOp === 'match'
                                            ? 'Matching…'
                                            : 'Match selected'}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={pendingOp !== null}
                                        onClick={clearSelection}
                                    >
                                        Clear
                                    </Button>
                                </div>
                            </div>
                        ) : tooManyToSelectAll ? (
                            <p className="text-fg-subtle text-xs">
                                <span className="sm:hidden">
                                    Limit: {DECISION_MAX_ITEMS} per action.
                                </span>
                                <span className="hidden sm:inline">
                                    Select all is limited to{' '}
                                    {DECISION_MAX_ITEMS} rows. Pick rows
                                    individually, or show fewer per page.
                                </span>
                            </p>
                        ) : (
                            <p className="text-fg-subtle text-xs">
                                <span className="sm:hidden">
                                    Analyze/match: up to {BATCH_MAX_ITEMS}
                                </span>
                                <span className="hidden sm:inline">
                                    Select jobs to shortlist or dismiss them, or
                                    to analyze or match up to {BATCH_MAX_ITEMS}{' '}
                                    at a time.
                                </span>
                            </p>
                        )}
                    </div>

                    {dismissing === 'selection' && anySelected && (
                        <div className="border-line border-b px-4 py-3">
                            <DismissStrip
                                heading={`Dismiss ${selectedVisible.size} selected because…`}
                                disabled={decisionPending}
                                onPick={(reason, note) =>
                                    decideSelected('dismissed', {
                                        reason,
                                        note,
                                    })
                                }
                                onCancel={() => setDismissing(null)}
                            />
                        </div>
                    )}

                    {batchError !== undefined && (
                        <p
                            role="alert"
                            className="border-line text-bad border-b px-4 py-2 text-sm"
                        >
                            {batchError}
                        </p>
                    )}

                    <ul className="divide-line divide-y">
                        {jobs.map((job) => (
                            <JobRow
                                key={job.id}
                                job={job}
                                selected={selectedVisible.has(job.id)}
                                checkboxDisabled={
                                    pendingOp !== null ||
                                    (!selectedVisible.has(job.id) &&
                                        selectedVisible.size >=
                                            DECISION_MAX_ITEMS)
                                }
                                decisionsDisabled={decisionPending}
                                dismissing={dismissing === job.id}
                                onToggle={(checked) =>
                                    toggleJob(job.id, checked)
                                }
                                onDecide={decideOne(job)}
                                onStartDismiss={() => setDismissing(job.id)}
                                onCancelDismiss={() => setDismissing(null)}
                            />
                        ))}
                    </ul>
                </Card>
            )}

            {pagination.total > 0 && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <label className="text-fg-muted flex items-center gap-2 text-sm">
                        Rows per page
                        <Select
                            value={pagination.per_page}
                            onChange={(e) =>
                                navigate({ per_page: Number(e.target.value) })
                            }
                            aria-label="Rows per page"
                        >
                            {pagination.per_page_options.map((n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <Pagination
                        page={pagination.page}
                        lastPage={pagination.last_page}
                        onPage={goToPage}
                    />
                </div>
            )}
        </AppShell>
    );
}
