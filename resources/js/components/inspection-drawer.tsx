import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { GenerationStatus } from '@/components/generation-status';
import { Badge } from '@/components/ui/badge';
import { Button, ButtonAnchor, ButtonLink } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import {
    WaitingForWorkerNotice,
    WorkerStatusBadge,
} from '@/components/worker-status-badge';
import { useApplicationInspectionPolling } from '@/hooks/use-application-inspection-polling';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import { presentInspection } from '@/lib/inspection-status';
import { notify } from '@/lib/toast';
import { show as applicationsShow } from '@/routes/applications';
import { store as inspectStore } from '@/routes/jobs/applications';
import type {
    ApplicationInspectionState,
    ApplicationQuestionView,
    InspectionJob,
} from '@/types/application-inspection';

export type InspectionDrawerState = 'loading' | 'ready' | 'unavailable';

/**
 * Where the drawer lives, which decides what a started inspection returns to and which props a finished run refreshes:
 * the Opportunities list (`return_to=opportunities` plus its resume filter) or one Opportunity's workspace.
 */
export type InspectionDestination = {
    post: Record<string, string>;
    refresh: string[];
};

export const listDestination = (resume: string): InspectionDestination => ({
    post: { return_to: 'opportunities', resume },
    refresh: ['opportunities'],
});

export const workspaceDestination: InspectionDestination = {
    post: { return_to: 'opportunity' },
    refresh: ['opportunity'],
};

const OPTIONS_COLLAPSE_THRESHOLD = 6;

function Question({ question }: { question: ApplicationQuestionView }) {
    const unresolved = question.label_source === 'unresolved';
    const options = question.options ?? [];

    return (
        <li className="px-3 py-2.5">
            <div className="flex items-start justify-between gap-3">
                <p className="min-w-0 text-sm leading-snug break-words">
                    <span className="text-fg-subtle mr-1.5 text-xs">
                        #{question.position}
                    </span>
                    {unresolved ? (
                        <span className="text-fg-subtle italic">
                            Unresolved field
                        </span>
                    ) : (
                        <span className="text-fg">{question.raw_label}</span>
                    )}
                    {question.section && (
                        <span className="text-fg-subtle ml-1.5 text-xs">
                            ({question.section})
                        </span>
                    )}
                </p>
                <p className="text-fg-subtle shrink-0 text-xs">
                    {question.control_type}
                    {' · '}
                    {question.required === null
                        ? 'unknown'
                        : question.required
                          ? 'required'
                          : 'optional'}
                </p>
            </div>
            {options.length > 0 &&
                (options.length <= OPTIONS_COLLAPSE_THRESHOLD ? (
                    <p className="text-fg-subtle mt-1 text-xs break-words">
                        Options: {options.join(', ')}
                    </p>
                ) : (
                    <details className="mt-1">
                        <summary className="text-fg-subtle cursor-pointer text-xs">
                            {options.length} options
                        </summary>
                        <p className="text-fg-subtle mt-1 text-xs break-words">
                            {options.join(', ')}
                        </p>
                    </details>
                ))}
        </li>
    );
}

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
            <dt className="text-fg-subtle text-xs">{label}</dt>
            <dd className="text-fg text-sm">{children}</dd>
        </div>
    );
}

/**
 * The body for one job with its inspection state. Keyed by job and application by the caller, so starting the first
 * inspection (which creates the application) remounts it with the new state and polling begins.
 */
function InspectionBody({
    job,
    destination,
    initial,
}: {
    job: InspectionJob;
    destination: InspectionDestination;
    initial: ApplicationInspectionState | null;
}) {
    const polled = useApplicationInspectionPolling(
        job.application_id ?? 0,
        initial ?? {
            application_id: 0,
            workflow_run: null,
            latest_result: null,
        },
    );
    const state = initial === null ? null : polled;
    const view = presentInspection(state);
    const worker = useWorkerStatusPolling();
    const [starting, setStarting] = useState(false);
    // A ref as well as state: two clicks in the same tick both see the state as false.
    const requested = useRef(false);
    const result = state?.latest_result ?? null;
    const run = state?.workflow_run ?? null;

    // Results arrive while the drawer is open: when a run finishes, refresh the list behind it, and say so briefly.
    const wasActive = useRef(view.active);
    useEffect(() => {
        if (wasActive.current && !view.active) {
            router.reload({ only: destination.refresh });
            notify.info(`Inspection finished: ${view.headline.label}`);
        }

        wasActive.current = view.active;
    }, [view.active, view.headline.label]);

    const inspect = () => {
        if (
            requested.current ||
            starting ||
            view.active ||
            job.source_url === null
        ) {
            return;
        }

        requested.current = true;
        setStarting(true);
        router.post(
            inspectStore.url({ jobPosting: job.id }),
            destination.post,
            {
                only: ['inspection_job', ...destination.refresh, 'filters'],
                preserveScroll: true,
                preserveState: true,
                showProgress: false,
                onError: () =>
                    notify.error("Couldn't start the inspection", {
                        description: 'Nothing was queued. Try again.',
                    }),
                onNetworkError: () => {
                    notify.error("Couldn't reach the server", {
                        description: 'Nothing was queued. Try again.',
                    });
                },
                onFinish: () => {
                    requested.current = false;
                    setStarting(false);
                },
            },
        );
    };

    return (
        <div className="space-y-5">
            <p className="text-fg-muted text-sm leading-relaxed">
                Read-only: this reads the application form's fields and
                questions without filling in or submitting anything.
            </p>

            <section aria-labelledby="inspection-status" className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3
                        id="inspection-status"
                        className="text-fg text-sm font-semibold tracking-tight"
                    >
                        Latest inspection
                    </h3>
                    <Badge tone={view.headline.tone}>
                        {view.headline.label}
                    </Badge>
                </div>

                <dl className="border-line bg-sunken space-y-1.5 rounded-lg border px-3 py-2.5">
                    <Fact label="Browser worker run">
                        <Badge tone={view.execution.tone}>
                            {view.execution.label}
                        </Badge>
                    </Fact>
                    <Fact label="Inspection outcome">
                        {view.outcome ? (
                            <Badge tone={view.outcome.tone}>
                                {view.outcome.label}
                                {view.outcomeIsEarlier ? ' (earlier run)' : ''}
                            </Badge>
                        ) : (
                            <span className="text-fg-subtle">
                                {view.active ? 'Pending' : 'None yet'}
                            </span>
                        )}
                    </Fact>
                    {result && (
                        <>
                            <Fact label="ATS">
                                {result.ats_detected ?? 'Not identified'}
                            </Fact>
                            <Fact label="Fields and questions">
                                {result.field_count}
                                {result.unresolved_count > 0 &&
                                    ` (${result.unresolved_count} unresolved)`}
                            </Fact>
                            {result.finished_at && (
                                <Fact label="Last inspected">
                                    {new Date(
                                        result.finished_at,
                                    ).toLocaleString()}
                                </Fact>
                            )}
                        </>
                    )}
                </dl>

                <GenerationStatus
                    active={view.active}
                    label="Inspecting application"
                    queued={run?.status === 'pending'}
                    queuedLabel="Inspection queued…"
                    startedAt={run?.started_at}
                />

                {(view.active || run === null) && (
                    <div className="space-y-1">
                        <WorkerStatusBadge status={worker} />
                        {view.active && (
                            <WaitingForWorkerNotice status={worker} />
                        )}
                    </div>
                )}

                {run?.status === 'failed' && run.failure_message && (
                    <p role="alert" className="text-bad text-sm break-words">
                        {run.failure_message}
                    </p>
                )}

                {view.outcome && view.outcome.explanation && (
                    <p
                        className={
                            result?.inspection_outcome === 'complete'
                                ? 'sr-only'
                                : 'text-fg-muted text-sm leading-relaxed'
                        }
                    >
                        {view.outcome.explanation}
                    </p>
                )}

                {result && result.warnings.length > 0 && (
                    <ul className="text-warn space-y-0.5 text-xs">
                        {result.warnings.map((warning, index) => (
                            <li key={index} className="break-words">
                                ⚠ {warning}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {result && result.questions.length > 0 && (
                <section aria-labelledby="inspection-questions">
                    <h3
                        id="inspection-questions"
                        className="text-fg mb-2 text-sm font-semibold tracking-tight"
                    >
                        Extracted fields and questions ({result.field_count})
                    </h3>
                    <ul className="divide-line border-line bg-surface divide-y rounded-lg border">
                        {result.questions.map((question) => (
                            <Question
                                key={question.position}
                                question={question}
                            />
                        ))}
                    </ul>
                </section>
            )}

            <div className="border-line flex flex-wrap items-center gap-x-1 gap-y-2 border-t pt-4">
                {job.source_url !== null ? (
                    <Button
                        variant="primary"
                        disabled={starting || view.active}
                        onClick={inspect}
                    >
                        {view.active
                            ? run?.status === 'pending'
                                ? 'Queued…'
                                : 'Inspecting…'
                            : view.action}
                    </Button>
                ) : (
                    <p className="text-fg-muted text-sm">
                        This job has no application link to inspect.
                    </p>
                )}
                {job.application_id !== null && (
                    <ButtonLink
                        variant="ghost"
                        href={applicationsShow.url({
                            application: job.application_id,
                        })}
                    >
                        Open full inspection page
                    </ButtonLink>
                )}
                {job.source_url !== null && (
                    <ButtonAnchor
                        variant="ghost"
                        href={job.source_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Original posting ↗
                    </ButtonAnchor>
                )}
            </div>
        </div>
    );
}

/** The Opportunities inspection drawer: what the inspector found for one pursued job, and a way to run it again. */
export function InspectionDrawer({
    state,
    job,
    fallback,
    destination,
    onClose,
}: {
    state: InspectionDrawerState;
    job: InspectionJob | null;
    fallback: { title: string; company: string } | null;
    destination: InspectionDestination;
    onClose: () => void;
}) {
    return (
        <Drawer
            title={job?.title ?? fallback?.title ?? 'Application inspection'}
            subtitle={job?.company ?? fallback?.company}
            closeLabel="Close inspection"
            onClose={onClose}
        >
            {state === 'loading' && (
                <div
                    role="status"
                    aria-label="Loading inspection"
                    className="space-y-3 motion-safe:animate-pulse"
                >
                    <div className="bg-raised h-3 w-2/3 rounded" />
                    <div className="bg-raised h-3 w-full rounded" />
                    <div className="bg-raised h-3 w-5/6 rounded" />
                </div>
            )}
            {state === 'unavailable' && (
                <div role="alert" className="text-fg-muted text-sm">
                    <p className="text-fg font-medium">
                        This inspection isn't available.
                    </p>
                    <p className="mt-1">
                        The job may have moved out of Opportunities, or the link
                        is not one of your jobs.
                    </p>
                </div>
            )}
            {state === 'ready' && job && (
                <InspectionBody
                    key={`${job.id}:${job.application_id}`}
                    job={job}
                    destination={destination}
                    initial={job.inspection}
                />
            )}
        </Drawer>
    );
}
