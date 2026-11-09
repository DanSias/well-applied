import { Head, Link, router } from '@inertiajs/react';
import { ExperienceView } from '@/components/career-data/experience-view';
import { FactsView } from '@/components/career-data/facts-view';
import { SkillsView } from '@/components/career-data/skills-view';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/surface';
import { Tabs, type TabItem } from '@/components/ui/tabs';
import AppShell from '@/layouts/app-shell';
import { index as careerDataIndex } from '@/routes/career-data';
import type { CareerDataIndexProps, CareerDataView } from '@/types/career-data';

export default function CareerDataIndex({
    profile,
    employers,
    education,
    skills,
    view,
    counts,
    facts,
    filters,
    pagination,
    filter_options,
}: CareerDataIndexProps) {
    const openView = (next: CareerDataView) =>
        router.get(
            careerDataIndex.url(),
            next === 'experience' ? {} : { view: next },
            { preserveScroll: false },
        );

    const items: TabItem<CareerDataView>[] = [
        { id: 'experience', label: 'Experience' },
        {
            id: 'facts',
            label: 'Career Facts',
            badge: <Badge>{counts.facts}</Badge>,
        },
        {
            id: 'skills',
            label: 'Skills',
            badge: <Badge>{counts.skills}</Badge>,
        },
    ];

    return (
        <AppShell>
            <Head title="Career Data" />

            {profile === null ? (
                <Alert tone="warn" role="status">
                    <p className="font-medium">No career profile found</p>
                    <p className="text-fg-muted mt-1">
                        Run{' '}
                        <code className="bg-sunken rounded px-1 py-0.5 text-xs">
                            php artisan career:import
                        </code>{' '}
                        to populate the canonical dataset.
                    </p>
                </Alert>
            ) : (
                <>
                    <header>
                        <h1 className="text-fg text-2xl font-semibold tracking-tight">
                            Career Data
                        </h1>
                        <p className="text-fg-muted mt-1 text-sm">
                            {profile.name} · what your approved record says and
                            where it came from.
                        </p>
                        <p className="text-fg-muted mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span>{counts.facts} facts</span>
                            <Link
                                href={careerDataIndex.url({
                                    query: {
                                        view: 'facts',
                                        provenance: 'confirmed',
                                    },
                                })}
                                className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                            >
                                {counts.confirmed} confirmed by you
                            </Link>
                            <span>{counts.employers} employers</span>
                            <span>{counts.skills} skills</span>
                        </p>
                    </header>

                    <div className="mt-6">
                        <Tabs<CareerDataView>
                            label="Career Data views"
                            idPrefix="career-data"
                            value={view}
                            onChange={openView}
                            items={items}
                        />
                    </div>

                    <div
                        role="tabpanel"
                        id="career-data-panel"
                        aria-labelledby={`career-data-${view}`}
                        className="mt-6 max-w-5xl"
                    >
                        {view === 'experience' && (
                            <>
                                {profile.facts.length > 0 && (
                                    <Card className="mb-6 p-5">
                                        <h2 className="text-fg text-sm font-semibold">
                                            Across your whole career
                                        </h2>
                                        <ul className="text-fg-muted mt-3 space-y-2 text-sm leading-relaxed">
                                            {profile.facts
                                                .slice(0, 3)
                                                .map((f) => (
                                                    <li key={f.key}>
                                                        {f.statement}
                                                    </li>
                                                ))}
                                        </ul>
                                        {profile.facts.length > 3 && (
                                            <p className="text-fg-subtle mt-3 text-xs">
                                                and {profile.facts.length - 3}{' '}
                                                more in Career Facts.
                                            </p>
                                        )}
                                    </Card>
                                )}
                                <ExperienceView
                                    employers={employers}
                                    education={education}
                                />
                            </>
                        )}
                        {view === 'facts' &&
                            facts !== null &&
                            pagination !== null && (
                                <FactsView
                                    facts={facts}
                                    filters={filters}
                                    pagination={pagination}
                                    options={filter_options}
                                />
                            )}
                        {view === 'skills' && <SkillsView skills={skills} />}
                    </div>
                </>
            )}
        </AppShell>
    );
}
