import { Head, Link, useForm } from '@inertiajs/react';
import { index as careerDataIndex } from '@/routes/career-data';
import { useState } from 'react';
import {
    DescriptionCompletenessBadge,
    InspectionStateBadge,
} from '@/components/badges';
import { ApprovedEvidencePanel } from '@/components/approved-evidence-panel';
import { DispositionBadge } from '@/components/job-decisions';
import { GenerationStatus } from '@/components/generation-status';
import { TailoredResumePanel } from '@/components/tailored-resume-panel';
import { WaitingForWorkerNotice } from '@/components/worker-status-badge';
import { useGenerationAttemptPolling } from '@/hooks/use-generation-attempt-polling';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import AppShell from '@/layouts/app-shell';
import { show as applicationsShow } from '@/routes/applications';
import { index as jobsIndex } from '@/routes/jobs';
import {
    index as opportunitiesIndex,
    show as opportunitiesShow,
} from '@/routes/opportunities';
import {
    show as analysesShow,
    store as analysesStore,
} from '@/routes/jobs/analyses';
import { store as applicationsStore } from '@/routes/jobs/applications';
import type { GenerationAttempt } from '@/types/generation-attempt';
import type { InspectionSummary } from '@/types/application-inspection';
import type {
    DescriptionCompleteness,
    JobShowProps,
} from '@/types/job-posting';
import { JobDescription } from '@/components/job-description';
import { buttonClass } from '@/components/ui/button';

function InspectApplicationAction({
    jobId,
    inspection,
    hasSourceUrl,
}: {
    jobId: number;
    inspection: InspectionSummary;
    hasSourceUrl: boolean;
}) {
    const form = useForm({});
    const workerStatus = useWorkerStatusPolling();
    const isActive =
        inspection.state === 'queued' || inspection.state === 'running';

    if (!hasSourceUrl) {
        return null;
    }

    return (
        <div>
            <div className="flex items-center gap-3">
                <InspectionStateBadge summary={inspection} />
                {inspection.application_id !== null ? (
                    <Link
                        href={applicationsShow.url({
                            application: inspection.application_id,
                        })}
                        className={buttonClass('secondary', 'md')}
                    >
                        View Application Inspection
                    </Link>
                ) : (
                    <button
                        type="button"
                        disabled={form.processing}
                        onClick={() =>
                            form.post(
                                applicationsStore.url({ jobPosting: jobId }),
                                {
                                    showProgress: false,
                                },
                            )
                        }
                        className={buttonClass('secondary', 'md')}
                    >
                        Inspect Application
                    </button>
                )}
            </div>
            {isActive && <WaitingForWorkerNotice status={workerStatus} />}
        </div>
    );
}

function GenerateAnalysisAction({
    jobId,
    initialAttempt,
    completeness,
}: {
    jobId: number;
    initialAttempt: GenerationAttempt | null;
    completeness: DescriptionCompleteness;
}) {
    const form = useForm({ allow_incomplete_description: false });
    const [confirming, setConfirming] = useState(false);
    const attempt = useGenerationAttemptPolling(initialAttempt);
    const isActive =
        attempt !== null &&
        (attempt.status === 'queued' || attempt.status === 'running');
    const disabled = form.processing || isActive;

    return (
        <div>
            <button
                type="button"
                disabled={disabled}
                onClick={() => {
                    if (completeness !== 'complete') {
                        setConfirming(true);
                        return;
                    }
                    form.post(analysesStore.url({ jobPosting: jobId }), {
                        showProgress: false,
                    });
                }}
                className={buttonClass('primary', 'md')}
            >
                {disabled ? 'Generating…' : 'Generate Analysis'}
            </button>
            {confirming && !isActive && (
                <div className="mt-3 max-w-md rounded-md border border-neutral-300 p-3 text-sm dark:border-neutral-700">
                    <p>
                        {completeness === 'preview'
                            ? 'This posting contains only a preview.'
                            : 'Well Applied cannot verify that this description is complete.'}{' '}
                        Missing requirements or responsibilities can affect
                        analysis.
                    </p>
                    <div className="mt-2 flex gap-3">
                        <button
                            type="button"
                            disabled={disabled}
                            className="font-medium underline disabled:opacity-50"
                            onClick={() => {
                                form.transform(() => ({
                                    allow_incomplete_description: true,
                                }));
                                form.post(
                                    analysesStore.url({ jobPosting: jobId }),
                                    {
                                        showProgress: false,
                                        onSuccess: () => setConfirming(false),
                                        onFinish: () =>
                                            form.transform((data) => data),
                                    },
                                );
                            }}
                        >
                            Analyze Anyway
                        </button>
                        <button
                            type="button"
                            disabled={disabled}
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            )}
            {form.errors.allow_incomplete_description && (
                <p role="alert" className="mt-2 text-sm">
                    {form.errors.allow_incomplete_description}
                </p>
            )}
            <GenerationStatus
                active={isActive}
                label="Generating analysis"
                queued={attempt?.status === 'queued'}
                queuedLabel="Analysis queued…"
                startedAt={attempt?.started_at}
            />
            {attempt?.status === 'failed' && attempt.failure_message && (
                <p
                    role="alert"
                    className="mt-2 text-sm text-red-600 dark:text-red-400"
                >
                    {attempt.failure_message}
                </p>
            )}
        </div>
    );
}

export default function JobsShow({
    job,
    tailored_resume,
    resume_profiles,
    approved_evidence,
}: JobShowProps) {
    return (
        <AppShell>
            <Head title={`${job.title} at ${job.company}`} />

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <Link
                    href={
                        job.disposition.value === 'pursued'
                            ? opportunitiesIndex.url()
                            : jobsIndex.url()
                    }
                    className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                >
                    {job.disposition.value === 'pursued'
                        ? '← Back to Opportunities'
                        : '← Back to Jobs'}
                </Link>
                {job.disposition.value === 'pursued' && (
                    <Link
                        href={opportunitiesShow.url(job.id)}
                        className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                    >
                        Opportunity workspace
                    </Link>
                )}
                {job.disposition.value === 'pursued' && (
                    <Link
                        href={jobsIndex.url()}
                        className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                    >
                        All jobs
                    </Link>
                )}
            </div>

            <div className="mt-4">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {job.title}
                </h1>
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-neutral-600 dark:text-neutral-400">
                    {job.company}
                    <DispositionBadge disposition={job.disposition} />
                </p>

                <div className="mt-3">
                    <DescriptionCompletenessBadge
                        value={job.discovery.description_completeness}
                    />
                    {job.discovery.description_completeness !== 'complete' && (
                        <p
                            className={
                                job.discovery.description_completeness ===
                                'preview'
                                    ? 'mt-2 text-sm text-amber-700 dark:text-amber-300'
                                    : 'mt-2 text-sm text-neutral-500 dark:text-neutral-400'
                            }
                        >
                            {job.discovery.description_completeness ===
                            'preview'
                                ? 'This opportunity only contains a preview of the job description. Some requirements or responsibilities may be missing.'
                                : 'Well Applied cannot confirm that this is the complete job description.'}
                        </p>
                    )}
                </div>

                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {job.location && (
                        <div className="flex gap-1">
                            <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                                Location:
                            </dt>
                            <dd>{job.location}</dd>
                        </div>
                    )}
                    {job.source_url && (
                        <div className="flex gap-1">
                            <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                                Source:
                            </dt>
                            <dd>
                                <a
                                    href={job.source_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-neutral-700 underline underline-offset-2 dark:text-neutral-300"
                                >
                                    {job.source_url}
                                </a>
                            </dd>
                        </div>
                    )}
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Captured:
                        </dt>
                        <dd>{job.captured_at}</dd>
                    </div>
                </dl>

                <div className="mt-4">
                    <TailoredResumePanel
                        jobId={job.id}
                        initial={tailored_resume}
                        profiles={resume_profiles}
                    />
                </div>

                <ApprovedEvidencePanel evidence={approved_evidence} />
                <p className="text-fg-subtle mt-3 text-xs">
                    Everything you have confirmed, across all jobs, is in{' '}
                    <Link
                        href={careerDataIndex.url({
                            query: { view: 'facts', provenance: 'confirmed' },
                        })}
                        className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                    >
                        Career Data
                    </Link>
                    .
                </p>

                <div className="mt-4">
                    <InspectApplicationAction
                        jobId={job.id}
                        inspection={job.inspection}
                        hasSourceUrl={job.source_url !== null}
                    />
                </div>
            </div>

            <div className="mt-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Job Analysis{' '}
                        <span className="text-xs font-normal text-neutral-400">
                            (manual stage action)
                        </span>
                    </h2>
                    <GenerateAnalysisAction
                        jobId={job.id}
                        initialAttempt={job.latest_job_analysis_attempt}
                        completeness={job.discovery.description_completeness}
                    />
                </div>

                {job.analyses.length === 0 ? (
                    <p className="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                        No analysis has been generated yet.
                    </p>
                ) : (
                    <ul className="mt-2 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                        {job.analyses.map((analysis) => (
                            <li key={analysis.id}>
                                <Link
                                    href={analysesShow.url({
                                        jobPosting: job.id,
                                        jobAnalysis: analysis.id,
                                    })}
                                    className="flex items-center justify-between px-4 py-3 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                >
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        {analysis.generated_at ?? '—'}
                                    </span>
                                    <span className="text-xs text-neutral-400 dark:text-neutral-500">
                                        {analysis.overall_seniority ??
                                            'unspecified'}{' '}
                                        · {analysis.findings_count ?? 0} finding
                                        {analysis.findings_count === 1
                                            ? ''
                                            : 's'}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="mt-6">
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    Original Job Description
                </h2>
                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                    Captured verbatim at intake — not an analysis or summary.
                </p>
                <div className="mt-2 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <JobDescription text={job.description} />
                </div>
            </div>
        </AppShell>
    );
}
