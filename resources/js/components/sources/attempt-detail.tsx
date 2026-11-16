import { Badge } from '@/components/ui/badge';
import {
    count,
    diagnosticRows,
    formatDuration,
    outcomeChip,
    retrievedLabel,
    systemFailureExplanation,
} from '@/lib/sources';
import type { SourceAttempt } from '@/types/sources';
import { Time } from './time';

function Counter({ label, value }: { label: string; value: number | null }) {
    return (
        <div>
            <dt className="text-fg-subtle text-xs">{label}</dt>
            <dd className="text-fg text-sm font-medium tabular-nums">
                {count(value)}
                {value === null && (
                    <span className="sr-only"> not recorded</span>
                )}
            </dd>
        </div>
    );
}

/** One attempt in full: counters, why candidates were filtered, what the provider reported, and how it failed. */
export function AttemptDetail({ attempt }: { attempt: SourceAttempt }) {
    const chip = outcomeChip(attempt);
    const duration = formatDuration(attempt.duration_seconds);
    const diagnostics = diagnosticRows(attempt.retrieval_diagnostics);

    return (
        <div>
            <p className="text-fg-muted flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                <Badge tone={chip.tone}>{chip.text}</Badge>
                <span>
                    Started <Time at={attempt.started_at} />
                </span>
                {attempt.finished_at !== null && (
                    <span>
                        · finished <Time at={attempt.finished_at} />
                    </span>
                )}
                {duration !== null && <span>· took {duration}</span>}
            </p>

            <dl className="mt-3 grid grid-cols-3 gap-x-4 gap-y-3 sm:grid-cols-6">
                <Counter
                    label={retrievedLabel(attempt)}
                    value={attempt.retrieved}
                />
                <Counter label="Accepted" value={attempt.accepted} />
                <Counter label="Created" value={attempt.created} />
                <Counter label="Updated" value={attempt.updated} />
                <Counter label="Canonicalized" value={attempt.canonicalized} />
                <Counter label="Filtered" value={attempt.filtered} />
            </dl>

            {attempt.retrieved_basis === 'after_prefilter' && (
                <p className="text-fg-subtle mt-2 text-xs">
                    Eightfold counts what survived its own prefilter, so it is
                    not comparable with the raw feed counts of other sources.
                </p>
            )}

            {attempt.status === 'succeeded' && attempt.retrieved === 0 && (
                <p className="text-fg-subtle mt-2 text-xs">
                    The source answered and had nothing to offer. That is a
                    successful run, not a failure.
                </p>
            )}

            {attempt.filtered_by_reason.length > 0 && (
                <div className="mt-3">
                    <p className="text-fg-subtle text-xs font-medium">
                        Filtered out, by reason
                    </p>
                    <ul className="text-fg-muted mt-1 space-y-0.5 text-xs">
                        {attempt.filtered_by_reason.map((reason) => (
                            <li
                                key={reason.code}
                                className="flex justify-between gap-4"
                            >
                                <span>{reason.label}</span>
                                <span className="tabular-nums">
                                    {reason.count}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {diagnostics.length > 0 && (
                <div className="mt-3">
                    <p className="text-fg-subtle text-xs font-medium">
                        Reported by the provider
                    </p>
                    <ul className="text-fg-muted mt-1 space-y-0.5 text-xs">
                        {diagnostics.map((row) => (
                            <li
                                key={row.label}
                                className="flex justify-between gap-4"
                            >
                                <span>{row.label}</span>
                                <span className="tabular-nums">
                                    {row.value}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {attempt.failure !== null && (
                <div
                    className={
                        attempt.status === 'failed'
                            ? 'text-bad mt-3 text-xs break-words'
                            : 'text-warn mt-3 text-xs break-words'
                    }
                >
                    <p className="font-medium">
                        {attempt.status === 'failed'
                            ? 'Failure'
                            : 'Partial outcome'}
                        {attempt.failure.category !== null &&
                            ` · ${attempt.failure.category.replace(/_/g, ' ')}`}
                    </p>
                    {systemFailureExplanation(attempt.failure.category) !==
                    null ? (
                        <>
                            <p className="mt-0.5">
                                {systemFailureExplanation(
                                    attempt.failure.category,
                                )}
                            </p>
                            {attempt.failure.message !== null && (
                                <p className="text-fg-subtle mt-0.5">
                                    Recorded: {attempt.failure.message}
                                </p>
                            )}
                        </>
                    ) : (
                        attempt.failure.message !== null && (
                            <p className="mt-0.5">{attempt.failure.message}</p>
                        )
                    )}
                    {attempt.failure.exception_class !== null && (
                        <p className="text-fg-subtle mt-0.5">
                            {attempt.failure.exception_class}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
