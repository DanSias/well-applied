import type { MouseEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { isPlainClick } from '@/lib/opportunities-list';
import {
    availabilityChip,
    count,
    failureLine,
    providerOutcomeChip,
    retrievedLabel,
    scheduleChip,
} from '@/lib/sources';
import type { SourceProvider } from '@/types/sources';
import { Time } from './time';

function Stat({ label, value }: { label: string; value: number | null }) {
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

/**
 * One provider on the list. Three separate questions get three separate chips: is it set up (availability), when does it
 * run (schedule) and how did its last run go (outcome). Only the name opens the drawer, as on the Jobs list.
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
    const availability = availabilityChip(provider);
    const schedule = scheduleChip(provider);
    const attempt = provider.last_attempt;
    const outcome = providerOutcomeChip(provider);
    const overdue = provider.overdue;
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
        <li className="px-5 py-3.5">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <a
                    href={href}
                    onClick={open}
                    data-provider={provider.provider}
                    className="row-link text-fg focus-visible:ring-focus rounded-sm text-sm font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none"
                >
                    {provider.label}
                    <span className="sr-only"> details</span>
                </a>
                <p className="flex flex-wrap items-center gap-1.5">
                    <Badge tone={availability.tone}>{availability.text}</Badge>
                    <Badge tone={schedule.tone}>{schedule.text}</Badge>
                    <Badge tone={outcome.tone}>{outcome.text}</Badge>
                    {overdue && <Badge tone="warn">Overdue</Badge>}
                </p>
            </div>

            <p className="text-fg-subtle mt-1.5 text-xs">
                {attempt === null ? (
                    provider.configured ? (
                        'Has not run yet.'
                    ) : (
                        (provider.unavailable_reason ?? 'Not configured.')
                    )
                ) : (
                    <>
                        Last attempt{' '}
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
                        {!provider.configured &&
                            provider.unavailable_reason && (
                                <> · {provider.unavailable_reason}</>
                            )}
                    </>
                )}
            </p>

            {attempt !== null && (
                <dl
                    className="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-5"
                    aria-label={`${provider.label} latest attempt counts`}
                >
                    <Stat
                        label={retrievedLabel(attempt)}
                        value={attempt.retrieved}
                    />
                    <Stat label="Accepted" value={attempt.accepted} />
                    <Stat label="Created" value={attempt.created} />
                    <Stat label="Updated" value={attempt.updated} />
                    <Stat label="Filtered" value={attempt.filtered} />
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
