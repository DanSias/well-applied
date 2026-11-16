import type { MouseEvent } from 'react';
import { Pagination } from '@/components/ui/pagination';
import { Card } from '@/components/ui/surface';
import { isPlainClick } from '@/lib/opportunities-list';
import {
    count,
    failureLine,
    formatDuration,
    outcomeChip,
    runMark,
    runTotals,
} from '@/lib/sources';
import type { SourcesPagination, SourceRun } from '@/types/sources';
import { OutcomeIndicator } from './status-icons';
import { Time } from './time';

function RunRow({
    run,
    labels,
    hrefFor,
    onOpen,
}: {
    run: SourceRun;
    labels: Record<string, string>;
    hrefFor: (provider: string) => string;
    onOpen: (provider: string, trigger: string) => void;
}) {
    const mark = runMark(run);
    const totals = runTotals(run);
    const active = run.status === 'running' || run.status === 'queued';
    const duration = formatDuration(run.duration_seconds);
    const problems = run.attempts.filter(
        (a) => a.status !== 'succeeded' && failureLine(a) !== null,
    );

    return (
        <li className="px-4 py-2.5 sm:px-5">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-0.5">
                <p className="text-fg text-sm font-medium">
                    <Time at={run.started_at} />
                    <span className="text-fg-subtle text-xs font-normal">
                        {' '}
                        · run #{run.id}
                    </span>
                </p>
                <p className="flex items-center gap-2 text-xs">
                    <span className="text-fg-subtle">
                        {active
                            ? 'In progress'
                            : run.finished_at === null
                              ? 'No finish time recorded'
                              : duration !== null
                                ? `took ${duration}`
                                : null}
                    </span>
                    <OutcomeIndicator mark={mark} />
                </p>
            </div>

            <div className="mt-1.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <ul
                    className="flex flex-wrap gap-1.5"
                    aria-label="Providers attempted"
                >
                    {run.attempts.map((attempt) => {
                        const outcome = outcomeChip(attempt);
                        // A provider that did fine is just its name; one that did not says how, in words.
                        const quiet =
                            attempt.status === 'succeeded' &&
                            outcome.tone === 'ok';
                        const trigger = `run:${run.id}:${attempt.provider}`;
                        const open = (event: MouseEvent<HTMLAnchorElement>) => {
                            if (isPlainClick(event)) {
                                event.preventDefault();
                                onOpen(attempt.provider, trigger);
                            }
                        };

                        return (
                            <li key={attempt.id}>
                                <a
                                    href={hrefFor(attempt.provider)}
                                    onClick={open}
                                    data-trigger={trigger}
                                    className="border-line text-fg hover:bg-raised focus-visible:ring-focus inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-medium focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {labels[attempt.provider] ??
                                        attempt.provider}
                                    {quiet ? (
                                        <span className="sr-only">
                                            {' '}
                                            {outcome.text}
                                        </span>
                                    ) : (
                                        <span
                                            className={
                                                outcome.tone === 'bad'
                                                    ? 'text-bad font-normal'
                                                    : outcome.tone === 'warn'
                                                      ? 'text-warn font-normal'
                                                      : 'text-fg-muted font-normal'
                                            }
                                        >
                                            · {outcome.text}
                                        </span>
                                    )}
                                </a>
                            </li>
                        );
                    })}
                    {run.attempts.length === 0 && (
                        <li className="text-fg-subtle text-xs">
                            No providers were recorded for this run.
                        </li>
                    )}
                </ul>

                <p className="text-fg-subtle text-xs tabular-nums">
                    Created {count(totals.created)} · updated{' '}
                    {count(totals.updated)}
                </p>
            </div>

            {problems.map((attempt) => (
                <p
                    key={attempt.id}
                    className={
                        attempt.status === 'failed'
                            ? 'text-bad mt-1 line-clamp-2 text-xs break-words'
                            : 'text-warn mt-1 line-clamp-2 text-xs break-words'
                    }
                >
                    {labels[attempt.provider] ?? attempt.provider}
                    {attempt.status === 'partial' ? ' (partial)' : ''}:{' '}
                    {failureLine(attempt)}
                </p>
            ))}
        </li>
    );
}

/** Recent discovery runs across providers, newest first. Paged on the server. */
export function RecentActivity({
    runs,
    pagination,
    labels,
    hrefFor,
    onOpen,
    onPage,
}: {
    runs: SourceRun[];
    pagination: SourcesPagination;
    labels: Record<string, string>;
    hrefFor: (provider: string) => string;
    onOpen: (provider: string, trigger: string) => void;
    onPage: (page: number) => void;
}) {
    if (runs.length === 0) {
        return (
            <Card className="text-fg-muted mt-3 p-6 text-center text-sm">
                Discovery has not run yet. Run{' '}
                <code>php artisan discovery:run</code> or start the scheduler,
                and each run will be listed here.
            </Card>
        );
    }

    return (
        <>
            <Card className="mt-3">
                <ul className="divide-line divide-y">
                    {runs.map((run) => (
                        <RunRow
                            key={run.id}
                            run={run}
                            labels={labels}
                            hrefFor={hrefFor}
                            onOpen={onOpen}
                        />
                    ))}
                </ul>
            </Card>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p className="text-fg-subtle text-xs" role="status">
                    {pagination.from}–{pagination.to} of {pagination.total} runs
                </p>
                <Pagination
                    page={pagination.page}
                    lastPage={pagination.last_page}
                    onPage={onPage}
                />
            </div>
        </>
    );
}
