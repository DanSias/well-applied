import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { TriageStageBadge, InspectionStateBadge } from '@/components/badges';
import { sourceLinkLabel } from '@/components/provider-attribution';
import { DispositionBadge } from '@/components/job-decisions';
import { Badge } from '@/components/ui/badge';
import {
    InspectionDrawer,
    listDestination,
    type InspectionDrawerState,
} from '@/components/inspection-drawer';
import { OverflowMenu } from '@/components/overflow-menu';
import { Button, ButtonLink } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented';
import { Card } from '@/components/ui/surface';
import { useJobDecisions } from '@/hooks/use-job-decisions';
import { useStartInspection } from '@/hooks/use-start-inspection';
import AppShell from '@/layouts/app-shell';
import { formatCompensation } from '@/lib/job-display';
import { submissionIndicator } from '@/lib/submission';
import { notify } from '@/lib/toast';
import {
    RESUME_FILTERS,
    buildOpportunityParams,
    existingWorkSummary,
    nextAction,
    resumeStateLabel,
    resumeStateTone,
    type ExistingWork,
    type Opportunity,
    type OpportunityResumeFilter,
} from '@/lib/opportunity';
import { index as jobsIndex, show as showJob } from '@/routes/jobs';
import { show as workspaceShow } from '@/routes/jobs/profile-resumes';
import {
    index as opportunitiesIndex,
    show as opportunityShow,
} from '@/routes/opportunities';
import type { InspectionJob } from '@/types/application-inspection';

type Props = {
    opportunities: Opportunity[];
    counts: Record<OpportunityResumeFilter, number>;
    review: ExistingWork[];
    filters: { resume: OpportunityResumeFilter; inspect: number | null };
    inspection_job: InspectionJob | null;
};

/**
 * Drawer bookkeeping that has to outlive the page component: going Back or Forward makes Inertia restore the page from
 * history and remount it, which would reset anything kept in state or refs (same reason as the Jobs drawer).
 */
const drawerSession = {
    active: false,
    triggerId: null as number | null,
    /** Opened from this list in this session: closing is Back, which keeps the history tidy. */
    openedByPush: false,
};

function OpportunityRow({
    op,
    busy,
    onStop,
    onInspect,
}: {
    op: Opportunity;
    busy: boolean;
    onStop: () => void;
    onInspect: () => void;
}) {
    const next = nextAction(op);
    const href =
        next.to === 'resume' && next.variantId !== null
            ? workspaceShow.url({
                  jobPosting: op.id,
                  resumeVariant: next.variantId,
              })
            : `${opportunityShow.url(op.id)}#resume`;
    const meta = [op.location, op.remote_status, formatCompensation(op)].filter(
        (item): item is string => Boolean(item),
    );
    const showInspection =
        op.inspection.state !== 'not_inspected' ||
        op.inspection.latest_attempt_failed;
    const inspected = op.inspection.state !== 'not_inspected';
    const canInspect = inspected || op.source_url !== null;
    const label = `${op.title} at ${op.company}`;
    // The application the person recorded, never the browser inspection, which has its own badge.
    const applied = submissionIndicator(op.submission);

    return (
        <li className="grid grid-cols-1 items-start gap-x-3 gap-y-2 px-4 py-2.5 sm:grid-cols-[minmax(0,1fr)_auto]">
            <div className="min-w-0">
                <p className="text-sm leading-snug break-words">
                    <Link
                        href={opportunityShow.url(op.id)}
                        className="text-fg focus-visible:ring-focus rounded-sm font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none"
                    >
                        {op.title}
                    </Link>
                    <span className="text-fg-muted"> · {op.company}</span>
                </p>
                <div className="text-fg-subtle mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs leading-relaxed">
                    {meta.length > 0 && (
                        <span className="text-fg-muted break-words">
                            {meta.map((item, i) => (
                                <Fragment key={i}>
                                    {i > 0 && ' · '}
                                    {item}
                                </Fragment>
                            ))}
                        </span>
                    )}
                    <Badge tone={resumeStateTone(op.resume.state)}>
                        {resumeStateLabel(op.resume.state)}
                        {op.resume.versions > 1
                            ? ` · ${op.resume.versions} versions`
                            : ''}
                    </Badge>
                    {applied.tone !== null ? (
                        <Badge tone={applied.tone}>{applied.label}</Badge>
                    ) : (
                        <span className="text-fg-subtle">{applied.label}</span>
                    )}
                    <TriageStageBadge value={op.stage} />
                    {showInspection && (
                        <InspectionStateBadge summary={op.inspection} />
                    )}
                    {op.pending_questions && (
                        <Badge tone="warn">Questions waiting</Badge>
                    )}
                    {op.posting_status === 'closed' && (
                        <Badge tone="warn">Posting closed</Badge>
                    )}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-1 sm:justify-end">
                <ButtonLink size="sm" variant="primary" href={href}>
                    {next.label}
                </ButtonLink>
                {canInspect && (
                    <Button
                        variant="ghost"
                        size="sm"
                        data-inspect-trigger={op.id}
                        aria-haspopup="dialog"
                        aria-label={`${inspected ? 'View inspection' : 'Inspect application form'} for ${label}`}
                        onClick={onInspect}
                    >
                        {inspected ? 'View inspection' : 'Inspect form'}
                    </Button>
                )}
                <OverflowMenu
                    label={`More actions for ${label}`}
                    disabled={busy}
                    items={[
                        ...(op.source_url !== null
                            ? [
                                  {
                                      label: sourceLinkLabel(op.attribution),
                                      href: op.source_url,
                                      external: true,
                                  },
                              ]
                            : []),
                        { label: 'Back to Shortlist', onSelect: onStop },
                    ]}
                />
            </div>
        </li>
    );
}

/** A job with work already done on it that nobody has pursued: pursue it on purpose, or leave it be. */
function ReviewRow({
    work,
    busy,
    onDecide,
}: {
    work: ExistingWork;
    busy: boolean;
    onDecide: (to: 'pursued' | 'new') => void;
}) {
    const resumeId =
        work.final_resume_variant_id ?? work.current_resume_variant_id;

    return (
        <li className="px-4 py-3">
            <p className="text-sm leading-snug break-words">
                <Link
                    href={showJob.url(work.job_id)}
                    className="text-fg rounded-sm font-medium hover:underline"
                >
                    {work.title}
                </Link>
                <span className="text-fg-muted"> · {work.company}</span>
            </p>
            <p className="text-fg-subtle mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                <DispositionBadge
                    disposition={{
                        value: work.disposition,
                        reason: null,
                        reason_label: null,
                        decided_at: null,
                    }}
                />
                <span>{existingWorkSummary(work)}</span>
            </p>
            <div className="mt-2 -ml-2.5 flex flex-wrap items-center gap-x-1 gap-y-1">
                {work.disposition === 'dismissed' ? (
                    <Button
                        size="sm"
                        className="ml-2.5"
                        disabled={busy}
                        aria-label={`Restore ${work.title} to Inbox so it can be pursued`}
                        onClick={() => onDecide('new')}
                    >
                        Restore to Inbox
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        className="ml-2.5"
                        disabled={busy}
                        aria-label={`Pursue ${work.title} at ${work.company}`}
                        onClick={() => onDecide('pursued')}
                    >
                        Pursue
                    </Button>
                )}
                {resumeId !== null && (
                    <ButtonLink
                        variant="ghost"
                        size="sm"
                        href={workspaceShow.url({
                            jobPosting: work.job_id,
                            resumeVariant: resumeId,
                        })}
                    >
                        Open resume
                    </ButtonLink>
                )}
                <ButtonLink
                    variant="ghost"
                    size="sm"
                    href={showJob.url(work.job_id)}
                >
                    Open job
                </ButtonLink>
            </div>
        </li>
    );
}

export default function OpportunitiesIndex({
    opportunities,
    counts,
    review,
    filters,
    inspection_job,
}: Props) {
    const decisions = useJobDecisions();
    // One starter for the row buttons and the drawer: a single gate, so one click can never start two runs.
    const starter = useStartInspection(listDestination(filters.resume));

    // The inspection drawer. Its job is in the URL (`?inspect=ID`): opening adds a history entry (Back closes it,
    // Forward reopens it) and a reload or shared link restores it. Only the drawer's own props are fetched when it
    // opens, so the list, its filter and its scroll position are left exactly as they were.
    const [pendingId, setPendingId] = useState<number | null>(null);
    const openRequest = useRef(0);
    const openId = pendingId ?? filters.inspect;
    const selected =
        inspection_job !== null && inspection_job.id === openId
            ? inspection_job
            : null;
    const drawerState: InspectionDrawerState =
        selected !== null
            ? 'ready'
            : pendingId !== null
              ? 'loading'
              : 'unavailable';
    const listParams = (inspect: number | null) => ({
        ...buildOpportunityParams(filters.resume),
        ...(inspect !== null ? { inspect } : {}),
    });

    /**
     * Opens the drawer for one job and starts nothing. A job with no inspection gets the setup (discover from the listing, or
     * supply the application URL); each choice there is one explicit confirmation made inside the drawer.
     */
    const openInspection = (id: number) => {
        const request = ++openRequest.current;
        drawerSession.triggerId = id;
        drawerSession.openedByPush = true;
        setPendingId(id);
        router.get(opportunitiesIndex.url(), listParams(id), {
            only: ['filters', 'inspection_job'],
            preserveState: true,
            preserveScroll: true,
            replace: false,
            onNetworkError: () => {
                notify.error("Couldn't load that inspection", {
                    description: 'Check your connection and try again.',
                });
            },
            onFinish: () => {
                if (openRequest.current === request) {
                    setPendingId(null);
                }
            },
        });
    };

    const closeInspection = () => {
        if (pendingId !== null) {
            openRequest.current++;
            drawerSession.openedByPush = false;
            router.cancelAll();
            setPendingId(null);

            return;
        }

        if (drawerSession.openedByPush) {
            drawerSession.openedByPush = false;
            window.history.back();

            return;
        }

        // Opened by a direct link or a reload: no earlier entry to return to, so replace the URL.
        router.get(opportunitiesIndex.url(), listParams(null), {
            only: ['filters', 'inspection_job', 'opportunities'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // After it closes, focus goes back to the button that opened it.
    useEffect(() => {
        if (openId !== null) {
            drawerSession.active = true;

            // Opened by a direct link or reload: nothing was clicked, so the job in the URL is the one to return to.
            if (!drawerSession.openedByPush) {
                drawerSession.triggerId = openId;
            }

            return;
        }

        if (!drawerSession.active) {
            return;
        }

        drawerSession.active = false;
        drawerSession.openedByPush = false;

        const id = drawerSession.triggerId;

        window.setTimeout(() => {
            document
                .querySelector<HTMLElement>(`[data-inspect-trigger="${id}"]`)
                ?.focus();
        }, 60);
    }, [openId]);

    const tabs = RESUME_FILTERS.map((tab) => ({
        value: tab.value,
        label: tab.label,
        count: counts[tab.value],
    }));

    return (
        <AppShell>
            <Head title="Opportunities" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-fg text-xl font-semibold tracking-tight sm:text-2xl">
                        Opportunities
                    </h1>
                    <p className="text-fg-muted mt-1 text-sm">
                        {counts.all === 0
                            ? 'Jobs you have decided to pursue'
                            : `${counts.all} ${counts.all === 1 ? 'job' : 'jobs'} you are pursuing`}
                    </p>
                </div>
                <ButtonLink href={jobsIndex.url()}>Browse jobs</ButtonLink>
            </div>

            {counts.all > 0 && (
                <div className="mt-5 flex max-w-full min-w-0 items-center gap-2">
                    <span className="text-fg-subtle text-xs font-medium">
                        Resume
                    </span>
                    <SegmentedControl
                        label="Resume status"
                        items={tabs}
                        value={filters.resume}
                        onChange={(resume) =>
                            router.get(
                                opportunitiesIndex.url(),
                                buildOpportunityParams(resume),
                                { preserveState: true, replace: false },
                            )
                        }
                    />
                </div>
            )}

            {opportunities.length === 0 ? (
                <Card className="mt-5 border-dashed p-10 text-center">
                    <p className="text-fg-muted mx-auto max-w-md text-sm leading-relaxed">
                        {counts.all === 0
                            ? 'Nothing is being pursued yet. Pursue a job from your Inbox or Shortlist when you have decided to go after it.'
                            : 'No opportunities match this resume filter.'}
                    </p>
                    {counts.all === 0 ? (
                        <ButtonLink
                            variant="primary"
                            className="mt-4"
                            href={jobsIndex.url()}
                        >
                            Go to Jobs
                        </ButtonLink>
                    ) : (
                        <ButtonLink
                            className="mt-4"
                            href={opportunitiesIndex.url()}
                        >
                            Show all opportunities
                        </ButtonLink>
                    )}
                </Card>
            ) : (
                <Card className="mt-3 overflow-hidden">
                    <ul className="divide-line divide-y">
                        {opportunities.map((op) => (
                            <OpportunityRow
                                key={op.id}
                                op={op}
                                busy={decisions.pending}
                                onStop={() =>
                                    decisions.decide(op.id, 'shortlisted')
                                }
                                onInspect={() => openInspection(op.id)}
                            />
                        ))}
                    </ul>
                </Card>
            )}
            {review.length > 0 && (
                <Card className="mt-5 overflow-hidden">
                    <div className="border-line border-b px-4 py-3">
                        <h2 className="text-fg text-sm font-semibold tracking-tight">
                            Existing work to review ({review.length})
                        </h2>
                        <p className="text-fg-muted mt-0.5 text-sm">
                            These jobs already have resume or inspection work
                            but are not pursued. Nothing changes until you
                            choose; an inspection draft is not a submitted
                            application.
                        </p>
                    </div>
                    <ul className="divide-line divide-y">
                        {review.map((work) => (
                            <ReviewRow
                                key={work.job_id}
                                work={work}
                                busy={decisions.pending}
                                onDecide={(to) =>
                                    decisions.decide(work.job_id, to)
                                }
                            />
                        ))}
                    </ul>
                </Card>
            )}
            {openId !== null && (
                <InspectionDrawer
                    state={drawerState}
                    job={selected}
                    fallback={
                        opportunities.find((op) => op.id === openId) ?? null
                    }
                    destination={listDestination(filters.resume)}
                    starter={starter}
                    onClose={closeInspection}
                />
            )}
        </AppShell>
    );
}
