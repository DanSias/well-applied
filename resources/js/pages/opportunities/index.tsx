import { Head, Link, router, useForm } from '@inertiajs/react';
import { Fragment } from 'react';
import { TriageStageBadge, InspectionStateBadge } from '@/components/badges';
import { DispositionBadge } from '@/components/job-decisions';
import { Badge } from '@/components/ui/badge';
import { Button, ButtonAnchor, ButtonLink } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented';
import { Card } from '@/components/ui/surface';
import { useJobDecisions } from '@/hooks/use-job-decisions';
import AppShell from '@/layouts/app-shell';
import { formatCompensation } from '@/lib/job-display';
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
import { show as applicationsShow } from '@/routes/applications';
import { index as jobsIndex, show as showJob } from '@/routes/jobs';
import { store as inspectStore } from '@/routes/jobs/applications';
import { show as workspaceShow } from '@/routes/jobs/profile-resumes';
import { index as opportunitiesIndex } from '@/routes/opportunities';

type Props = {
    opportunities: Opportunity[];
    counts: Record<OpportunityResumeFilter, number>;
    review: ExistingWork[];
    filters: { resume: OpportunityResumeFilter };
};

/** Quiet "inspect the application form" action: starts the existing read-only inspection, or opens its draft. */
function InspectAction({ op }: { op: Opportunity }) {
    const form = useForm({});

    if (op.inspection.application_id !== null) {
        return (
            <ButtonLink
                variant="ghost"
                size="sm"
                href={applicationsShow.url({
                    application: op.inspection.application_id,
                })}
            >
                View inspection
            </ButtonLink>
        );
    }

    if (op.source_url === null) {
        return null;
    }

    return (
        <Button
            variant="ghost"
            size="sm"
            disabled={form.processing}
            onClick={() =>
                form.post(inspectStore.url({ jobPosting: op.id }), {
                    showProgress: false,
                })
            }
        >
            Inspect application form
        </Button>
    );
}

function OpportunityRow({
    op,
    busy,
    onStop,
}: {
    op: Opportunity;
    busy: boolean;
    onStop: () => void;
}) {
    const next = nextAction(op);
    const href =
        next.to === 'resume' && next.variantId !== null
            ? workspaceShow.url({
                  jobPosting: op.id,
                  resumeVariant: next.variantId,
              })
            : showJob.url(op.id);
    const meta = [op.location, op.remote_status, formatCompensation(op)].filter(
        (item): item is string => Boolean(item),
    );
    const showInspection =
        op.inspection.state !== 'not_inspected' ||
        op.inspection.latest_attempt_failed;

    return (
        <li className="px-4 py-3">
            <p className="text-sm leading-snug break-words">
                <Link
                    href={showJob.url(op.id)}
                    className="text-fg rounded-sm font-medium hover:underline"
                >
                    {op.title}
                </Link>
                <span className="text-fg-muted"> · {op.company}</span>
            </p>
            {meta.length > 0 && (
                <p className="text-fg-subtle mt-0.5 text-xs leading-relaxed break-words">
                    {meta.map((item, i) => (
                        <Fragment key={i}>
                            {i > 0 && ' · '}
                            {item}
                        </Fragment>
                    ))}
                </p>
            )}
            <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                <Badge tone={resumeStateTone(op.resume.state)}>
                    {resumeStateLabel(op.resume.state)}
                    {op.resume.versions > 1
                        ? ` · ${op.resume.versions} versions`
                        : ''}
                </Badge>
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
            <div className="mt-2 -ml-2.5 flex flex-wrap items-center gap-x-1 gap-y-1">
                <ButtonLink
                    size="sm"
                    variant="primary"
                    href={href}
                    className="mr-1 ml-2.5"
                >
                    {next.label}
                </ButtonLink>
                <InspectAction op={op} />
                {op.source_url !== null && (
                    <ButtonAnchor
                        variant="ghost"
                        size="sm"
                        href={op.source_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Original posting ↗
                    </ButtonAnchor>
                )}
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    aria-label={`Stop pursuing ${op.title} at ${op.company}: return it to the Shortlist`}
                    onClick={onStop}
                >
                    Back to Shortlist
                </Button>
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
}: Props) {
    const decisions = useJobDecisions();
    const tabs = RESUME_FILTERS.map((tab) => ({
        value: tab.value,
        label: `${tab.label} ${counts[tab.value]}`,
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
        </AppShell>
    );
}
