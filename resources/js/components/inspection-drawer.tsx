import { router } from '@inertiajs/react';
import {
    ProviderCredit,
    sourceLinkLabel,
} from '@/components/provider-attribution';
import { useEffect, useRef, useState } from 'react';
import { GenerationStatus } from '@/components/generation-status';
import {
    ApplicationUrlForm,
    InspectionFindings,
    TechnicalDetails,
} from '@/components/inspection-findings';
import { Badge } from '@/components/ui/badge';
import { Button, ButtonAnchor, ButtonLink } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import {
    WaitingForWorkerNotice,
    WorkerStatusBadge,
} from '@/components/worker-status-badge';
import { useApplicationInspectionPolling } from '@/hooks/use-application-inspection-polling';
import type {
    InspectionDestination,
    InspectionStarter,
} from '@/hooks/use-start-inspection';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import {
    describeFailure,
    firstInspectionOptions,
    fromProviderSchema,
    hostOf,
    presentInspection,
} from '@/lib/inspection-status';
import { notify } from '@/lib/toast';
import { show as applicationsShow } from '@/routes/applications';
import type {
    ApplicationInspectionState,
    ApplicationQuestionView,
    InspectionDiagnostics,
    InspectionJob,
    InspectionResult,
    InspectionWorkflowRun,
} from '@/types/application-inspection';

export type InspectionDrawerState = 'loading' | 'ready' | 'unavailable';

export type { InspectionDestination };

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
    const schema = fromProviderSchema(question);
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
                            {schema
                                ? 'Untitled in the provider schema'
                                : 'Unresolved field'}
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
                          ? schema
                              ? 'required in schema'
                              : 'required'
                          : schema
                            ? 'optional in schema'
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

function QuestionList({ result }: { result: InspectionResult }) {
    return (
        <ul className="divide-line border-line bg-surface divide-y rounded-lg border">
            {result.questions.map((question) => (
                <Question key={question.position} question={question} />
            ))}
        </ul>
    );
}

/**
 * The body for one job with its inspection state. Keyed by job and application by the caller, so starting the first
 * inspection (which creates the application) remounts it with the new state and polling begins. It never starts an
 * inspection by itself: every start is a click or a form submit, handled by `starter`.
 */
function InspectionBody({
    job,
    destination,
    initial,
    starter,
}: {
    job: InspectionJob;
    destination: InspectionDestination;
    initial: ApplicationInspectionState | null;
    starter: InspectionStarter;
}) {
    const polled = useApplicationInspectionPolling(
        job.application_id ?? 0,
        initial ?? {
            application_id: 0,
            application_url: null,
            workflow_run: null,
            latest_attempt: null,
            latest_result: null,
            earlier_result: null,
        },
    );
    const state = initial === null ? null : polled;
    const view = presentInspection(state);
    const worker = useWorkerStatusPolling();
    const result = state?.latest_result ?? null;
    const earlier = state?.earlier_result ?? null;
    const run = state?.workflow_run ?? null;
    const attempt = state?.latest_attempt ?? null;
    const link = state?.application_url ?? null;
    const diagnostics = (result ?? attempt)?.diagnostics ?? null;
    // Between the click and the first poll of the new run the server has a run the page doesn't know about yet.
    const busy = view.active || starter.starting;

    // Results arrive while the drawer is open: when a run finishes, refresh the list behind it, and say so briefly.
    const wasActive = useRef(view.active);
    useEffect(() => {
        if (wasActive.current && !view.active) {
            router.reload({ only: destination.refresh });
            notify.info(`Inspection finished: ${view.headline.label}`);
        }

        wasActive.current = view.active;
    }, [view.active, view.headline.label]);

    const failure =
        run?.status === 'failed'
            ? describeFailure(
                  attempt?.failure_category ?? null,
                  run.failure_message,
              )
            : null;

    return (
        <div className="space-y-5">
            <p className="text-fg-muted text-sm leading-relaxed">
                Read-only: this opens the application page and reads its fields
                and questions without filling in or submitting anything.
            </p>

            <section aria-labelledby="inspection-status" className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3
                        id="inspection-status"
                        className="text-fg text-sm font-semibold tracking-tight"
                    >
                        Latest inspection
                    </h3>
                    <Badge
                        tone={starter.starting ? 'accent' : view.headline.tone}
                    >
                        {starter.starting && !view.active
                            ? 'Starting…'
                            : view.headline.label}
                    </Badge>
                </div>

                <GenerationStatus
                    active={busy}
                    label="Inspecting application"
                    queued={run?.status === 'pending' || starter.starting}
                    queuedLabel={
                        starter.starting && !view.active
                            ? 'Starting inspection…'
                            : 'Inspection queued…'
                    }
                    startedAt={run?.started_at}
                />

                {(busy || run === null) && (
                    <div className="space-y-1">
                        <WorkerStatusBadge status={worker} />
                        {busy && <WaitingForWorkerNotice status={worker} />}
                    </div>
                )}

                {failure !== null && (
                    <p role="alert" className="text-bad text-sm break-words">
                        {failure}
                    </p>
                )}

                <InspectionFindings
                    view={view}
                    result={result}
                    attempt={attempt}
                    link={link}
                />
            </section>

            {result && result.questions.length > 0 && (
                <section aria-labelledby="inspection-questions">
                    <h3
                        id="inspection-questions"
                        className="text-fg mb-2 text-sm font-semibold tracking-tight"
                    >
                        Extracted fields and questions ({result.field_count})
                    </h3>
                    <QuestionList result={result} />
                </section>
            )}

            {earlier && earlier.questions.length > 0 && (
                <section aria-labelledby="inspection-earlier">
                    <h3
                        id="inspection-earlier"
                        className="text-fg mb-1 text-sm font-semibold tracking-tight"
                    >
                        From an earlier inspection ({earlier.field_count})
                    </h3>
                    <p className="text-fg-subtle mb-2 text-xs">
                        The latest inspection found no fields, so the fields an
                        earlier one
                        {earlier.finished_at
                            ? ` (${new Date(earlier.finished_at).toLocaleDateString()})`
                            : ''}{' '}
                        read are kept here. They may be out of date.
                    </p>
                    <QuestionList result={earlier} />
                </section>
            )}

            <div className="border-line space-y-3 border-t pt-4">
                <div className="flex flex-wrap items-center gap-x-1 gap-y-2">
                    {job.source_url !== null || link?.url ? (
                        <Button
                            variant="primary"
                            disabled={busy}
                            onClick={() => starter.start(job.id)}
                        >
                            {busy
                                ? run?.status === 'pending' || starter.starting
                                    ? 'Queued…'
                                    : 'Inspecting…'
                                : view.action}
                        </Button>
                    ) : (
                        <p className="text-fg-muted text-sm">
                            This job has no application link to inspect. Enter
                            one below.
                        </p>
                    )}
                    {link?.url && (
                        <Button
                            variant="ghost"
                            disabled={busy || job.source_url === null}
                            onClick={() =>
                                starter.start(job.id, {
                                    clearApplicationUrl: true,
                                })
                            }
                        >
                            Start again from the listing
                        </Button>
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
                            {sourceLinkLabel(job.attribution)}
                        </ButtonAnchor>
                    )}
                    <ProviderCredit attribution={job.attribution} />
                </div>

                <ApplicationUrlForm
                    saved={link}
                    prominent={view.needsApplicationUrl}
                    disabled={busy}
                    onSubmit={(url, reject) =>
                        starter.start(job.id, {
                            applicationUrl: url,
                            onInvalid: reject,
                        })
                    }
                />

                {diagnostics !== null && (
                    <TechnicalDetails
                        diagnostics={diagnostics}
                        view={view}
                        run={run}
                        result={result}
                    />
                )}
            </div>
        </div>
    );
}

/**
 * The setup for the FIRST inspection of a job. Opening the drawer starts nothing: it offers two ways to start and waits for the
 * person to choose one. Each choice is one explicit confirmation that makes one request to the existing inspection endpoint
 * (the link choice rides its `application_url` override); the Application and the run are created only then. `starter` gates
 * double clicks, and `only_if_none` makes the server start a run only if the job still has none.
 */
function InspectionSetup({
    job,
    starter,
}: {
    job: InspectionJob;
    starter: InspectionStarter;
}) {
    const host = hostOf(job.source_url);

    return (
        <div className="space-y-5">
            <p className="text-fg-muted text-sm leading-relaxed">
                Read-only: this opens the application page and reads its fields
                and questions without filling in or submitting anything. Nothing
                runs, and nothing is saved, until you choose one of these.
            </p>

            <section
                aria-labelledby="setup-listing"
                className="border-line bg-sunken space-y-2 rounded-lg border px-3 py-3"
            >
                <h3
                    id="setup-listing"
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    Discover from the job listing
                </h3>
                <p className="text-fg-muted text-xs">
                    {job.source_url !== null
                        ? `We open the listing${host ? ` on ${host}` : ''} and follow its Apply link to the application form.`
                        : 'This job has no listing link to start from. Enter the application URL below.'}
                </p>
                <Button
                    variant="secondary"
                    disabled={job.source_url === null || starter.starting}
                    onClick={() =>
                        starter.start(job.id, firstInspectionOptions('listing'))
                    }
                >
                    {starter.starting ? 'Starting…' : 'Inspect listing'}
                </Button>
            </section>

            <ApplicationUrlForm
                plain
                prominent
                heading="I have the application URL"
                saved={null}
                disabled={starter.starting}
                onSubmit={(url, reject) =>
                    starter.start(
                        job.id,
                        firstInspectionOptions('url', url, reject),
                    )
                }
            />

            {job.source_url !== null && (
                <ButtonAnchor
                    variant="ghost"
                    href={job.source_url}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {sourceLinkLabel(job.attribution)}
                </ButtonAnchor>
            )}
            <ProviderCredit attribution={job.attribution} />
        </div>
    );
}

/** The Opportunities inspection drawer: what the inspector found for one pursued job, and a way to run it again. */
export function InspectionDrawer({
    state,
    job,
    fallback,
    destination,
    starter,
    onClose,
}: {
    state: InspectionDrawerState;
    job: InspectionJob | null;
    fallback: { title: string; company: string } | null;
    destination: InspectionDestination;
    /** Shared with whatever opened the drawer, so one click and one button can never both start a run. */
    starter: InspectionStarter;
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
            {state === 'ready' && job && job.inspection === null && (
                <InspectionSetup
                    key={`${job.id}:setup`}
                    job={job}
                    starter={starter}
                />
            )}
            {state === 'ready' && job && job.inspection !== null && (
                <InspectionBody
                    key={`${job.id}:${job.application_id}`}
                    job={job}
                    destination={destination}
                    initial={job.inspection}
                    starter={starter}
                />
            )}
        </Drawer>
    );
}
