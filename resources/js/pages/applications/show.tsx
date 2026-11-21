import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { GenerationStatus } from '@/components/generation-status';
import {
    ApplicationUrlForm,
    InspectionFindings,
    TechnicalDetails,
} from '@/components/inspection-findings';
import { Badge } from '@/components/ui/badge';
import { WaitingForWorkerNotice } from '@/components/worker-status-badge';
import { useApplicationInspectionPolling } from '@/hooks/use-application-inspection-polling';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import {
    describeFailure,
    fromProviderSchema,
    presentInspection,
} from '@/lib/inspection-status';
import AppShell from '@/layouts/app-shell';
import { store as inspectStore } from '@/routes/jobs/applications';
import type {
    ApplicationQuestionView,
    ApplicationShowProps,
} from '@/types/application-inspection';
import { buttonClass } from '@/components/ui/button';

const OPTIONS_COLLAPSE_THRESHOLD = 6;

function OptionsList({ options }: { options: string[] }) {
    if (options.length === 0) {
        return null;
    }

    if (options.length <= OPTIONS_COLLAPSE_THRESHOLD) {
        return (
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                Options: {options.join(', ')}
            </p>
        );
    }

    return (
        <details className="mt-1">
            <summary className="cursor-pointer text-xs text-neutral-500 dark:text-neutral-400">
                {options.length} options
            </summary>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                {options.join(', ')}
            </p>
        </details>
    );
}

function RequiredBadge({
    required,
    schema = false,
}: {
    required: boolean | null;
    schema?: boolean;
}) {
    if (required === null) {
        return (
            <span className="text-xs text-neutral-400 dark:text-neutral-500">
                unknown
            </span>
        );
    }

    return (
        <span
            className={
                required
                    ? 'text-xs font-medium text-orange-600 dark:text-orange-400'
                    : 'text-xs text-neutral-400 dark:text-neutral-500'
            }
        >
            {required ? 'required' : 'optional'}
            {schema ? ' in schema' : ''}
        </span>
    );
}

function QuestionRow({ question }: { question: ApplicationQuestionView }) {
    const unresolved = question.label_source === 'unresolved';
    const schema = fromProviderSchema(question);

    return (
        <li className="px-4 py-3">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <span className="mr-2 text-xs text-neutral-400 dark:text-neutral-500">
                        #{question.position}
                    </span>
                    {unresolved ? (
                        <span className="text-sm text-neutral-400 italic dark:text-neutral-500">
                            {schema
                                ? 'Untitled in the provider schema'
                                : 'Unresolved field'}
                        </span>
                    ) : (
                        <span className="text-sm text-neutral-800 dark:text-neutral-200">
                            {question.raw_label}
                        </span>
                    )}
                    {question.section && (
                        <span className="ml-2 text-xs text-neutral-400 dark:text-neutral-500">
                            ({question.section})
                        </span>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-3">
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">
                        {question.control_type}
                    </span>
                    <RequiredBadge
                        required={question.required}
                        schema={schema}
                    />
                </div>
            </div>
            {question.options && <OptionsList options={question.options} />}
        </li>
    );
}

function InspectApplicationAction({
    jobPostingId,
    sourceUrl,
    inspection,
}: {
    jobPostingId: number;
    sourceUrl: string | null;
    inspection: ApplicationShowProps['inspection'];
}) {
    const form = useForm({});
    const state = useApplicationInspectionPolling(
        inspection.application_id,
        inspection,
    );
    const workerStatus = useWorkerStatusPolling();
    const view = presentInspection(state);
    const run = state.workflow_run;
    const result = state.latest_result;
    const attempt = state.latest_attempt;
    const link = state.application_url;
    const diagnostics = (result ?? attempt)?.diagnostics ?? null;
    const [sendingUrl, setSendingUrl] = useState(false);
    const isActive = view.active;
    const disabled = form.processing || sendingUrl || isActive;
    const failure =
        run?.status === 'failed'
            ? describeFailure(
                  attempt?.failure_category ?? null,
                  run.failure_message,
              )
            : null;

    // The existing override: the same request the drawer makes. The server validates the URL, saves it as the person's own
    // and inspects it; a refusal comes back as an error on `application_url`.
    const submitUrl = (
        url: string,
        reject: (message: string) => void,
    ): void => {
        setSendingUrl(true);
        router.post(
            inspectStore.url({ jobPosting: jobPostingId }),
            { application_url: url },
            {
                showProgress: false,
                preserveScroll: true,
                onError: (errors) =>
                    reject(
                        errors.application_url ??
                            "Couldn't start the inspection. Nothing was queued.",
                    ),
                onFinish: () => setSendingUrl(false),
            },
        );
    };

    return (
        <div>
            <div className="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() =>
                        form.post(
                            inspectStore.url({ jobPosting: jobPostingId }),
                            {
                                showProgress: false,
                            },
                        )
                    }
                    className={buttonClass('primary', 'md')}
                >
                    {isActive ? 'Inspecting…' : view.action}
                </button>
                {run && (
                    <Badge tone={view.headline.tone}>
                        {view.headline.label}
                    </Badge>
                )}
            </div>

            <GenerationStatus
                active={isActive}
                label="Inspecting application"
                queued={run?.status === 'pending'}
                queuedLabel="Inspection queued…"
                startedAt={run?.started_at}
            />

            {failure !== null && (
                <p role="alert" className="text-bad mt-2 text-sm break-words">
                    {failure}
                </p>
            )}

            {isActive && <WaitingForWorkerNotice status={workerStatus} />}

            <div className="mt-4 space-y-4">
                {result && (
                    <InspectionFindings
                        view={view}
                        result={result}
                        attempt={attempt}
                        link={link}
                    />
                )}

                {result && result.questions.length > 0 && (
                    <ul className="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                        {result.questions.map((question) => (
                            <QuestionRow
                                key={question.position}
                                question={question}
                            />
                        ))}
                    </ul>
                )}

                {(sourceUrl !== null || link?.url) && result && (
                    <ApplicationUrlForm
                        saved={link}
                        prominent={view.needsApplicationUrl}
                        disabled={disabled}
                        onSubmit={submitUrl}
                    />
                )}

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

export default function ApplicationShow({
    application,
    inspection,
}: ApplicationShowProps) {
    return (
        <AppShell>
            <Head
                title={`Application — ${application.job_posting.title} at ${application.job_posting.company}`}
            />

            <Link
                href={application.job_posting.home_url}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to job
            </Link>

            <div className="mt-4">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {application.job_posting.title}
                </h1>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    {application.job_posting.company}
                </p>
            </div>

            <div className="mt-6">
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    Application Inspection
                </h2>
                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                    Read-only — extracts the application's fields and questions
                    without filling or submitting anything.
                </p>
                <div className="mt-3">
                    <InspectApplicationAction
                        jobPostingId={application.job_posting.id}
                        sourceUrl={application.job_posting.source_url}
                        inspection={inspection}
                    />
                </div>
            </div>
        </AppShell>
    );
}
