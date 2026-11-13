import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ClarificationPanel } from '@/components/clarification-panel';
import { LayoutQualityNotice } from '@/components/layout-quality-notice';
import { useTailoredResumeStatus } from '@/hooks/use-tailored-resume-status';
import { formatElapsedTime } from '@/lib/format-elapsed-time';
import { start as startClarification } from '@/routes/jobs/clarification';
import { store as tailoredResumeStore } from '@/routes/jobs/tailored-resume';
import type {
    TailoredResumeStage,
    TailoredResumeStatus,
    TailoringProfile,
} from '@/types/tailored-resume';
import { buttonClass } from '@/components/ui/button';
import { fieldClass } from '@/components/ui/field';
import { cn } from '@/lib/utils';

const STAGE_MARK: Record<TailoredResumeStage['status'], string> = {
    pending: '○',
    queued: '◔',
    running: '●',
    done: '✓',
    reused: '✓',
    failed: '✕',
};

function useLiveElapsed(status: TailoredResumeStatus): number | null {
    const [now, setNow] = useState(() => Date.now());
    const [receivedAt, setReceivedAt] = useState(() => Date.now());

    useEffect(() => {
        setReceivedAt(Date.now());
        setNow(Date.now());
    }, [status.elapsed_seconds, status.state]);

    useEffect(() => {
        if (status.state !== 'running') {
            return;
        }

        const timer = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(timer);
    }, [status.state]);

    if (status.elapsed_seconds === null) {
        return null;
    }

    return status.state === 'running'
        ? status.elapsed_seconds + Math.floor((now - receivedAt) / 1000)
        : status.elapsed_seconds;
}

const REVIEW_STATUS_LABEL = {
    review: 'Needs verification',
    generalized: 'Generalized, verify',
    omitted: 'Left out',
} as const;

function ReviewReport({
    review,
}: {
    review: NonNullable<TailoredResumeStatus['review']>;
}) {
    if (review.items.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
            <p className="font-medium">
                Review before sending: {review.counts.review} to verify,{' '}
                {review.counts.generalized} generalized, {review.counts.omitted}{' '}
                left out, {review.counts.passed} passed.
            </p>
            <ul className="mt-2 space-y-2">
                {review.items.map((item) => (
                    <li key={`${item.label}-${item.status}`}>
                        <span className="font-medium">
                            {item.label}: {REVIEW_STATUS_LABEL[item.status]}
                        </span>
                        {item.action && <span> {item.action}</span>}
                        {item.text && (
                            <span className="block italic">
                                Claim: &ldquo;{item.text}&rdquo;
                            </span>
                        )}
                        {item.findings.map((finding) => (
                            <span key={finding.message} className="block">
                                {finding.message}
                            </span>
                        ))}
                        {item.evidence.map((fact) => (
                            <span
                                key={fact.fact_key}
                                className="block text-xs opacity-80"
                            >
                                Evidence ({fact.visibility ?? 'unknown'}):{' '}
                                {fact.statement ?? fact.fact_key}
                            </span>
                        ))}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function StageList({ stages }: { stages: TailoredResumeStage[] }) {
    return (
        <ol className="mt-3 space-y-1 text-sm">
            {stages.map((stage) => (
                <li
                    key={stage.key}
                    className={
                        stage.status === 'running' || stage.status === 'queued'
                            ? 'font-medium text-neutral-900 dark:text-neutral-100'
                            : stage.status === 'failed'
                              ? 'text-red-600 dark:text-red-400'
                              : 'text-neutral-500 dark:text-neutral-400'
                    }
                >
                    <span aria-hidden className="mr-2 inline-block w-4">
                        {STAGE_MARK[stage.status]}
                    </span>
                    {stage.label}
                    {stage.status === 'reused' && ' (reused existing result)'}
                    {stage.status === 'queued' && ' (waiting for worker)'}
                    {stage.phase && ` (${stage.phase})`}
                </li>
            ))}
        </ol>
    );
}

/**
 * The one-click "Generate Tailored Resume" control: starts or resumes
 * the durable pipeline and shows stage-level progress (never a made-up
 * percentage), elapsed time, a useful failure with Retry, and a
 * confirmation step before generating from a description that is not
 * known to be complete. See App\Support\TailoredResume.
 */
export function TailoredResumePanel({
    jobId,
    initial,
    profiles = [],
}: {
    jobId: number;
    initial: TailoredResumeStatus;
    profiles?: TailoringProfile[];
}) {
    const { status, refresh } = useTailoredResumeStatus(jobId, initial);
    const elapsed = useLiveElapsed(status);
    const [processing, setProcessing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [askError, setAskError] = useState<string | null>(null);
    const running = status.state === 'running';
    // Tailor from an approved profile by default (the most relevant fresh one); "standard" is the original pathway.
    const [profileId, setProfileId] = useState<string>(
        String(
            status.profile_resume?.profile_id ??
                profiles.find((p) => p.recommended)?.id ??
                '',
        ),
    );
    // The approved profile is the way to generate; the original matching pathway stays available under Advanced.
    const [legacy, setLegacy] = useState(profiles.length === 0);
    const selected = legacy
        ? null
        : (profiles.find((p) => String(p.id) === profileId) ?? null);

    const start = (options: {
        allowIncomplete?: boolean;
        regenerate?: boolean;
    }) => {
        setProcessing(true);
        router.post(
            tailoredResumeStore.url({ jobPosting: jobId }),
            {
                allow_incomplete_description: options.allowIncomplete ?? false,
                regenerate: options.regenerate ?? false,
                ...(selected && !selected.stale
                    ? { resume_profile_synthesis_id: selected.id }
                    : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setConfirming(false);
                    refresh();
                },
                onError: (errors) => {
                    if (errors.allow_incomplete_description) {
                        setConfirming(true);
                    }

                    if (errors.resume_profile_synthesis_id) {
                        setAskError(errors.resume_profile_synthesis_id);
                    }
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const click = (regenerate = false) => {
        if (status.needs_confirmation && !confirming) {
            setConfirming(true);

            return;
        }

        start({ allowIncomplete: status.needs_confirmation, regenerate });
    };

    return (
        <section
            aria-label="Generate Tailored Resume"
            className="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Tailored Resume
                    </h2>
                    <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                        {selected && !selected.stale
                            ? 'Uses your approved profile as the starting point, finds the evidence this job needs, writes the resume, and renders the PDF. The stored job analysis is reused.'
                            : 'Analyzes this job, matches your career evidence, composes the resume, and opens the PDF preview. Existing valid results are reused.'}
                    </p>
                    {profiles.length > 0 &&
                        !legacy &&
                        status.state !== 'running' && (
                            <label className="mt-2 flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-400">
                                Profile
                                <select
                                    value={profileId}
                                    onChange={(e) =>
                                        setProfileId(e.target.value)
                                    }
                                    className={cn(fieldClass, 'text-xs')}
                                >
                                    {profiles.map((p) => (
                                        <option
                                            key={p.id}
                                            value={p.id}
                                            disabled={p.stale}
                                        >
                                            {p.name} (version {p.version}
                                            {p.stale
                                                ? ', evidence changed: refresh it first'
                                                : ''}
                                            )
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    {profiles.length > 0 && status.state !== 'running' && (
                        <details className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                            <summary className="cursor-pointer">
                                Advanced
                            </summary>
                            <label className="mt-1 flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={legacy}
                                    onChange={(e) =>
                                        setLegacy(e.target.checked)
                                    }
                                />
                                Use the original matching pathway instead of an
                                approved profile
                            </label>
                        </details>
                    )}
                </div>

                {status.state !== 'ready' &&
                    status.state !== 'needs_clarification' && (
                        <button
                            type="button"
                            disabled={processing || running}
                            onClick={() => click()}
                            className={buttonClass('primary', 'md')}
                        >
                            {running
                                ? 'Generating…'
                                : status.state === 'failed'
                                  ? 'Retry'
                                  : 'Generate Tailored Resume'}
                        </button>
                    )}
            </div>

            {confirming && !running && (
                <div
                    role="alert"
                    className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                >
                    <p>
                        Well Applied cannot confirm this is the complete job
                        description. Missing requirements or responsibilities
                        can affect the tailoring.
                    </p>
                    <div className="mt-2 flex gap-2">
                        <button
                            type="button"
                            disabled={processing}
                            onClick={() =>
                                start({
                                    allowIncomplete: true,
                                    regenerate: status.state === 'ready',
                                })
                            }
                            className="rounded-md bg-amber-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-800 disabled:opacity-50"
                        >
                            Generate Anyway
                        </button>
                        <button
                            type="button"
                            onClick={() => setConfirming(false)}
                            className="rounded-md border border-amber-400 px-3 py-1.5 text-xs font-medium"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            )}

            {status.state === 'idle' && status.evidence_changed && (
                <p
                    role="status"
                    className="mt-3 text-sm text-neutral-600 dark:text-neutral-400"
                >
                    {status.label}
                </p>
            )}

            {status.state !== 'idle' && (
                <div className="mt-3">
                    <p
                        role="status"
                        className="text-sm font-medium text-neutral-900 dark:text-neutral-100"
                    >
                        {status.label}
                        {elapsed !== null && (
                            <span className="ml-2 font-normal text-neutral-500 tabular-nums dark:text-neutral-400">
                                {formatElapsedTime(elapsed)} elapsed
                            </span>
                        )}
                    </p>
                    <StageList stages={status.stages} />
                </div>
            )}

            {status.state === 'needs_clarification' && status.clarification && (
                <ClarificationPanel
                    jobId={jobId}
                    clarification={status.clarification}
                />
            )}

            {status.state === 'failed' && status.failure && (
                <p
                    role="alert"
                    className="mt-3 text-sm text-red-600 dark:text-red-400"
                >
                    {status.failure.stage_label}
                    {status.failure.phase && ` (${status.failure.phase})`}{' '}
                    failed: {status.failure.message ?? 'unknown error'} Retry
                    continues from this stage; completed stages are not repeated
                    {status.failure.selection_saved &&
                        ', and the validated resume selection is saved and will be reused'}
                    .
                </p>
            )}

            {status.state === 'ready' &&
                status.review &&
                !status.profile_resume && (
                    <ReviewReport review={status.review} />
                )}

            {askError && (
                <p
                    role="alert"
                    className="mt-3 text-sm text-neutral-600 dark:text-neutral-400"
                >
                    {askError}
                </p>
            )}

            {status.state === 'ready' && status.profile_resume?.review_url && (
                <div className="mt-3">
                    <div className="rounded-md border border-neutral-200 p-3 dark:border-neutral-800">
                        <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {status.profile_resume.label ?? 'Tailored resume'}
                            {status.profile_resume.kind === 'revision' && (
                                <span className="ml-2 font-normal text-neutral-500 dark:text-neutral-400">
                                    {status.profile_resume.summary}
                                </span>
                            )}
                        </p>
                        <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                            {status.profile_resume.profile_name}, version{' '}
                            {status.profile_resume.profile_version}
                            {status.layout?.pages &&
                                ` · ${status.layout.pages} ${status.layout.pages === 1 ? 'page' : 'pages'}`}
                            {status.review &&
                                status.review.items.length > 0 &&
                                ` · ${status.review.items.length} to check`}
                            {status.profile_resume.revisions > 1 &&
                                ` · ${status.profile_resume.revisions} versions`}
                        </p>
                        <LayoutQualityNotice quality={status.layout?.quality} />
                        {status.profile_resume.preferred &&
                            !status.profile_resume.preferred.is_current && (
                                <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                                    You marked another version final.{' '}
                                    <a
                                        href={
                                            status.profile_resume.preferred.url
                                        }
                                        className="font-medium underline underline-offset-2"
                                    >
                                        Open it
                                    </a>
                                </p>
                            )}
                    </div>
                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <a
                            href={status.profile_resume.review_url}
                            className={buttonClass('primary', 'md')}
                        >
                            Open resume
                        </a>
                        {status.download_url && (
                            <a
                                href={status.download_url}
                                className={buttonClass('secondary', 'md')}
                            >
                                Download PDF
                            </a>
                        )}
                        <details className="text-sm text-neutral-600 dark:text-neutral-400">
                            <summary className="cursor-pointer">More</summary>
                            <button
                                type="button"
                                disabled={processing}
                                onClick={() => click(true)}
                                className="mt-1 font-medium underline underline-offset-2 hover:text-neutral-900 disabled:opacity-50 dark:hover:text-neutral-200"
                            >
                                Write a new resume from the profile
                            </button>
                            <p className="mt-0.5 max-w-xs text-xs">
                                Starts over with a new model call. Your current
                                versions are kept.
                            </p>
                        </details>
                    </div>
                </div>
            )}

            {status.state === 'ready' && !status.profile_resume && (
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    {status.preview_url && (
                        <a
                            href={status.preview_url}
                            className={buttonClass('primary', 'md')}
                        >
                            Open Preview
                        </a>
                    )}
                    {status.download_url && (
                        <a
                            href={status.download_url}
                            className={buttonClass('secondary', 'md')}
                        >
                            Download PDF
                        </a>
                    )}

                    {!status.profile_resume && (
                        <button
                            type="button"
                            disabled={processing}
                            onClick={() => {
                                setAskError(null);
                                router.post(
                                    startClarification.url({
                                        jobPosting: jobId,
                                    }),
                                    { mode: 'standard' },
                                    {
                                        preserveScroll: true,
                                        onError: (errors) =>
                                            setAskError(
                                                errors.clarification ??
                                                    'Could not open questions.',
                                            ),
                                    },
                                );
                            }}
                            className="text-sm font-medium text-neutral-600 underline underline-offset-2 hover:text-neutral-900 disabled:opacity-50 dark:text-neutral-400"
                        >
                            Add details to improve targeting
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={processing}
                        onClick={() => click(true)}
                        className="text-sm font-medium text-neutral-600 underline underline-offset-2 hover:text-neutral-900 disabled:opacity-50 dark:text-neutral-400"
                    >
                        Regenerate resume
                    </button>
                    {status.layout?.pages && (
                        <span className="text-xs text-neutral-500 dark:text-neutral-400">
                            {status.layout.pages}{' '}
                            {status.layout.pages === 1 ? 'page' : 'pages'}
                        </span>
                    )}
                </div>
            )}
        </section>
    );
}
