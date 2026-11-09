import { Link } from '@inertiajs/react';
import { VisibilityBadge } from '@/components/badges';
import { Badge } from '@/components/ui/badge';
import { Card, Disclosure } from '@/components/ui/surface';
import { index as careerDataIndex } from '@/routes/career-data';
import * as factRoutes from '@/routes/career-data/facts';
import type {
    EducationSummary,
    EmployerSummary,
    Highlight,
    ProjectSummary,
} from '@/types/career-data';

function factsLink(query: Record<string, string | number>) {
    return careerDataIndex.url({ query: { view: 'facts', ...query } });
}

function Highlights({ items }: { items: Highlight[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <ul className="mt-3 space-y-2">
            {items.map((h) => (
                <li
                    key={h.key}
                    className="text-fg-muted flex gap-2.5 text-sm leading-relaxed"
                >
                    <span
                        aria-hidden="true"
                        className="bg-fg-subtle mt-2 h-1 w-1 shrink-0 rounded-full"
                    />
                    <span className="min-w-0">
                        <Link
                            href={factRoutes.show.url(
                                { careerFact: h.key },
                                { query: { back: '/career-data' } },
                            )}
                            className="text-fg rounded-sm hover:underline"
                        >
                            {h.statement}
                        </Link>
                        {h.visibility !== 'public' && (
                            <span className="ml-2 inline-block align-middle">
                                <VisibilityBadge value={h.visibility} />
                            </span>
                        )}
                        {h.confirmed && (
                            <span className="ml-1.5 inline-block align-middle">
                                <Badge tone="accent">Confirmed</Badge>
                            </span>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function Project({ project }: { project: ProjectSummary }) {
    return (
        <li className="rounded-control border-line bg-canvas/50 border px-4 py-3">
            <Disclosure
                summary={
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="text-fg font-medium">
                            {project.name}
                        </span>
                        <span className="text-fg-subtle text-xs font-normal">
                            {project.fact_count}{' '}
                            {project.fact_count === 1 ? 'fact' : 'facts'}
                        </span>
                        {project.visibility &&
                            project.visibility !== 'public' && (
                                <VisibilityBadge value={project.visibility} />
                            )}
                    </span>
                }
            >
                {project.description && (
                    <p className="text-fg-muted text-sm leading-relaxed">
                        {project.description}
                    </p>
                )}
                {project.skills.length > 0 && (
                    <p className="mt-3 flex flex-wrap gap-1.5">
                        {project.skills.map((s) => (
                            <Link
                                key={s.slug}
                                href={factsLink({ skill: s.slug })}
                            >
                                <Badge>{s.name}</Badge>
                            </Link>
                        ))}
                    </p>
                )}
                <Highlights items={project.highlights} />
                <Link
                    href={factsLink({ project: project.slug })}
                    className="text-accent-text hover:text-fg mt-3 inline-block rounded-sm text-xs underline underline-offset-2"
                >
                    All {project.fact_count} facts for this project
                </Link>
            </Disclosure>
        </li>
    );
}

/** Employers and roles, newest first, with the headline accomplishments and expandable projects. */
export function ExperienceView({
    employers,
    education,
}: {
    employers: EmployerSummary[];
    education: EducationSummary[];
}) {
    if (employers.length === 0) {
        return (
            <Card className="text-fg-muted border-dashed p-10 text-center text-sm">
                No employers recorded.
            </Card>
        );
    }

    return (
        <div className="space-y-6">
            {employers.map((employer) => (
                <Card key={employer.id} className="p-5">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-fg text-base font-semibold tracking-tight">
                            {employer.name}
                        </h2>
                        <Link
                            href={factsLink({ employer: employer.id })}
                            className="text-accent-text hover:text-fg rounded-sm text-xs underline underline-offset-2"
                        >
                            View all facts
                        </Link>
                    </div>
                    {employer.description && (
                        <p className="text-fg-muted mt-1 max-w-prose text-sm leading-relaxed">
                            {employer.description}
                        </p>
                    )}

                    <div className="mt-5 space-y-6">
                        {employer.roles.map((role) => (
                            <section
                                key={role.id}
                                className="border-line border-l-2 pl-4"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                    <h3 className="text-fg text-sm font-semibold">
                                        {role.title}
                                        {role.is_current && (
                                            <span className="ml-2 align-middle">
                                                <Badge tone="ok">Current</Badge>
                                            </span>
                                        )}
                                    </h3>
                                    <span className="text-fg-subtle text-xs">
                                        {role.date_label}
                                    </span>
                                </div>
                                <Highlights items={role.highlights} />
                                {role.projects.length > 0 && (
                                    <ul className="mt-4 space-y-2">
                                        {role.projects.map((p) => (
                                            <Project key={p.slug} project={p} />
                                        ))}
                                    </ul>
                                )}
                            </section>
                        ))}
                    </div>
                </Card>
            ))}

            {education.length > 0 && (
                <Card className="p-5">
                    <h2 className="text-fg text-base font-semibold tracking-tight">
                        Education
                    </h2>
                    <ul className="mt-3 space-y-3 text-sm">
                        {education.map((e) => (
                            <li key={e.id}>
                                <p className="text-fg font-medium">
                                    {e.degree}
                                    {e.field_of_study && (
                                        <span className="text-fg-muted font-normal">
                                            {' '}
                                            in {e.field_of_study}
                                        </span>
                                    )}
                                </p>
                                <p className="text-fg-subtle text-xs">
                                    {e.institution}
                                    {(e.start_year || e.end_year) &&
                                        ` · ${[e.start_year, e.end_year].filter(Boolean).join('–')}`}
                                </p>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </div>
    );
}
