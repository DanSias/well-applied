import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { InspectionStateBadge } from '@/components/badges';
import {
    InspectionDrawer,
    workspaceDestination,
} from '@/components/inspection-drawer';
import { TailoredResumePanel } from '@/components/tailored-resume-panel';
import { Badge } from '@/components/ui/badge';
import { Button, ButtonAnchor, ButtonLink } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { SubmissionDrawer } from '@/components/submission-drawer';
import { Card } from '@/components/ui/surface';
import { useJobDecisions } from '@/hooks/use-job-decisions';
import AppShell from '@/layouts/app-shell';
import { formatCompensation } from '@/lib/job-display';
import {
    resumeStateLabel,
    resumeStateTone,
    workspaceNext,
    type AnalysisPointer,
    type OpportunityDetail,
} from '@/lib/opportunity';
import { show as jobShow } from '@/routes/jobs';
import {
    browserTimezone,
    formatSubmittedDate,
    phaseTone,
    type ApplicationSection,
    type SubmissionRecord,
} from '@/lib/submission';
import { show as analysisShow } from '@/routes/jobs/analyses';
import { show as matchShow } from '@/routes/jobs/analyses/matches';
import { show as workspaceShow } from '@/routes/jobs/profile-resumes';
import { index as opportunitiesIndex } from '@/routes/opportunities';
import type { InspectionJob } from '@/types/application-inspection';
import type {
    TailoredResumeStatus,
    TailoringProfile,
} from '@/types/tailored-resume';

type Props = {
    opportunity: OpportunityDetail;
    tailored_resume: TailoredResumeStatus;
    resume_profiles: TailoringProfile[];
    analysis: AnalysisPointer;
    inspection_job: InspectionJob;
    application: ApplicationSection;
};

function Section({
    id,
    title,
    aside,
    children,
}: {
    id: string;
    title: string;
    aside?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-title`}
            className="scroll-mt-4 px-4 py-4 sm:px-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2
                    id={`${id}-title`}
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    {title}
                </h2>
                {aside}
            </div>
            <div className="mt-2">{children}</div>
        </section>
    );
}

const day = (iso: string) => new Date(iso).toLocaleDateString();

/** One recorded application: what the person told us, and where it stands now. */
function SubmissionItem({
    record,
    timezone,
}: {
    record: SubmissionRecord;
    timezone: string;
}) {
    const resume = record.resume
        ? `${record.resume.label ?? 'A Well Applied resume'}${record.pdf_kept ? ', copy of the PDF kept' : ', no copy of the PDF kept'}`
        : record.pdf_kept
          ? 'A PDF you uploaded, kept privately'
          : 'No resume recorded';

    return (
        <li className="space-y-1 py-2.5 text-sm first:pt-0">
            <p className="text-fg font-medium">
                Applied {formatSubmittedDate(record.submitted_at, timezone)}
                <span className="text-fg-subtle ml-2 text-xs font-normal">
                    {record.phase_label}
                </span>
            </p>
            <dl className="text-fg-muted space-y-0.5 text-xs">
                <div className="flex flex-wrap gap-x-1.5">
                    <dt className="text-fg-subtle">Recorded by you:</dt>
                    <dd>manual, not submitted by Well Applied</dd>
                </div>
                <div className="flex flex-wrap gap-x-1.5">
                    <dt className="text-fg-subtle">Resume:</dt>
                    <dd className="break-words">{resume}</dd>
                </div>
                {record.application_url && (
                    <div className="flex flex-wrap gap-x-1.5">
                        <dt className="text-fg-subtle">Link:</dt>
                        <dd className="min-w-0 break-all">
                            <a
                                href={record.application_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-accent-text underline underline-offset-2"
                            >
                                {record.application_url}
                            </a>
                        </dd>
                    </div>
                )}
                {record.notes && (
                    <div className="flex flex-wrap gap-x-1.5">
                        <dt className="text-fg-subtle">Notes:</dt>
                        <dd className="min-w-0 break-words whitespace-pre-wrap">
                            {record.notes}
                        </dd>
                    </div>
                )}
            </dl>
        </li>
    );
}

/**
 * One pursued job as a workspace. It composes what already exists and owns no workflow of its own: the resume panel,
 * the inspection drawer and the decision hook are the same ones used elsewhere, and the one next step is the list's
 * own choice. Anything substantial (editing a resume, reading a full analysis or match) stays on its existing page.
 */
export default function OpportunityShow({
    opportunity: op,
    tailored_resume,
    resume_profiles,
    analysis,
    inspection_job,
    application,
}: Props) {
    const decisions = useJobDecisions();
    const [open, setOpen] = useState<
        'description' | 'inspection' | 'application' | null
    >(null);
    const [recordingAnother, setRecordingAnother] = useState(false);
    const applicationTrigger = useRef<HTMLButtonElement>(null);
    const timezone = browserTimezone();
    const activeRecord = application.records.find((r) => r.active) ?? null;
    const descriptionTrigger = useRef<HTMLButtonElement>(null);
    const inspectionTrigger = useRef<HTMLButtonElement>(null);
    const next = workspaceNext(op, tailored_resume);
    const meta = [op.location, op.remote_status, formatCompensation(op)].filter(
        (item): item is string => Boolean(item),
    );
    const label = `${op.title} at ${op.company}`;
    const inspected = op.inspection.application_id !== null;
    const showInspectionBadge =
        op.inspection.state !== 'not_inspected' ||
        op.inspection.latest_attempt_failed;

    const close = () => {
        const trigger =
            open === 'description'
                ? descriptionTrigger
                : open === 'application'
                  ? applicationTrigger
                  : inspectionTrigger;

        setOpen(null);
        window.setTimeout(() => trigger.current?.focus(), 60);
    };

    return (
        <AppShell>
            <Head title={`${label} · Opportunity`} />

            <Link
                href={opportunitiesIndex.url()}
                className="text-fg-muted hover:text-fg focus-visible:ring-focus rounded-sm text-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
            >
                ← Opportunities
            </Link>

            <header className="mt-3">
                <h1 className="text-fg text-xl leading-snug font-semibold tracking-tight break-words sm:text-2xl">
                    {op.title}
                </h1>
                <p className="text-fg-muted mt-0.5 text-sm">{op.company}</p>
                <div className="text-fg-subtle mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                    {meta.length > 0 && (
                        <span className="text-fg-muted break-words">
                            {meta.join(' · ')}
                        </span>
                    )}
                    <Badge tone="ok">
                        Pursuing
                        {op.pursued_at ? ` since ${day(op.pursued_at)}` : ''}
                    </Badge>
                    {op.posting_status === 'closed' && (
                        <Badge tone="warn">Posting closed</Badge>
                    )}
                    {op.source_url !== null && (
                        <ButtonAnchor
                            variant="ghost"
                            size="sm"
                            href={op.source_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="-my-1 -ml-1"
                        >
                            Original posting ↗
                        </ButtonAnchor>
                    )}
                </div>
            </header>

            <Card className="mt-4 p-4 sm:p-5">
                <p className="text-fg-subtle text-xs font-medium">Next step</p>
                <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-2">
                    {next.kind === 'link' ? (
                        <ButtonLink
                            variant="primary"
                            href={workspaceShow.url({
                                jobPosting: op.id,
                                resumeVariant: next.variantId,
                            })}
                        >
                            {next.label}
                        </ButtonLink>
                    ) : (
                        // The step happens in the Resume section below, whose own button is the primary action: stating
                        // it here in words, rather than as a second button, keeps one clear thing to press.
                        <p className="text-fg text-sm font-semibold">
                            {next.label}
                        </p>
                    )}
                    <p className="text-fg-muted min-w-0 flex-1 text-sm">
                        {next.hint}
                    </p>
                </div>
            </Card>

            <Card className="divide-line mt-4 divide-y overflow-hidden">
                <Section
                    id="resume"
                    title="Resume"
                    aside={
                        <Badge tone={resumeStateTone(op.resume.state)}>
                            {resumeStateLabel(op.resume.state)}
                            {op.resume.versions > 1
                                ? ` · ${op.resume.versions} versions`
                                : ''}
                        </Badge>
                    }
                >
                    {op.resume.state === 'none' && (
                        <p className="text-fg-muted mb-2 text-sm">
                            Not started.
                        </p>
                    )}
                    {op.description_completeness !== 'complete' && (
                        <p
                            role="note"
                            className="text-warn mb-2 text-sm leading-relaxed"
                        >
                            {op.description_completeness === 'preview'
                                ? 'This posting only contains a preview of the job description. Some requirements or responsibilities may be missing.'
                                : 'Well Applied cannot confirm that this is the complete job description.'}
                        </p>
                    )}
                    <TailoredResumePanel
                        jobId={op.id}
                        initial={tailored_resume}
                        profiles={resume_profiles}
                        returnTo="opportunity"
                    />
                    {op.resume.current_variant_id !== null && (
                        <div className="mt-3">
                            <ButtonLink
                                size="sm"
                                href={workspaceShow.url({
                                    jobPosting: op.id,
                                    resumeVariant:
                                        op.resume.final_variant_id ??
                                        op.resume.current_variant_id,
                                })}
                            >
                                Open resume workspace
                            </ButtonLink>
                        </div>
                    )}
                </Section>

                <Section
                    id="application"
                    title="Application"
                    aside={
                        activeRecord !== null ? (
                            <Badge tone={phaseTone(activeRecord.phase)}>
                                {activeRecord.phase_label}
                            </Badge>
                        ) : application.records.length > 0 ? (
                            <Badge
                                tone={phaseTone(application.records[0].phase)}
                            >
                                {application.records[0].phase_label}
                            </Badge>
                        ) : (
                            <Badge>Not recorded</Badge>
                        )
                    }
                >
                    {application.records.length === 0 ? (
                        <p className="text-fg-muted text-sm">
                            Tracks an application you submitted yourself,
                            outside Well Applied. Well Applied never submits
                            anything for you.
                        </p>
                    ) : (
                        <ul className="divide-line divide-y">
                            {application.records.map((record) => (
                                <SubmissionItem
                                    key={record.id}
                                    record={record}
                                    timezone={timezone}
                                />
                            ))}
                        </ul>
                    )}
                    <Button
                        ref={applicationTrigger}
                        size="sm"
                        variant={
                            application.records.length === 0
                                ? 'primary'
                                : 'secondary'
                        }
                        className="mt-3"
                        aria-haspopup="dialog"
                        onClick={() => {
                            setRecordingAnother(activeRecord !== null);
                            setOpen('application');
                        }}
                    >
                        {application.records.length === 0
                            ? 'Record that I applied'
                            : 'Record another application'}
                    </Button>
                </Section>

                <Section
                    id="inspection"
                    title="Application inspection"
                    aside={
                        showInspectionBadge ? (
                            <InspectionStateBadge summary={op.inspection} />
                        ) : (
                            <Badge>Not started</Badge>
                        )
                    }
                >
                    <p className="text-fg-muted text-sm">
                        Reads the application form's fields and questions. It
                        never fills in or submits anything.
                    </p>
                    {(inspected || op.source_url !== null) && (
                        <Button
                            ref={inspectionTrigger}
                            size="sm"
                            className="mt-2"
                            aria-haspopup="dialog"
                            onClick={() => setOpen('inspection')}
                        >
                            {inspected
                                ? 'View inspection'
                                : 'Inspect application form'}
                        </Button>
                    )}
                    {!inspected && op.source_url === null && (
                        <p className="text-fg-subtle mt-1 text-xs">
                            This job has no application link to inspect.
                        </p>
                    )}
                </Section>

                <Section id="analysis" title="Job analysis">
                    {analysis === null ? (
                        <div className="text-fg-muted text-sm">
                            <p>
                                Not started. Generating the tailored resume
                                analyzes the job as its first step.
                            </p>
                            <ButtonLink
                                size="sm"
                                variant="ghost"
                                className="mt-1 -ml-2.5"
                                href={jobShow.url({ jobPosting: op.id })}
                            >
                                Analyze this job on its own
                            </ButtonLink>
                        </div>
                    ) : (
                        <div className="space-y-2 text-sm">
                            <p className="text-fg-muted leading-relaxed">
                                {analysis.role_summary}
                            </p>
                            <p className="text-fg-subtle text-xs">
                                Analyzed {day(analysis.generated_at)} ·{' '}
                                {analysis.findings_count}{' '}
                                {analysis.findings_count === 1
                                    ? 'finding'
                                    : 'findings'}
                                {analysis.match !== null &&
                                    ` · matched ${day(analysis.match.generated_at)}`}
                            </p>
                            <div className="flex flex-wrap gap-1">
                                <ButtonLink
                                    size="sm"
                                    variant="ghost"
                                    href={analysisShow.url({
                                        jobPosting: op.id,
                                        jobAnalysis: analysis.id,
                                    })}
                                >
                                    Full analysis
                                </ButtonLink>
                                {analysis.match !== null && (
                                    <ButtonLink
                                        size="sm"
                                        variant="ghost"
                                        href={matchShow.url({
                                            jobPosting: op.id,
                                            jobAnalysis: analysis.id,
                                            jobMatch: analysis.match.id,
                                        })}
                                    >
                                        Evidence match
                                    </ButtonLink>
                                )}
                            </div>
                        </div>
                    )}
                </Section>

                <Section id="description" title="Job description">
                    <Button
                        ref={descriptionTrigger}
                        size="sm"
                        aria-haspopup="dialog"
                        onClick={() => setOpen('description')}
                    >
                        Read the full description
                    </Button>
                </Section>
            </Card>

            <div className="border-line mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-4">
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={decisions.pending}
                    aria-label={`Stop pursuing ${label}: return it to the Shortlist`}
                    onClick={() =>
                        decisions.decide(op.id, 'shortlisted', {
                            returnTo: 'job',
                        })
                    }
                >
                    Back to Shortlist
                </Button>
                <p className="text-fg-subtle min-w-0 flex-1 text-xs">
                    Your resume, analysis and inspection are kept, and you can
                    undo this from the notice that follows.
                </p>
            </div>

            {open === 'description' && (
                <Drawer
                    title={op.title}
                    subtitle={op.company}
                    closeLabel="Close job description"
                    onClose={close}
                >
                    <h3 className="text-fg text-sm font-semibold tracking-tight">
                        Job description
                    </h3>
                    <p className="text-fg pt-2 text-sm leading-relaxed break-words whitespace-pre-wrap">
                        {op.description}
                    </p>
                </Drawer>
            )}
            {open === 'application' && (
                <SubmissionDrawer
                    job={op}
                    section={application.form}
                    another={recordingAnother}
                    onClose={close}
                />
            )}
            {open === 'inspection' && (
                <InspectionDrawer
                    state="ready"
                    job={inspection_job}
                    fallback={null}
                    destination={workspaceDestination}
                    onClose={close}
                />
            )}
        </AppShell>
    );
}
