import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { InspectionStateBadge } from '@/components/badges';
import {
    StepBadge,
    WorkflowStep,
    stepStatusFromTone,
    type StepStatus,
} from '@/components/opportunity/workflow-step';
import {
    InspectionDrawer,
    workspaceDestination,
} from '@/components/inspection-drawer';
import { PreparationSection } from '@/components/preparation/preparation-section';
import {
    TailoredResumePanel,
    type ResumeControl,
} from '@/components/tailored-resume-panel';
import { Badge, type Tone } from '@/components/ui/badge';
import {
    Button,
    ButtonAnchor,
    ButtonLink,
    buttonClass,
} from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { StopPursuingPopover } from '@/components/opportunity/stop-pursuing-popover';
import { SubmissionCorrectionDrawer } from '@/components/submission-correction-drawer';
import { SubmissionDrawer } from '@/components/submission-drawer';
import { SubmissionUpdateDrawer } from '@/components/submission-update-drawer';
import { Card } from '@/components/ui/surface';
import { JobDescription } from '@/components/job-description';
import { useJobDecisions } from '@/hooks/use-job-decisions';
import AppShell from '@/layouts/app-shell';
import { formatCompensation } from '@/lib/job-display';
import { summaryBadge } from '@/lib/inspection-status';
import { resumeSteps } from '@/lib/resume-stepper';
import {
    headerAction,
    resumeSummary,
    type AnalysisPointer,
    type OpportunityDetail,
} from '@/lib/opportunity';
import { show as jobShow } from '@/routes/jobs';
import {
    formatCalendarDate,
    phaseTone,
    type ApplicationSection,
    type SubmissionRecord,
} from '@/lib/submission';
import {
    historyEntries,
    recordActions,
    splitRecords,
    statusNote,
} from '@/lib/submission-update';
import { show as analysisShow } from '@/routes/jobs/analyses';
import { show as matchShow } from '@/routes/jobs/analyses/matches';
import { show as workspaceShow } from '@/routes/jobs/profile-resumes';
import { index as opportunitiesIndex } from '@/routes/opportunities';
import type { InspectionJob } from '@/types/application-inspection';
import type { PreparationPanel } from '@/types/application-preparation';
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
    preparation: PreparationPanel;
};

const day = (iso: string) => new Date(iso).toLocaleDateString();

/**
 * One recorded application: what the person told us, where it stands now (derived from its updates), and its own compact
 * history. Everything here is the person's own record, not a verified employer message.
 */
function SubmissionItem({
    record,
    onUpdate,
    onCorrect,
}: {
    record: SubmissionRecord;
    onUpdate: () => void;
    onCorrect: () => void;
}) {
    const resume = record.resume
        ? `${record.resume.label ?? 'A Well Applied resume'}${record.pdf_kept ? ', copy of the PDF kept' : ', no copy of the PDF kept'}`
        : record.pdf_kept
          ? 'A PDF you uploaded, kept privately'
          : 'No resume recorded';
    const note = statusNote(record);
    const { canUpdate, canCorrect } = recordActions(record);
    const history = historyEntries(record);

    return (
        <li
            className="space-y-2 py-3 text-sm first:pt-0"
            data-record-id={record.id}
        >
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <p className="text-fg font-medium">
                    Applied {formatCalendarDate(record.submitted_on)}
                </p>
                <Badge
                    tone={record.in_error ? 'neutral' : phaseTone(record.phase)}
                >
                    {record.phase_label}
                </Badge>
            </div>
            {note && (
                <p
                    role="note"
                    className="text-fg-muted text-xs leading-relaxed"
                >
                    {note}
                </p>
            )}
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

            <div>
                <h3 className="text-fg-subtle text-xs font-medium">
                    History{' '}
                    <span className="font-normal">
                        (what you recorded, newest first)
                    </span>
                </h3>
                <ol className="border-line mt-1 space-y-1.5 border-l pl-3">
                    {history.map((entry) => (
                        <li key={entry.key} className="text-xs leading-relaxed">
                            <span className="text-fg font-medium">
                                {entry.label}
                            </span>
                            <span className="text-fg-subtle">
                                {' '}
                                · {entry.date}
                            </span>
                            {entry.note && (
                                <span className="text-fg-muted block break-words whitespace-pre-wrap">
                                    {entry.note}
                                </span>
                            )}
                            {entry.url && (
                                <a
                                    href={entry.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-accent-text block break-all underline underline-offset-2"
                                >
                                    {entry.url}
                                </a>
                            )}
                        </li>
                    ))}
                </ol>
            </div>

            {(canUpdate || canCorrect) && (
                <div className="flex flex-wrap items-center gap-1 pt-0.5">
                    {canUpdate && (
                        <Button
                            size="sm"
                            variant="secondary"
                            aria-haspopup="dialog"
                            data-record-action={`${record.id}:update`}
                            onClick={onUpdate}
                        >
                            Add update
                        </Button>
                    )}
                    {canCorrect && (
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-haspopup="dialog"
                            data-record-action={`${record.id}:correct`}
                            onClick={onCorrect}
                        >
                            Mark as recorded in error
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

/** Why the description may not be the whole posting, in the words the page has always used. */
function completenessNote(c: OpportunityDetail['description_completeness']) {
    return c === 'preview'
        ? 'This posting only contains a preview of the job description. Some requirements or responsibilities may be missing.'
        : 'Well Applied cannot confirm that this is the complete job description.';
}

/**
 * One pursued job as a workspace. It composes what already exists and owns no workflow of its own: the resume panel,
 * the inspection drawer, the preparation step and the decision hook are the same ones used elsewhere, and the one
 * primary action in the header follows the list's own next step and the live generation status. Anything substantial
 * (editing a resume, reading a full analysis or match) stays on its existing page.
 *
 * Three purposes, three places: the header and the right column are the opportunity itself (what is it, what does it
 * require); the left card is the preparation workflow; the strip beneath is the person's own record of applying.
 */
export default function OpportunityShow({
    opportunity: op,
    tailored_resume,
    resume_profiles,
    analysis,
    inspection_job,
    application,
    preparation,
}: Props) {
    const decisions = useJobDecisions();
    // Mirrors what the resume panel below already tracks (polling, refresh, retry), so the summary cannot disagree with it.
    const [generation, setGeneration] =
        useState<TailoredResumeStatus>(tailored_resume);
    const [starting, setStarting] = useState(false);
    const resumeControl = useRef<ResumeControl>(null);
    const summary = resumeSummary(op.resume, generation);
    const action = headerAction(op, generation);
    const [open, setOpen] = useState<
        'description' | 'inspection' | 'application' | null
    >(null);
    const [recordingAnother, setRecordingAnother] = useState(false);
    // Whatever opened a drawer gets focus back when it closes (the description has two doors: the header and the preview).
    const opener = useRef<HTMLElement | null>(null);
    const { counted, inError } = splitRecords(application.records);
    const activeRecord = counted.find((r) => r.active) ?? null;
    const [editing, setEditing] = useState<{
        record: SubmissionRecord;
        mode: 'update' | 'correct';
    } | null>(null);
    const place = [op.location, op.remote_status].filter(
        (item): item is string => Boolean(item),
    );
    const pay = formatCompensation(op);
    const label = `${op.title} at ${op.company}`;
    const inspected = op.inspection.application_id !== null;
    const showInspectionBadge =
        op.inspection.state !== 'not_inspected' ||
        op.inspection.latest_attempt_failed;
    const inspectionTone = showInspectionBadge
        ? summaryBadge(op.inspection).tone
        : 'neutral';
    const hasDescription = op.description.trim() !== '';
    const opening =
        (which: 'description' | 'inspection' | 'application') =>
        (event: React.MouseEvent<HTMLElement>) => {
            opener.current = event.currentTarget;
            setOpen(which);
        };

    // The resume's own state decides how the step reads, so a run in progress never shows "Not started" and a draft is
    // not drawn as finished. Work running now spins; an unfinished resume that nothing is working on is a still dot.
    const resumeStatus: StepStatus =
        generation.state === 'running'
            ? 'active'
            : stepStatusFromTone(summary.tone, 'progress');

    // Analysis is the first stage of the same run, so while that run is going (or has stopped) the step says so.
    const analysisStage = resumeSteps(generation)[0]?.state;
    const analysisView: { status: StepStatus; label: string; tone: Tone } =
        analysis !== null || analysisStage === 'done'
            ? { status: 'done', label: 'Analyzed', tone: 'ok' }
            : analysisStage === 'active'
              ? { status: 'active', label: 'In progress', tone: 'accent' }
              : analysisStage === 'failed'
                ? { status: 'failed', label: 'Failed', tone: 'bad' }
                : analysisStage === 'interrupted'
                  ? { status: 'attention', label: 'Interrupted', tone: 'warn' }
                  : {
                        status: 'pending',
                        label: 'Not started',
                        tone: 'neutral',
                    };

    // After an update or correction closes, focus returns to the button that opened it (or, if it is gone because the
    // application ended, to the application's own section).
    const closeEditing = () => {
        const target = editing;

        setEditing(null);
        window.setTimeout(() => {
            const selector = target
                ? `[data-record-action="${target.record.id}:${target.mode}"]`
                : '';

            (
                (selector && document.querySelector<HTMLElement>(selector)) ||
                document.querySelector<HTMLElement>('#application h2')
            )?.focus();
        }, 80);
    };

    const close = () => {
        setOpen(null);
        window.setTimeout(() => opener.current?.focus(), 60);
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
                <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                    <div className="min-w-0 flex-1 basis-80">
                        <h1 className="text-fg text-xl leading-snug font-semibold tracking-tight break-words sm:text-2xl">
                            {op.title}
                        </h1>
                        <p className="text-fg-muted mt-0.5 text-sm">
                            {op.company}
                        </p>
                    </div>

                    <div className="flex flex-col items-start sm:items-end">
                        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                            {hasDescription && (
                                // The details card holds this on wide screens; here it sits below the workflow, so keep a door in the header.
                                <Button
                                    variant="secondary"
                                    className="lg:hidden"
                                    aria-haspopup="dialog"
                                    onClick={opening('description')}
                                >
                                    Job description
                                </Button>
                            )}
                            {action.kind === 'link' && (
                                <ButtonLink
                                    variant="primary"
                                    href={workspaceShow.url({
                                        jobPosting: op.id,
                                        resumeVariant: action.variantId,
                                    })}
                                >
                                    {action.label}
                                </ButtonLink>
                            )}
                            {action.kind === 'url' && (
                                <ButtonAnchor
                                    variant="primary"
                                    href={action.url}
                                >
                                    {action.label}
                                </ButtonAnchor>
                            )}
                            {action.kind === 'generate' && (
                                <Button
                                    variant="primary"
                                    disabled={starting}
                                    onClick={() =>
                                        resumeControl.current?.generate()
                                    }
                                >
                                    {action.label}
                                </Button>
                            )}
                            {action.kind === 'busy' && (
                                <Button
                                    variant="primary"
                                    disabled
                                    aria-busy="true"
                                >
                                    {action.label}
                                </Button>
                            )}
                            {action.kind === 'anchor' && (
                                // The step happens in the Resume step, so this takes you there rather than starting anything.
                                <a
                                    href="#resume"
                                    className={buttonClass('primary', 'md')}
                                    onClick={() =>
                                        window.setTimeout(
                                            () =>
                                                document
                                                    .getElementById(
                                                        'resume-title',
                                                    )
                                                    ?.focus(),
                                            0,
                                        )
                                    }
                                >
                                    {action.label}
                                </a>
                            )}
                        </div>
                    </div>
                </div>
                <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    <div className="text-fg-subtle flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                        <Badge tone="ok">
                            Pursuing
                            {op.pursued_at
                                ? ` since ${day(op.pursued_at)}`
                                : ''}
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
                    <StopPursuingPopover jobId={op.id} company={op.company} />
                </div>
            </header>

            <div className="mt-5 grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Card className="divide-line divide-y overflow-hidden">
                    <WorkflowStep
                        id="analysis"
                        title="Job analysis"
                        status={analysisView.status}
                        badge={
                            <StepBadge
                                tone={analysisView.tone}
                                label={analysisView.label}
                            />
                        }
                    >
                        {analysis === null ? (
                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <p className="text-fg-muted min-w-0 flex-1 basis-60 text-sm">
                                    {analysisStage === 'done'
                                        ? 'Finished as the first step of resume generation.'
                                        : 'Analyzes the job\u2019s requirements and identifies relevant evidence. Also runs as the first step of resume generation.'}
                                </p>
                                {analysisStage === undefined && (
                                    <ButtonLink
                                        size="sm"
                                        variant="ghost"
                                        href={jobShow.url({
                                            jobPosting: op.id,
                                        })}
                                    >
                                        Analyze on its own
                                    </ButtonLink>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-1.5 text-sm">
                                <p className="text-fg-muted line-clamp-3 leading-relaxed">
                                    {analysis.role_summary}
                                </p>
                                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                    <p className="text-fg-subtle text-xs">
                                        Analyzed {day(analysis.generated_at)} ·{' '}
                                        {analysis.findings_count}{' '}
                                        {analysis.findings_count === 1
                                            ? 'finding'
                                            : 'findings'}
                                        {analysis.match !== null &&
                                            ` · matched ${day(analysis.match.generated_at)}`}
                                    </p>
                                    <div className="-mr-2 flex flex-wrap gap-1">
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
                            </div>
                        )}
                    </WorkflowStep>

                    <WorkflowStep
                        id="resume"
                        title="Tailored resume"
                        prominent
                        status={resumeStatus}
                        badge={
                            <Badge tone={summary.tone}>{summary.label}</Badge>
                        }
                    >
                        <TailoredResumePanel
                            embedded
                            primaryInHeader
                            controlRef={resumeControl}
                            jobId={op.id}
                            initial={tailored_resume}
                            profiles={resume_profiles}
                            returnTo="opportunity"
                            onStatus={setGeneration}
                            onBusy={setStarting}
                        />
                        {op.resume.current_variant_id !== null &&
                            action.kind !== 'link' && (
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
                    </WorkflowStep>

                    <WorkflowStep
                        id="inspection"
                        title="Application inspection"
                        status={stepStatusFromTone(inspectionTone)}
                        badge={
                            showInspectionBadge ? (
                                <InspectionStateBadge summary={op.inspection} />
                            ) : (
                                <Badge>Not started</Badge>
                            )
                        }
                    >
                        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                            <div className="min-w-0 flex-1 basis-60">
                                <p className="text-fg-muted text-sm">
                                    Identifies application fields and questions
                                    without filling or submitting anything.
                                </p>
                                {!inspected && op.source_url === null && (
                                    <p className="text-fg-subtle mt-1 text-xs">
                                        This job has no application link to
                                        inspect.
                                    </p>
                                )}
                            </div>
                            {(inspected || op.source_url !== null) && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    aria-haspopup="dialog"
                                    onClick={opening('inspection')}
                                >
                                    {inspected
                                        ? 'View inspection'
                                        : 'Inspect application form'}
                                </Button>
                            )}
                        </div>
                    </WorkflowStep>

                    <PreparationSection
                        panel={preparation}
                        title={op.title}
                        company={op.company}
                    />
                </Card>

                <Card className="flex flex-col p-4 sm:p-5">
                    <section
                        aria-labelledby="details-title"
                        className="flex min-h-0 flex-1 flex-col"
                    >
                        <h2
                            id="details-title"
                            className="text-fg text-sm font-semibold tracking-tight"
                        >
                            Opportunity details
                        </h2>
                        <dl className="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            <div className="min-w-0">
                                <dt className="text-fg-subtle text-xs">
                                    Location
                                </dt>
                                <dd
                                    className={
                                        place.length > 0
                                            ? 'text-fg break-words'
                                            : 'text-fg-muted'
                                    }
                                >
                                    {place.length > 0
                                        ? place.join(' · ')
                                        : 'Not listed'}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-fg-subtle text-xs">
                                    Salary
                                </dt>
                                <dd
                                    className={
                                        pay
                                            ? 'text-fg break-words'
                                            : 'text-fg-muted'
                                    }
                                >
                                    {pay ?? 'Not listed'}
                                </dd>
                            </div>
                        </dl>

                        <div className="border-line mt-4 flex min-h-0 flex-1 flex-col border-t pt-4">
                            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                <h3
                                    id="description-title"
                                    className="text-fg text-sm font-semibold tracking-tight"
                                >
                                    Job description
                                </h3>
                                {op.description_completeness !== 'complete' && (
                                    <Badge tone="warn">
                                        {op.description_completeness ===
                                        'preview'
                                            ? 'Preview only'
                                            : 'Not confirmed complete'}
                                    </Badge>
                                )}
                            </div>
                            {op.description_completeness !== 'complete' && (
                                <p
                                    role="note"
                                    className="text-warn mt-2 text-xs leading-relaxed"
                                >
                                    {completenessNote(
                                        op.description_completeness,
                                    )}
                                </p>
                            )}
                            {hasDescription ? (
                                <>
                                    <div
                                        className="mt-3 line-clamp-8 min-h-0 lg:line-clamp-[14]"
                                        aria-hidden="true"
                                    >
                                        <JobDescription text={op.description} />
                                    </div>
                                    <div className="mt-auto flex flex-wrap items-center justify-between gap-x-3 gap-y-1 pt-3">
                                        <Button
                                            size="sm"
                                            aria-haspopup="dialog"
                                            onClick={opening('description')}
                                        >
                                            Read full description
                                        </Button>
                                        {op.captured_at !== null && (
                                            <span className="text-fg-subtle text-xs">
                                                Captured {day(op.captured_at)}
                                            </span>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <p className="text-fg-muted mt-2 text-sm">
                                    No job description was captured for this
                                    posting.
                                </p>
                            )}
                        </div>
                    </section>
                </Card>
            </div>

            <Card className="mt-4">
                <section
                    id="application"
                    aria-labelledby="application-title"
                    className="scroll-mt-4 px-4 py-3.5 sm:px-5"
                >
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <h2
                                id="application-title"
                                tabIndex={-1}
                                className="text-fg text-sm font-semibold tracking-tight focus:outline-none"
                            >
                                Application status
                            </h2>
                            {counted.length === 0 ? (
                                <Badge>Not recorded</Badge>
                            ) : counted.length > 1 ? (
                                <span className="text-fg-subtle text-xs">
                                    {counted.length} applications
                                </span>
                            ) : undefined}
                            {counted.length === 0 && (
                                <p className="text-fg-muted min-w-0 text-sm">
                                    Tracks an application you submitted
                                    yourself. Well Applied never submits for
                                    you.
                                </p>
                            )}
                        </div>
                        <Button
                            size="sm"
                            variant={
                                counted.length === 0 ? 'primary' : 'secondary'
                            }
                            aria-haspopup="dialog"
                            onClick={(event) => {
                                setRecordingAnother(activeRecord !== null);
                                opening('application')(event);
                            }}
                        >
                            {counted.length === 0
                                ? 'Record that I applied'
                                : 'Record another application'}
                        </Button>
                    </div>

                    {counted.length > 0 && (
                        <ul className="divide-line mt-3 divide-y">
                            {counted.map((record) => (
                                <SubmissionItem
                                    key={record.id}
                                    record={record}
                                    onUpdate={() =>
                                        setEditing({ record, mode: 'update' })
                                    }
                                    onCorrect={() =>
                                        setEditing({ record, mode: 'correct' })
                                    }
                                />
                            ))}
                        </ul>
                    )}

                    {inError.length > 0 && (
                        <details className="border-line mt-4 border-t pt-3">
                            <summary className="text-fg-subtle cursor-pointer text-xs font-medium">
                                Recorded in error ({inError.length})
                            </summary>
                            <ul className="divide-line mt-2 divide-y opacity-80">
                                {inError.map((record) => (
                                    <SubmissionItem
                                        key={record.id}
                                        record={record}
                                        onUpdate={() => undefined}
                                        onCorrect={() => undefined}
                                    />
                                ))}
                            </ul>
                        </details>
                    )}
                </section>
            </Card>

            <div className="border-line mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-4">
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={decisions.pending}
                    aria-label={`Return ${label} to the Shortlist`}
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
                    <div className="pt-2">
                        <JobDescription text={op.description} />
                    </div>
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
            {editing?.mode === 'update' && (
                <SubmissionUpdateDrawer
                    record={editing.record}
                    jobLabel={label}
                    onClose={closeEditing}
                />
            )}
            {editing?.mode === 'correct' && (
                <SubmissionCorrectionDrawer
                    record={editing.record}
                    jobLabel={label}
                    onClose={closeEditing}
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
