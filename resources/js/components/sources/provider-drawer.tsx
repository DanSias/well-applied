import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button, ButtonLink } from '@/components/ui/button';
import { Disclosure, SectionTitle } from '@/components/ui/surface';
import { Drawer } from '@/components/ui/drawer';
import { Pagination } from '@/components/ui/pagination';
import {
    availabilityChip,
    configurationRows,
    count,
    discoveredJobsHref,
    failureLine,
    filteredCandidatesHref,
    formatDuration,
    outcomeChip,
    scheduleChip,
} from '@/lib/sources';
import type {
    SourceAttempt,
    SourceDetail,
    SourceProvider,
} from '@/types/sources';
import { AttemptDetail } from './attempt-detail';
import { Time } from './time';
import { notify } from '@/lib/toast';
import { store as runNow } from '@/routes/sources/run';
import { update as updateSchedule } from '@/routes/sources/schedule';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section
            aria-label={title}
            className="border-line border-t py-4 first:border-t-0 first:pt-0"
        >
            <SectionTitle>{title}</SectionTitle>
            <div className="mt-3">{children}</div>
        </section>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-x-3 py-1 text-sm">
            <dt className="text-fg-subtle text-xs leading-5">{label}</dt>
            <dd className="text-fg-muted break-words">{children}</dd>
        </div>
    );
}

function attemptSummary(attempt: SourceAttempt | null) {
    if (attempt === null) {
        return 'Never';
    }

    const chip = outcomeChip(attempt);

    return (
        <>
            <Time at={attempt.finished_at ?? attempt.started_at} /> ·{' '}
            {chip.text}
        </>
    );
}

/**
 * Pause, resume or enable scheduling. The server offers the one action that fits the provider's state, so nothing here
 * repeats the scheduling rules. It is a plain PATCH that redirects back, so the open drawer, its history page and the
 * activity page all stay as they are; the result arrives as a toast.
 */
function ScheduleControl({ provider }: { provider: SourceProvider }) {
    const [pending, setPending] = useState(false);
    const control = provider.schedule_control;

    if (control === null) {
        return (
            <span className="text-warn mt-1 block text-xs">
                Needs setup.{' '}
                {provider.unavailable_reason ??
                    'It cannot be scheduled until it is configured.'}
            </span>
        );
    }

    const submit = () => {
        if (pending) {
            return;
        }

        setPending(true);
        router.patch(
            updateSchedule.url(provider.provider),
            { schedule_override: control.override },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    notify.error(
                        `Couldn't change ${provider.label} scheduling`,
                        {
                            description: Object.values(errors)[0],
                        },
                    );
                },
                onFinish: () => setPending(false),
            },
        );
    };

    return (
        <Button
            variant="secondary"
            size="sm"
            className="mt-2"
            disabled={pending}
            aria-busy={pending}
            onClick={submit}
        >
            {control.label}
        </Button>
    );
}

/**
 * Run Now: queue one run of this provider, whatever its schedule says. The button stays focusable while a request or a
 * run is in progress (aria-disabled rather than disabled), so keyboard focus is not lost when it changes state. The
 * page polls while the run is queued or running and the result appears in Latest results; nothing here waits for it.
 */
function RunNowControl({ provider }: { provider: SourceProvider }) {
    const [pending, setPending] = useState(false);
    const run = provider.run_now;

    if (run.active === 'running') {
        return (
            <>
                <RunButton busy label="Running…" />
                <span className="text-fg-subtle mt-1 block text-xs">
                    Started <Time at={run.since} />. The result appears below
                    when it finishes.
                </span>
            </>
        );
    }

    if (run.active === 'queued') {
        return (
            <>
                <RunButton busy label="Queued" />
                <span className="text-fg-subtle mt-1 block text-xs">
                    Waiting for a queue worker to pick it up. A worker has to be
                    running for it to start (php artisan dev starts one).
                </span>
            </>
        );
    }

    if (!run.available) {
        return (
            <span className="text-warn block text-xs">
                Needs setup. {run.unavailable_reason}
            </span>
        );
    }

    const submit = () => {
        if (pending) {
            return;
        }

        setPending(true);
        router.post(
            runNow.url(provider.provider),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    notify.error(`Couldn't run ${provider.label}`, {
                        description: Object.values(errors)[0],
                    });
                },
                onFinish: () => setPending(false),
            },
        );
    };

    return (
        <RunButton
            busy={pending}
            label={pending ? 'Queuing…' : 'Run now'}
            onClick={submit}
        />
    );
}

function RunButton({
    label,
    busy = false,
    onClick,
}: {
    label: string;
    busy?: boolean;
    onClick?: () => void;
}) {
    return (
        <Button
            variant="secondary"
            size="sm"
            aria-disabled={busy}
            aria-busy={busy}
            className={busy ? 'cursor-not-allowed opacity-60' : undefined}
            onClick={() => {
                if (!busy) {
                    onClick?.();
                }
            }}
        >
            {label}
        </Button>
    );
}

const scheduleExplanation = (provider: SourceProvider) => {
    switch (provider.effective_schedule) {
        case 'automatic':
            return 'Checked hourly; runs when due. Only happens while the scheduler process is running.';
        case 'manual_only':
            return (
                'Does not run on a schedule. Start it with php artisan discovery:run --only=' +
                provider.provider
            );
        case 'paused':
            return 'Scheduling is paused. It is not picked up automatically; you can still run it manually. A run already in progress is not stopped.';
        case 'unavailable':
            return 'Cannot run until it is set up.';
    }
};

function HistoryRow({ attempt }: { attempt: SourceAttempt }) {
    const chip = outcomeChip(attempt);
    const duration = formatDuration(attempt.duration_seconds);
    const line = failureLine(attempt);

    return (
        <li className="py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <p className="flex items-center gap-2 text-xs">
                    <Badge tone={chip.tone}>{chip.text}</Badge>
                    <span className="text-fg-muted">
                        <Time at={attempt.started_at} />
                    </span>
                </p>
                <p className="text-fg-subtle text-xs tabular-nums">
                    {duration !== null && <>{duration} · </>}
                    Retrieved {count(attempt.retrieved)} · accepted{' '}
                    {count(attempt.accepted)} · created {count(attempt.created)}{' '}
                    · updated {count(attempt.updated)}
                </p>
            </div>
            {line !== null && (
                <p
                    className={
                        attempt.status === 'failed'
                            ? 'text-bad mt-1 line-clamp-2 text-xs break-words'
                            : 'text-warn mt-1 line-clamp-2 text-xs break-words'
                    }
                >
                    {line}
                </p>
            )}
            <Disclosure summary="Attempt details" quiet className="mt-1.5">
                <AttemptDetail attempt={attempt} />
            </Disclosure>
        </li>
    );
}

/** The provider's detail: overview, latest results, paged run history and read-only configuration. */
export function ProviderDrawer({
    provider,
    detail,
    onClose,
    onHistoryPage,
}: {
    provider: SourceProvider;
    /** Null while the drawer's own data is still loading. */
    detail: SourceDetail | null;
    onClose: () => void;
    onHistoryPage: (page: number) => void;
}) {
    const availability = availabilityChip(provider);
    const schedule = scheduleChip(provider);
    const latest = provider.last_attempt;
    const rows = detail === null ? [] : configurationRows(detail.configuration);
    const config = detail?.configuration as
        | {
              tenants?: {
                  slug: string;
                  company: string | null;
                  host: string | null;
                  enabled: boolean;
              }[];
              budget?: Record<string, number>;
          }
        | undefined;
    // Only worth a second copy when the latest attempt was not the last good one.
    const showLastSuccess =
        provider.last_success !== null &&
        provider.last_success.id !== latest?.id;

    return (
        <Drawer
            title={provider.label}
            subtitle="Discovery source"
            closeLabel={`Close ${provider.label} details`}
            onClose={onClose}
            header={
                <p className="border-line flex flex-wrap items-center gap-1.5 border-b px-5 py-2.5">
                    <Badge tone={availability.tone}>{availability.text}</Badge>
                    <Badge tone={schedule.tone}>{schedule.text}</Badge>
                    <Badge tone={outcomeChip(latest).tone}>
                        {outcomeChip(latest).text}
                    </Badge>
                </p>
            }
            footer={
                <div className="flex flex-wrap items-center gap-2">
                    <ButtonLink
                        variant="secondary"
                        size="sm"
                        href={discoveredJobsHref(provider.provider)}
                    >
                        View discovered jobs
                    </ButtonLink>
                    <ButtonLink
                        variant="secondary"
                        size="sm"
                        href={filteredCandidatesHref(provider.provider)}
                    >
                        View filtered candidates
                    </ButtonLink>
                    <p className="text-fg-subtle basis-full text-xs">
                        Jobs shows every posting this source has found, in all
                        views, not the ones from a single run.
                    </p>
                </div>
            }
        >
            <Section title="Overview">
                <dl>
                    <Fact label="Availability">
                        {provider.configured
                            ? provider.registered
                                ? 'Configured and registered'
                                : 'Configured'
                            : (provider.unavailable_reason ?? 'Not configured')}
                        {!provider.configured && provider.registered && (
                            <span className="text-fg-subtle">
                                {' '}
                                (still registered, so a run will fail until it
                                is set up)
                            </span>
                        )}
                    </Fact>
                    <Fact label="Scheduling">
                        {schedule.text}
                        <span className="text-fg-subtle block text-xs">
                            {scheduleExplanation(provider)}
                        </span>
                        <ScheduleControl provider={provider} />
                        {provider.overdue && (
                            <span className="text-warn block text-xs">
                                Overdue: its last success is more than twice the
                                normal cadence old.
                            </span>
                        )}
                    </Fact>
                    <Fact label="Run">
                        <RunNowControl provider={provider} />
                    </Fact>
                    <Fact label="Last attempt">{attemptSummary(latest)}</Fact>
                    <Fact label="Last success">
                        {attemptSummary(provider.last_success)}
                    </Fact>
                    <Fact label="Last failure">
                        {provider.last_failure === null ? (
                            'None recorded'
                        ) : (
                            <>
                                <Time
                                    at={
                                        provider.last_failure.finished_at ??
                                        provider.last_failure.started_at
                                    }
                                />
                                {failureLine(provider.last_failure) && (
                                    <span className="text-fg-subtle block text-xs">
                                        {failureLine(provider.last_failure)}
                                    </span>
                                )}
                            </>
                        )}
                    </Fact>
                    <Fact label="Jobs added (7 days)">
                        {provider.jobs_added}
                    </Fact>
                </dl>
            </Section>

            <Section title="Latest results">
                {latest === null ? (
                    <p className="text-fg-muted text-sm">
                        This source has not run yet, so there are no results.
                    </p>
                ) : (
                    <>
                        <AttemptDetail attempt={latest} />
                        {showLastSuccess && provider.last_success && (
                            <Disclosure
                                summary="Last successful run"
                                className="mt-4"
                                quiet
                            >
                                <AttemptDetail
                                    attempt={provider.last_success}
                                />
                            </Disclosure>
                        )}
                    </>
                )}
            </Section>

            <Section title="Run history">
                {detail === null ? (
                    <p className="text-fg-muted text-sm" role="status">
                        Loading history…
                    </p>
                ) : detail.history.attempts.length === 0 ? (
                    <p className="text-fg-muted text-sm">
                        No attempts have been recorded for this source.
                    </p>
                ) : (
                    <>
                        <ul className="divide-line divide-y">
                            {detail.history.attempts.map((attempt) => (
                                <HistoryRow
                                    key={attempt.id}
                                    attempt={attempt}
                                />
                            ))}
                        </ul>
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <p className="text-fg-subtle text-xs" role="status">
                                {detail.history.pagination.from}–
                                {detail.history.pagination.to} of{' '}
                                {detail.history.pagination.total}
                            </p>
                            <Pagination
                                page={detail.history.pagination.page}
                                lastPage={detail.history.pagination.last_page}
                                onPage={onHistoryPage}
                            />
                        </div>
                    </>
                )}
            </Section>

            <Section title="Configuration">
                {detail === null ? (
                    <p className="text-fg-muted text-sm" role="status">
                        Loading configuration…
                    </p>
                ) : (
                    <>
                        {rows.length === 0 && !config?.tenants && (
                            <p className="text-fg-muted text-sm">
                                Nothing to configure. This source needs no
                                credentials.
                            </p>
                        )}
                        <dl>
                            {rows.map((row) => (
                                <Fact key={row.label} label={row.label}>
                                    {row.value}
                                </Fact>
                            ))}
                        </dl>
                        {config?.tenants && (
                            <div className="mt-3">
                                <p className="text-fg-subtle text-xs font-medium">
                                    Tenants
                                </p>
                                <ul className="text-fg-muted mt-1 space-y-0.5 text-xs">
                                    {config.tenants.map((tenant) => (
                                        <li
                                            key={tenant.slug}
                                            className="flex flex-wrap justify-between gap-x-4"
                                        >
                                            <span>
                                                {tenant.company ?? tenant.slug}
                                                {tenant.host && (
                                                    <span className="text-fg-subtle">
                                                        {' '}
                                                        · {tenant.host}
                                                    </span>
                                                )}
                                            </span>
                                            <span>
                                                {tenant.enabled
                                                    ? 'Enabled'
                                                    : 'Disabled'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-fg-subtle mt-2 text-xs">
                                    Only totals across tenants are recorded for
                                    each run; there is no per-tenant history.
                                </p>
                            </div>
                        )}
                        {config?.budget && (
                            <Disclosure
                                summary="Request limits per tenant"
                                quiet
                                className="mt-3"
                            >
                                <ul className="text-fg-muted space-y-0.5 text-xs">
                                    {Object.entries(config.budget).map(
                                        ([key, value]) => (
                                            <li
                                                key={key}
                                                className="flex justify-between gap-4"
                                            >
                                                <span>
                                                    {key.replace(/_/g, ' ')}
                                                </span>
                                                <span className="tabular-nums">
                                                    {value}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </Disclosure>
                        )}
                    </>
                )}
            </Section>
        </Drawer>
    );
}
