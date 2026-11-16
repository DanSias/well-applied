import type { MouseEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { isPlainClick } from '@/lib/opportunities-list';
import {
    count,
    failureLine,
    providerStatus,
    retrievedLabel,
} from '@/lib/sources';
import type { SourceProvider } from '@/types/sources';
import { ChevronRightIcon, OutcomeIndicator } from './status-icons';
import { Time } from './time';

/** A figure with its label beneath it. The value comes first on screen; the label stays first in the markup. */
function Metric({ label, value }: { label: string; value: number | null }) {
    return (
        <div className="flex flex-col-reverse">
            <dt className="text-fg-subtle text-xs">{label}</dt>
            <dd className="text-fg text-base leading-tight font-semibold tabular-nums">
                {count(value)}
                {value === null && (
                    <span className="sr-only"> not recorded</span>
                )}
            </dd>
        </div>
    );
}

/**
 * One provider on the list, written to be scanned: the name (which opens the drawer) with how it is scheduled and how it
 * last went, when it last ran, and four figures. A healthy provider is a quiet tick and a schedule word; a provider that
 * needs setup, has paused, or did not finish well says so in words. Only the name opens the drawer, as on the Jobs list.
 */
export function ProviderRow({
    provider,
    href,
    onOpen,
}: {
    provider: SourceProvider;
    href: string;
    onOpen: () => void;
}) {
    const status = providerStatus(provider);
    const attempt = provider.last_attempt;
    // Show the last success separately only when the last attempt was not it.
    const lastSuccess =
        provider.last_success !== null &&
        provider.last_success.id !== attempt?.id
            ? provider.last_success
            : null;

    const open = (event: MouseEvent<HTMLAnchorElement>) => {
        if (isPlainClick(event)) {
            event.preventDefault();
            onOpen();
        }
    };

    return (
        <li className="px-4 py-3 sm:px-5">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <a
                    href={href}
                    onClick={open}
                    data-provider={provider.provider}
                    className="row-link text-fg focus-visible:ring-focus group -mx-1 inline-flex items-center gap-1 rounded-sm px-1 text-sm font-semibold focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span className="group-hover:underline">
                        {provider.label}
                    </span>
                    <span className="sr-only"> details</span>
                    <span className="text-fg-subtle group-hover:text-fg">
                        <ChevronRightIcon />
                    </span>
                </a>
                <p className="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                    {status.needsSetup ? (
                        <Badge tone="warn">Needs setup</Badge>
                    ) : status.schedule !== null &&
                      status.schedule.tone === 'warn' ? (
                        <Badge tone="warn">{status.schedule.text}</Badge>
                    ) : (
                        <span className="text-fg-muted text-xs">
                            {status.schedule?.text}
                        </span>
                    )}
                    {status.overdue && <Badge tone="warn">Overdue</Badge>}
                    <OutcomeIndicator mark={status.outcome} />
                </p>
            </div>

            <p className="text-fg-subtle mt-0.5 text-xs">
                {attempt === null ? (
                    status.needsSetup ? (
                        (provider.unavailable_reason ?? 'Not configured.')
                    ) : (
                        'Has not run yet.'
                    )
                ) : (
                    <>
                        {attempt.status === 'queued'
                            ? 'Requested'
                            : attempt.status === 'running'
                              ? 'Started'
                              : 'Last run'}{' '}
                        <Time at={attempt.finished_at ?? attempt.started_at} />
                        {lastSuccess !== null && (
                            <>
                                {' '}
                                · last{' '}
                                {lastSuccess.status === 'partial'
                                    ? 'completed (partial)'
                                    : 'succeeded'}{' '}
                                <Time
                                    at={
                                        lastSuccess.finished_at ??
                                        lastSuccess.started_at
                                    }
                                />
                            </>
                        )}
                        {status.needsSetup && provider.unavailable_reason && (
                            <> · {provider.unavailable_reason}</>
                        )}
                    </>
                )}
            </p>

            {attempt !== null && (
                <dl
                    className="mt-2.5 grid grid-cols-4 gap-x-3 sm:gap-x-4"
                    aria-label={`${provider.label} latest run counts`}
                >
                    <Metric
                        label={retrievedLabel(attempt)}
                        value={attempt.retrieved}
                    />
                    <Metric label="Accepted" value={attempt.accepted} />
                    <Metric label="Created" value={attempt.created} />
                    <Metric label="Filtered" value={attempt.filtered} />
                </dl>
            )}

            {attempt !== null &&
                failureLine(attempt) &&
                attempt.status !== 'succeeded' && (
                    <p
                        className={
                            attempt.status === 'failed'
                                ? 'text-bad mt-2 line-clamp-2 text-xs break-words'
                                : 'text-warn mt-2 line-clamp-2 text-xs break-words'
                        }
                    >
                        {failureLine(attempt)}
                    </p>
                )}
        </li>
    );
}
