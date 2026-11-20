import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    describeLinkOrigin,
    describeStopReason,
    groupWarnings,
    highlightedWarnings,
    START_ORIGIN,
} from '@/lib/inspection-status';
import type { InspectionPresentation } from '@/lib/inspection-status';
import type {
    ApplicationUrlView,
    InspectionAttempt,
    InspectionDiagnostics,
    InspectionResult,
    InspectionWorkflowRun,
} from '@/types/application-inspection';

/**
 * The parts of an inspection result that the Opportunities drawer and the full application page share, so the two can
 * never word the same result differently: the plain-language finding, how the inspector got there, the technical
 * evidence (collapsed, and secondary), and the way to supply the application URL yourself.
 */

export function Fact({
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
 * What the inspection found and how it got there, in words: the explanation of the outcome, what an Apply button means,
 * where it started, what application URL (if any) is known and where that came from, and why it stopped. The raw codes
 * live in `TechnicalDetails`.
 */
export function InspectionFindings({
    view,
    result,
    attempt,
    link,
}: {
    view: InspectionPresentation;
    result: InspectionResult | null;
    attempt: InspectionAttempt | null;
    link: ApplicationUrlView | null;
}) {
    const diagnostics = (result ?? attempt)?.diagnostics ?? null;
    const stop = describeStopReason(result?.stop_reason ?? null);
    const highlighted = highlightedWarnings(result?.warnings ?? []);
    const startOrigin = diagnostics?.start_origin ?? null;
    // The application URL this inspection knows of: the saved one (with who set it), else one this very run resolved.
    const foundUrl = link?.url ?? diagnostics?.resolved_url ?? null;
    const foundBy = link?.url
        ? describeLinkOrigin(link.origin)
        : 'found by this inspection';

    return (
        <>
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

            {highlighted.map(({ code, text }) => (
                <p key={code} className="text-warn text-xs leading-relaxed">
                    {text}
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
                {diagnostics?.recorded && diagnostics.requested_url && (
                    <Fact label="Started from">
                        {startOrigin !== null && (
                            <span className="text-fg-subtle mr-1.5 text-xs">
                                {START_ORIGIN[startOrigin] ?? startOrigin}
                            </span>
                        )}
                        <span className="text-xs break-all">
                            {diagnostics.requested_url}
                        </span>
                    </Fact>
                )}
                {result && (
                    <Fact label="Application URL">
                        {foundUrl !== null ? (
                            <>
                                <span className="text-xs break-all">
                                    {foundUrl}
                                </span>
                                <span className="text-fg-subtle ml-1.5 text-xs">
                                    ({foundBy})
                                </span>
                            </>
                        ) : (
                            <span className="text-fg-subtle">
                                None yet
                                {view.needsApplicationUrl
                                    ? ': the application form was not reached'
                                    : ''}
                            </span>
                        )}
                    </Fact>
                )}
                {stop !== null && <Fact label="Why it stopped">{stop}</Fact>}
                {result && (
                    <>
                        <Fact label="Fields and questions">
                            {result.field_count}
                            {result.unresolved_count > 0 &&
                                ` (${result.unresolved_count} unresolved)`}
                        </Fact>
                        {result.finished_at && (
                            <Fact label="Last inspected">
                                {new Date(result.finished_at).toLocaleString()}
                            </Fact>
                        )}
                    </>
                )}
            </dl>
        </>
    );
}

/**
 * The technical evidence for one run, collapsed and secondary: the raw codes, every page visited, the page's HTTP status,
 * and every warning (each distinct code once, with how many times it occurred).
 */
export function TechnicalDetails({
    diagnostics,
    view,
    run,
    result,
}: {
    diagnostics: InspectionDiagnostics;
    view: InspectionPresentation;
    run: InspectionWorkflowRun | null;
    result: InspectionResult | null;
}) {
    const warnings = groupWarnings(diagnostics.warnings);

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
                {run?.started_at && run.finished_at && (
                    <Fact label="Worker run">
                        {new Date(run.started_at).toLocaleTimeString()} to{' '}
                        {new Date(run.finished_at).toLocaleTimeString()}
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
            {warnings.length > 0 && (
                <ul className="text-warn mt-2 space-y-0.5 text-xs">
                    {warnings.map(({ code, count }) => (
                        <li key={code} className="break-words">
                            ⚠ {code}
                            {count > 1 ? ` ×${count}` : ''}
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

/**
 * What submitting the form does, apart from React: an empty field is refused here, without a request; otherwise the trimmed
 * URL is handed over together with the way to show the server's refusal beside the field. Pure, so it can be tested.
 */
export function submitApplicationUrl(
    raw: string,
    onSubmit: (url: string, reject: (message: string) => void) => void,
    setProblem: (message: string | null) => void,
): void {
    setProblem(null);

    if (raw.trim() === '') {
        setProblem('Enter the application URL first.');

        return;
    }

    onSubmit(raw.trim(), setProblem);
}

/**
 * Supplying the application URL yourself. When the inspector could not find the application form, this is the next step, so
 * it is open and prominent; otherwise it stays one quiet click away. It is the existing override (`application_url` on the
 * inspection request): the URL is validated by the server, saved as yours, and inspected straight away. `onSubmit` hands
 * over the trimmed URL and a way to show the server's refusal beside the field.
 */
export function ApplicationUrlForm({
    saved,
    prominent,
    disabled,
    onSubmit,
}: {
    saved: ApplicationUrlView | null;
    prominent: boolean;
    disabled: boolean;
    onSubmit: (url: string, reject: (message: string) => void) => void;
}) {
    const [url, setUrl] = useState('');
    const [problem, setProblem] = useState<string | null>(null);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        submitApplicationUrl(url, onSubmit, setProblem);
    };

    const form = (
        <form onSubmit={submit} className="space-y-2" noValidate>
            <label
                htmlFor="application-url"
                className="text-fg-muted block text-xs"
            >
                The page you would apply through. It is saved as your link and
                inspected straight away. Nothing is filled in or submitted.
            </label>
            <div className="flex flex-wrap gap-2">
                <input
                    id="application-url"
                    type="url"
                    inputMode="url"
                    value={url}
                    onChange={(event) => setUrl(event.target.value)}
                    placeholder="https://…"
                    aria-invalid={problem !== null}
                    aria-describedby={
                        problem !== null ? 'application-url-error' : undefined
                    }
                    className="border-line bg-surface text-fg focus-visible:ring-focus min-w-0 flex-1 basis-64 rounded-md border px-2.5 py-1.5 text-sm focus-visible:ring-2 focus-visible:outline-none"
                />
                <Button
                    type="submit"
                    variant={prominent ? 'primary' : 'secondary'}
                    disabled={disabled}
                >
                    Save and inspect
                </Button>
            </div>
            {problem !== null && (
                <p
                    id="application-url-error"
                    role="alert"
                    className="text-bad text-sm"
                >
                    {problem}
                </p>
            )}
        </form>
    );

    if (prominent) {
        return (
            <section
                aria-labelledby="enter-application-url"
                className="border-warn-line bg-sunken space-y-2 rounded-lg border px-3 py-3"
            >
                <h3
                    id="enter-application-url"
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    Enter application URL
                </h3>
                {form}
            </section>
        );
    }

    return (
        <details className="space-y-2">
            <summary className="text-fg-muted cursor-pointer text-sm">
                {saved?.origin === 'user'
                    ? 'Use a different application URL'
                    : 'Enter application URL'}
            </summary>
            <div className="mt-2">{form}</div>
        </details>
    );
}
