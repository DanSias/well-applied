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
import type {
    InspectionDestination,
    InspectionStarter,
} from '@/hooks/use-start-inspection';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import { describeFailure, presentInspection } from '@/lib/inspection-status';
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

/** The few warnings worth saying out loud in the main view; every warning is in the technical details. */
const WARNING_TEXT: Record<string, string> = {
    apply_is_button:
        'The page has an "Apply" button rather than a link. Buttons are never pressed, so it was not followed.',
    apply_is_email:
        'The page applies by email, which is not something the inspector opens.',
    destination_is_download:
        'The Apply link leads to a file, not an application form.',
};

function TechnicalDetails({
    diagnostics,
    run,
    result,
}: {
    diagnostics: InspectionDiagnostics;
    run: InspectionWorkflowRun | null;
    result: InspectionResult | null;
}) {
    const view = presentInspection({
        application_id: 0,
        application_url: null,
        workflow_run: run,
        latest_attempt: null,
        latest_result: result,
        earlier_result: null,
    });

    return (
        <details className="border-line bg-sunken rounded-lg border px-3 py-2">
            <summary className="text-fg-muted cursor-pointer text-sm">
                Technical details
            </summary>
            <dl className="mt-2 space-y-1.5">
                <Fact label="Browser worker run">
                    <Badge tone={view.execution.tone}>
                        {view.execution.label}
                    </Badge>
                </Fact>
                {result?.inspection_outcome && (
                    <Fact label="Outcome">
                        <code className="text-xs">
                            {result.inspection_outcome}
                            {result.stop_reason
                                ? ` / ${result.stop_reason}`
                                : ''}
                        </code>
                    </Fact>
                )}
                <Fact label="ATS">
                    {result?.ats_detected ?? 'Not identified'}
                </Fact>
                {!diagnostics.recorded && (
                    <Fact label="Pages and links">
                        <span className="text-fg-subtle">
                            Not recorded for this inspection
                        </span>
                    </Fact>
                )}
                {diagnostics.start_origin && (
                    <Fact label="Started from">
                        {START_ORIGIN[diagnostics.start_origin] ??
                            diagnostics.start_origin}
                    </Fact>
                )}
                {diagnostics.requested_url && (
                    <Fact label="Requested">
                        <span className="text-xs break-all">
                            {diagnostics.requested_url}
                        </span>
                    </Fact>
                )}
                {diagnostics.final_url && (
                    <Fact label="Ended on">
                        <span className="text-xs break-all">
                            {diagnostics.final_url}
                        </span>
                    </Fact>
                )}
                {diagnostics.page_title && (
                    <Fact label="Page title">
                        <span className="text-xs break-words">
                            {diagnostics.page_title}
                        </span>
                    </Fact>
                )}
                {diagnostics.http_status !== null && (
                    <Fact label="HTTP status">{diagnostics.http_status}</Fact>
                )}
                {diagnostics.total_ms !== null && (
                    <Fact label="Took">
                        {(diagnostics.total_ms / 1000).toFixed(1)}s
                    </Fact>
                )}
            </dl>
            {diagnostics.navigation.length > 0 && (
                <ol className="text-fg-subtle mt-2 space-y-0.5 text-xs">
                    {diagnostics.navigation.map((step, index) => (
                        <li key={index} className="break-all">
                            {index + 1}. {step.kind}
                            {step.status !== null
                                ? ` (${step.status})`
                                : ''}{' '}
                            {step.url}
                        </li>
                    ))}
                </ol>
            )}
            {diagnostics.warnings.length > 0 && (
                <ul className="text-warn mt-2 space-y-0.5 text-xs">
                    {diagnostics.warnings.map((warning, index) => (
                        <li key={index} className="break-words">
                            ⚠ {warning}
                        </li>
                    ))}
                </ul>
            )}
            {diagnostics.failure_message && (
                <p className="text-fg-subtle mt-2 text-xs break-words">
                    {diagnostics.failure_message}
                </p>
            )}
        </details>
    );
}

const START_ORIGIN: Record<string, string> = {
    user: 'The link you entered',
    resolved: 'The link an earlier inspection found',
    provider: 'The job source’s application link',
    source: 'The job listing',
};

function QuestionList({ result }: { result: InspectionResult }) {
    return (
        <ul className="divide-line border-line bg-surface divide-y rounded-lg border">
            {result.questions.map((question) => (
                <Question key={question.position} question={question} />
            ))}
        </ul>
    );
}

/** The person's own application link: enter, replace, or go back to the listing. */
function ApplicationLinkForm({
    job,
    starter,
    saved,
    prominent,
    disabled,
}: {
    job: InspectionJob;
    starter: InspectionStarter;
    saved: ApplicationInspectionState['application_url'];
    prominent: boolean;
    disabled: boolean;
}) {
    const [url, setUrl] = useState('');
    const [problem, setProblem] = useState<string | null>(null);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setProblem(null);

        if (url.trim() === '') {
            setProblem('Enter the application link first.');

            return;
        }

        starter.start(job.id, {
            applicationUrl: url.trim(),
            onInvalid: setProblem,
        });
    };

    return (
        <details open={prominent} className="space-y-2">
            <summary className="text-fg-muted cursor-pointer text-sm">
                {saved?.origin === 'user'
                    ? 'Use a different application link'
                    : 'Enter the application link yourself'}
            </summary>
            <form onSubmit={submit} className="mt-2 space-y-2" noValidate>
                <label
                    htmlFor="application-link"
                    className="text-fg-muted block text-xs"
                >
                    The page you would apply through. It is inspected straight
                    away and kept for next time.
                </label>
                <div className="flex flex-wrap gap-2">
                    <input
                        id="application-link"
                        type="url"
                        inputMode="url"
                        value={url}
                        onChange={(event) => setUrl(event.target.value)}
                        placeholder="https://…"
                        aria-invalid={problem !== null}
                        aria-describedby={
                            problem !== null
                                ? 'application-link-error'
                                : undefined
                        }
                        className="border-line bg-surface text-fg focus-visible:ring-focus min-w-0 flex-1 basis-64 rounded-md border px-2.5 py-1.5 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    />
                    <Button
                        type="submit"
                        variant="secondary"
                        disabled={disabled || starter.starting}
                    >
                        Inspect this link
                    </Button>
                </div>
                {problem !== null && (
                    <p
                        id="application-link-error"
                        role="alert"
                        className="text-bad text-sm"
                    >
                        {problem}
                    </p>
                )}
            </form>
        </details>
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

                {view.outcome && view.outcome.explanation && (
                    <p
                        className={
                            result?.inspection_outcome === 'complete'
                                ? 'sr-only'
                                : 'text-fg-muted text-sm leading-relaxed'
                        }
                    >
                        {view.outcomeIsEarlier ? 'Earlier run: ' : ''}
                        {view.outcome.explanation}
                    </p>
                )}

                {result &&
                    result.warnings
                        .filter((warning) => warning in WARNING_TEXT)
                        .map((warning) => (
                            <p
                                key={warning}
                                className="text-warn text-xs leading-relaxed"
                            >
                                {WARNING_TEXT[warning]}
                            </p>
                        ))}

                {result?.stop_reason === 'multiple_apply_links' &&
                    result.diagnostics.candidates.length > 0 && (
                        <ul className="text-fg-muted space-y-0.5 text-xs">
                            {result.diagnostics.candidates.map(
                                (candidate, index) => (
                                    <li key={index} className="break-all">
                                        {candidate.text
                                            ? `“${candidate.text}” — `
                                            : ''}
                                        {candidate.url}
                                    </li>
                                ),
                            )}
                        </ul>
                    )}

                <dl className="border-line bg-sunken space-y-1.5 rounded-lg border px-3 py-2.5">
                    {link?.url && (
                        <Fact label="Application link">
                            <span className="text-xs break-all">
                                {link.url}
                            </span>
                            <span className="text-fg-subtle ml-1.5 text-xs">
                                {link.origin === 'user'
                                    ? '(you entered this)'
                                    : '(found by an inspection)'}
                            </span>
                        </Fact>
                    )}
                    {result && (
                        <>
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
                            Original posting ↗
                        </ButtonAnchor>
                    )}
                </div>

                <ApplicationLinkForm
                    job={job}
                    starter={starter}
                    saved={link}
                    prominent={view.needsApplicationUrl}
                    disabled={busy}
                />

                {diagnostics !== null && (
                    <TechnicalDetails
                        diagnostics={diagnostics}
                        run={run}
                        result={result}
                    />
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
            {state === 'ready' && job && (
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
