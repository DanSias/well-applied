import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ProfileTab } from '@/components/profile-synthesis';
import { HistoryTab } from '@/components/resume-profile/history';
import { Interview } from '@/components/resume-profile/interview';
import { MarketTab } from '@/components/resume-profile/market';
import {
    OverviewTab,
    type ProfileTabId,
} from '@/components/resume-profile/overview';
import {
    profileStatus,
    STATE_LABEL,
    statusInputFromPage,
} from '@/components/resume-profile/status';
import { Badge } from '@/components/ui/badge';
import { Tabs, type TabItem } from '@/components/ui/tabs';
import AppShell from '@/layouts/app-shell';
import { index } from '@/routes/resume-profiles';
import type { ResumeProfilePage } from '@/types/resume-profile';

const TAB_IDS: ProfileTabId[] = [
    'overview',
    'profile',
    'interview',
    'market',
    'history',
];

/** `?tab=` makes a tab linkable and survives a reload; anything else is the Overview. */
function tabFromUrl(): ProfileTabId {
    if (typeof window === 'undefined') {
        return 'overview';
    }

    const wanted = new URLSearchParams(window.location.search).get('tab');

    return TAB_IDS.find((t) => t === wanted) ?? 'overview';
}

export default function ResumeProfileShow({
    resume_profile: page,
}: {
    resume_profile: ResumeProfilePage;
}) {
    const [tab, setTab] = useState<ProfileTabId>(tabFromUrl);
    const status = profileStatus(statusInputFromPage(page));
    const polling =
        page.build.state === 'building' ||
        page.interview.enrichment.running ||
        page.interview.answers.some((a) => a.expansion.state === 'running');

    // While the study runs on the queue, refresh quietly until it finishes.
    useEffect(() => {
        if (!polling) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['resume_profile'] }),
            4000,
        );

        return () => window.clearInterval(timer);
    }, [polling]);

    const openTab = (next: ProfileTabId) => {
        setTab(next);
        const url = new URL(window.location.href);

        if (next === 'overview') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', next);
        }

        window.history.replaceState(window.history.state, '', url);
        window.scrollTo({ top: 0 });
    };

    const waitingReview =
        page.consolidation.counts.proposed +
        page.interview.answers.filter((a) => a.proposal?.status === 'proposed')
            .length;
    const shown = page.synthesis.synthesis;

    const items: TabItem<ProfileTabId>[] = [
        { id: 'overview', label: 'Overview' },
        {
            id: 'profile',
            label: 'Profile',
            badge:
                status.freshness === 'stale' ? (
                    <Badge tone="warn">Refresh</Badge>
                ) : shown?.status === 'draft' ? (
                    <Badge tone="accent">Draft</Badge>
                ) : undefined,
        },
        {
            id: 'interview',
            label: 'Interview',
            badge:
                page.interview.progress.open > 0 ? (
                    <Badge>{page.interview.progress.open} open</Badge>
                ) : undefined,
        },
        { id: 'market', label: 'Market' },
        {
            id: 'history',
            label: 'History & Review',
            badge:
                waitingReview > 0 ? (
                    <Badge tone="warn">{waitingReview}</Badge>
                ) : undefined,
        },
    ];

    return (
        <AppShell>
            <Head title={page.profile.name} />
            <Link
                href={index.url()}
                className="rounded-control text-fg-muted hover:text-fg inline-flex items-center gap-1 text-sm"
            >
                <span aria-hidden="true">←</span> Resume Profiles
            </Link>

            <header className="mt-4">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-fg text-2xl font-semibold tracking-tight">
                        {page.profile.name}
                    </h1>
                    <Badge
                        tone={
                            status.state === 'approved'
                                ? 'ok'
                                : status.state === 'draft'
                                  ? 'accent'
                                  : 'neutral'
                        }
                    >
                        {STATE_LABEL[status.state]}
                    </Badge>
                </div>
                {page.profile.purpose && (
                    <p className="text-fg-muted mt-1 max-w-3xl text-sm leading-relaxed">
                        {page.profile.purpose}
                    </p>
                )}
            </header>

            <div className="mt-6">
                <Tabs<ProfileTabId>
                    label="Resume profile sections"
                    idPrefix="profile"
                    value={tab}
                    onChange={openTab}
                    items={items}
                />
            </div>

            <div
                role="tabpanel"
                id="profile-panel"
                aria-labelledby={`profile-${tab}`}
                className="mt-6 max-w-5xl"
            >
                {tab === 'overview' && (
                    <OverviewTab
                        page={page}
                        status={status}
                        onOpenTab={openTab}
                    />
                )}
                {tab === 'profile' && (
                    <ProfileTab
                        state={page.synthesis}
                        slug={page.profile.slug}
                    />
                )}
                {tab === 'interview' && <Interview page={page} />}
                {tab === 'market' && <MarketTab page={page} />}
                {tab === 'history' && <HistoryTab page={page} />}
            </div>
        </AppShell>
    );
}
