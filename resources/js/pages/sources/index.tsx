import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ProviderDrawer } from '@/components/sources/provider-drawer';
import { ProviderRow } from '@/components/sources/provider-row';
import { RecentActivity } from '@/components/sources/recent-activity';
import { Time } from '@/components/sources/time';
import { Card, SectionTitle } from '@/components/ui/surface';
import AppShell from '@/layouts/app-shell';
import {
    attentionNeeded,
    buildSourcesParams,
    setupNeeded,
} from '@/lib/sources';
import { notify } from '@/lib/toast';
import { index as rejectionsIndex } from '@/routes/discovery/rejections';
import { index as sourcesIndex } from '@/routes/sources';
import type { SourcesProps } from '@/types/sources';

/**
 * Drawer bookkeeping that has to outlive the page component: Back and Forward make Inertia restore the page from history
 * and remount it, which would reset component state and refs. Same approach as the Jobs drawer.
 */
const drawerSession = {
    /** The drawer was seen open, so a later render without it is a close. */
    active: false,
    /** The element that opened it, found again on close (the element itself is replaced by a remount). */
    trigger: null as string | null,
    /** Opened from this page in this session: closing is Back, which keeps the history tidy. */
    openedByPush: false,
};

function SummaryItem({
    label,
    value,
    note,
}: {
    label: string;
    value: ReactNode;
    note?: ReactNode;
}) {
    return (
        <div className="bg-surface px-4 py-3">
            <dt className="text-fg-subtle text-xs">{label}</dt>
            <dd className="text-fg mt-0.5 text-lg font-semibold tabular-nums">
                {value}
            </dd>
            {note && (
                <p className="text-fg-subtle mt-0.5 text-xs break-words">
                    {note}
                </p>
            )}
        </div>
    );
}

export default function SourcesIndex({
    summary,
    providers,
    activity,
    filters,
    selected,
}: SourcesProps) {
    const [pending, setPending] = useState<string | null>(null);
    const request = useRef(0);
    const openId = pending ?? filters.provider;
    const provider = providers.find((p) => p.provider === openId) ?? null;
    // The drawer's own data, only once it is the one for the open provider.
    const detail =
        selected !== null && selected.provider === openId ? selected : null;
    const labels = Object.fromEntries(
        providers.map((p) => [p.provider, p.label]),
    );
    const activityPage = activity.pagination.page;

    const hrefFor = (id: string) =>
        sourcesIndex.url({
            query: buildSourcesParams({ provider: id, page: activityPage }),
        });

    const open = (id: string, trigger: string) => {
        const current = ++request.current;
        drawerSession.trigger = trigger;
        drawerSession.openedByPush = true;
        setPending(id);
        // Only the drawer's props are fetched, so the lists, their page and scroll stay exactly as they were.
        router.get(
            sourcesIndex.url(),
            buildSourcesParams({ provider: id, page: activityPage }),
            {
                only: ['filters', 'selected'],
                preserveState: true,
                preserveScroll: true,
                replace: false,
                onNetworkError: () => {
                    notify.error("Couldn't load that source", {
                        description: 'Check your connection and try again.',
                    });
                },
                onFinish: () => {
                    if (request.current === current) {
                        setPending(null);
                    }
                },
            },
        );
    };

    const close = () => {
        if (pending !== null) {
            request.current++;
            drawerSession.openedByPush = false;
            router.cancelAll();
            setPending(null);

            return;
        }

        if (drawerSession.openedByPush) {
            drawerSession.openedByPush = false;
            window.history.back();

            return;
        }

        // Opened by a direct link or a reload: there is no earlier list entry, so replace the URL.
        router.get(
            sourcesIndex.url(),
            buildSourcesParams({ page: activityPage }),
            {
                only: ['filters', 'selected'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    // After the drawer closes, focus returns to whatever opened it.
    useEffect(() => {
        if (openId !== null) {
            drawerSession.active = true;

            return;
        }

        if (!drawerSession.active) {
            return;
        }

        drawerSession.active = false;
        drawerSession.openedByPush = false;
        const trigger = drawerSession.trigger;

        const timer = window.setTimeout(() => {
            document
                .querySelector<HTMLElement>(
                    trigger?.startsWith('run:')
                        ? `[data-trigger="${trigger}"]`
                        : `a.row-link[data-provider="${trigger?.replace('row:', '')}"]`,
                )
                ?.focus();
        }, 60);

        return () => window.clearTimeout(timer);
    }, [openId]);

    // History paging only changes the drawer's own number and replaces the entry, so Back still closes the drawer and the
    // main list's page is untouched.
    const historyPage = (page: number) =>
        router.get(
            sourcesIndex.url(),
            buildSourcesParams({
                provider: openId,
                page: activityPage,
                history_page: page,
            }),
            {
                only: ['filters', 'selected'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    const activityPageTo = (page: number) =>
        router.get(sourcesIndex.url(), buildSourcesParams({ page }), {
            only: ['activity', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: false,
        });

    const setup = setupNeeded(providers);
    const attention = attentionNeeded(providers, summary);

    return (
        <AppShell>
            <Head title="Sources" />

            <header>
                <h1 className="text-fg text-xl font-semibold tracking-tight sm:text-2xl">
                    Sources
                </h1>
                <p className="text-fg-muted mt-0.5 max-w-2xl text-sm">
                    Where Well Applied looks for jobs, and how each source is
                    doing. What discovery finds lands in your{' '}
                    <Link
                        href="/jobs"
                        className="text-accent-text hover:text-fg underline underline-offset-2"
                    >
                        Jobs
                    </Link>{' '}
                    inbox.
                </p>
            </header>

            <section aria-label="Discovery summary" className="mt-5">
                <Card className="overflow-hidden">
                    <dl className="bg-line grid grid-cols-2 gap-px sm:grid-cols-4">
                        <SummaryItem
                            label="Configured"
                            value={`${summary.configured} of ${summary.total}`}
                            note={
                                setup.length > 0
                                    ? `Needs setup: ${setup.join(', ')}`
                                    : 'All sources are set up'
                            }
                        />
                        <SummaryItem
                            label={`Jobs added, ${summary.window_days} days`}
                            value={summary.jobs_added}
                            note="Discovered postings, from all sources"
                        />
                        <SummaryItem
                            label="Needs attention"
                            value={summary.needs_attention}
                            note={
                                attention.length > 0
                                    ? `Failing, partial or overdue: ${attention.join(', ')}`
                                    : 'No failures or overdue sources'
                            }
                        />
                        <SummaryItem
                            label="Last discovery"
                            value={
                                summary.ever_ran ? (
                                    <span className="text-base">
                                        <Time at={summary.last_run_at} />
                                    </span>
                                ) : (
                                    <span className="text-base">Never run</span>
                                )
                            }
                            note={
                                summary.ever_ran
                                    ? `${summary.runs_in_window} run${summary.runs_in_window === 1 ? '' : 's'} in ${summary.window_days} days`
                                    : undefined
                            }
                        />
                    </dl>
                </Card>
            </section>

            <section aria-label="Discovery providers" className="mt-6">
                <SectionTitle
                    aside={
                        <Link
                            href={rejectionsIndex.url()}
                            className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                        >
                            Filtered candidates
                        </Link>
                    }
                >
                    Providers
                </SectionTitle>
                <Card className="mt-3">
                    <ul className="divide-line divide-y">
                        {providers.map((p) => (
                            <ProviderRow
                                key={p.provider}
                                provider={p}
                                href={hrefFor(p.provider)}
                                onOpen={() =>
                                    open(p.provider, `row:${p.provider}`)
                                }
                            />
                        ))}
                    </ul>
                </Card>
            </section>

            <section aria-label="Recent activity" className="mt-6">
                <SectionTitle>Recent activity</SectionTitle>
                <RecentActivity
                    runs={activity.runs}
                    pagination={activity.pagination}
                    labels={labels}
                    hrefFor={hrefFor}
                    onOpen={open}
                    onPage={activityPageTo}
                />
            </section>

            {provider !== null && (
                <ProviderDrawer
                    key={provider.provider}
                    provider={provider}
                    detail={detail}
                    onClose={close}
                    onHistoryPage={historyPage}
                />
            )}
        </AppShell>
    );
}
