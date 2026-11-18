import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { skillCategoryLabel } from '@/components/badges';
import { TextInput } from '@/components/ui/field';
import { SegmentedControl } from '@/components/ui/segmented';
import { Card, Disclosure } from '@/components/ui/surface';
import { index as careerDataIndex } from '@/routes/career-data';
import type { SkillCategory, SkillSummary } from '@/types/career-data';

const CATEGORIES: SkillCategory[] = [
    'build_technology',
    'platform_integration',
    'capability',
    'practice',
];

function factsLink(query: Record<string, string>) {
    return careerDataIndex.url({ query: { view: 'facts', ...query } });
}

function SkillRow({ skill }: { skill: SkillSummary }) {
    const facts = (
        <Link
            href={factsLink({ skill: skill.slug })}
            className="text-accent-text hover:text-fg rounded-sm text-xs underline underline-offset-2"
        >
            {skill.career_fact_count}{' '}
            {skill.career_fact_count === 1 ? 'fact' : 'facts'}
        </Link>
    );

    // Projects are derived from the facts a skill is tagged on, so a skill with
    // none is simply documented at role or career level: say nothing about projects.
    if (skill.project_count === 0) {
        return (
            <li className="flex items-center justify-between gap-3 py-2.5 pr-4 pl-[2.375rem]">
                <span className="text-fg text-sm">{skill.name}</span>
                {facts}
            </li>
        );
    }

    return (
        <li className="px-4 py-2.5">
            <Disclosure
                summary={
                    <span className="flex w-full flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="text-fg text-sm font-normal">
                            {skill.name}
                        </span>
                        <span className="text-fg-subtle text-xs font-normal">
                            {skill.project_count}{' '}
                            {skill.project_count === 1 ? 'project' : 'projects'}
                        </span>
                    </span>
                }
            >
                <ul className="space-y-1.5 text-sm">
                    {skill.projects.map((p) => (
                        <li key={p.slug}>
                            <Link
                                href={factsLink({
                                    project: p.slug,
                                    skill: skill.slug,
                                })}
                                className="text-fg rounded-sm hover:underline"
                            >
                                {p.name}
                            </Link>
                            <span className="text-fg-subtle text-xs">
                                {p.employer && ` · ${p.employer}`} ·{' '}
                                {p.fact_count}{' '}
                                {p.fact_count === 1 ? 'fact' : 'facts'} with
                                this skill
                            </span>
                        </li>
                    ))}
                </ul>
                <p className="mt-2">{facts}</p>
            </Disclosure>
        </li>
    );
}

/** Skills grouped by kind, searchable; project links come from the facts each skill is tagged on. */
export function SkillsView({ skills }: { skills: SkillSummary[] }) {
    const [q, setQ] = useState('');
    const [category, setCategory] = useState<'all' | SkillCategory>('all');

    const visible = useMemo(() => {
        const needle = q.trim().toLowerCase();

        return skills.filter(
            (s) =>
                (category === 'all' || s.category === category) &&
                (needle === '' || s.name.toLowerCase().includes(needle)),
        );
    }, [skills, q, category]);

    const counts = (c: SkillCategory) =>
        skills.filter((s) => s.category === c).length;

    return (
        <div>
            <div className="flex flex-wrap items-center gap-3">
                <TextInput
                    type="search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search skills…"
                    aria-label="Search skills"
                    className="w-full sm:w-64"
                />
                <SegmentedControl
                    label="Skill category"
                    value={category}
                    onChange={setCategory}
                    items={[
                        { value: 'all', label: 'All', count: skills.length },
                        ...CATEGORIES.map((c) => ({
                            value: c,
                            label: skillCategoryLabel(c),
                            count: counts(c),
                        })),
                    ]}
                />
            </div>
            <p aria-live="polite" className="text-fg-muted mt-3 text-sm">
                {visible.length === 0
                    ? 'No skills match.'
                    : `${visible.length} ${visible.length === 1 ? 'skill' : 'skills'}`}
            </p>

            <div className="mt-4 space-y-5">
                {CATEGORIES.map((c) => {
                    const group = visible.filter((s) => s.category === c);

                    if (group.length === 0) {
                        return null;
                    }

                    return (
                        <section key={c} aria-label={skillCategoryLabel(c)}>
                            <h2 className="text-fg text-sm font-semibold">
                                {skillCategoryLabel(c)}
                                <span className="text-fg-subtle ml-2 text-xs font-normal">
                                    {group.length}
                                </span>
                            </h2>
                            <Card className="mt-2 overflow-hidden">
                                <ul className="divide-line divide-y">
                                    {group.map((s) => (
                                        <SkillRow key={s.slug} skill={s} />
                                    ))}
                                </ul>
                            </Card>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
