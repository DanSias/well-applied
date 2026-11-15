import type { MouseEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Pagination } from '@/components/ui/pagination';
import { Card } from '@/components/ui/surface';
import { isPlainClick } from '@/lib/opportunities-list';
import {
    count,
    failureLine,
    formatDuration,
    outcomeChip,
    runTotals,
    statusChip,
} from '@/lib/sources';
import type { SourcesPagination, SourceRun } from '@/types/sources';
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
    const chip = statusChip(run.status);
    const totals = runTotals(run);
    const active = run.status === 'running' || run.status === 'queued';
    const duration = formatDuration(run.duration_seconds);
    const problems = run.attempts.filter(
        (a) => a.status === 'failed' || a.status === 'partial',
    );

    return (
        <li className="px-5 py-3.5">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <p className="text-fg text-sm font-medium">
                    <Time at={run.started_at} />
                    <span className="text-fg-subtle text-xs font-normal">
                        {' '}
                        · run #{run.id}
                    </span>
                </p>
                <p className="flex items-center gap-2 text-xs">
                    <span className="text-fg-subtle">
                        {active ? (
                            'In progress'
                        ) : run.finished_at !== null ? (
                            <>
                                Finished <Time at={run.finished_at} />
                                {duration !== null && ` · took ${duration}`}
                            </>
                        ) : (
                            'No finish time recorded'
                        )}
                    </span>
                    <Badge tone={chip.tone}>{chip.text}</Badge>
                </p>
            </div>

            <ul
                className="mt-2 flex flex-wrap gap-1.5"
                aria-label="Providers attempted"
            >
                {run.attempts.map((attempt) => {
                    const outcome = outcomeChip(attempt);
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
                                className="border-line text-fg-muted hover:bg-raised hover:text-fg focus-visible:ring-focus inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <span className="text-fg font-medium">
                                    {labels[attempt.provider] ??
                                        attempt.provider}
                                </span>
                                <span>{outcome.text}</span>
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

            <p className="text-fg-subtle mt-2 text-xs tabular-nums">
                Created {count(totals.created)} · updated{' '}
                {count(totals.updated)}
            </p>

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
                    {failureLine(attempt) ?? 'no detail recorded'}
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
