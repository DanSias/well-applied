import { Head, Link } from '@inertiajs/react';
import { VerificationBadge, VisibilityBadge } from '@/components/badges';
import EvidenceList from '@/components/career-data/evidence-list';
import MetricDisplay from '@/components/career-data/metric-display';
import {
    ConfirmedBadge,
    formatDate,
    provenanceText,
} from '@/components/career-data/provenance';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, Disclosure, SectionTitle } from '@/components/ui/surface';
import AppShell from '@/layouts/app-shell';
import { safeBackPath } from '@/lib/career-data-list';
import { index as careerDataIndex } from '@/routes/career-data';
import type { CareerDataFactProps } from '@/types/career-data';

/** Back goes to the exact list the fact was opened from, when we know it. */
function backTarget(): { href: string; label: string } {
    const from =
        typeof window === 'undefined'
            ? null
            : safeBackPath(
                  new URLSearchParams(window.location.search).get('back'),
              );

    if (from === null) {
        return { href: careerDataIndex.url(), label: 'Back to Career Data' };
    }

    return {
        href: from,
        label: from.includes('view=facts')
            ? 'Back to Career Facts'
            : 'Back to Career Data',
    };
}

export default function CareerDataFact({ fact }: CareerDataFactProps) {
    const back = backTarget();
    const project = fact.attribution.path[2];

    return (
        <AppShell>
            <Head title={fact.statement} />

            <Link
                href={back.href}
                className="rounded-control text-fg-muted hover:text-fg inline-flex items-center gap-1 text-sm"
            >
                <span aria-hidden="true">←</span> {back.label}
            </Link>

            <div className="mt-4 max-w-3xl space-y-8">
                <header>
                    <p className="text-fg-subtle text-sm">
                        {fact.attribution.path.join(' › ')}
                    </p>
                    <h1 className="text-fg mt-2 text-2xl leading-snug font-semibold tracking-tight">
                        {fact.statement}
                    </h1>
                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <VisibilityBadge value={fact.visibility} />
                        <VerificationBadge value={fact.verification} />
                        <ConfirmedBadge p={fact} />
                        {fact.via_interview && (
                            <Badge>Added in an interview</Badge>
                        )}
                    </div>
                    <p className="text-fg-muted mt-3 text-sm">
                        {provenanceText(fact)}
                    </p>
                </header>

                {fact.visibility === 'private' && (
                    <Alert tone="warn">
                        Private facts are never sent to a model or shown on a
                        resume.
                    </Alert>
                )}

                {fact.notes && (
                    <section>
                        <SectionTitle>Notes</SectionTitle>
                        <p className="text-fg-muted mt-2 text-sm leading-relaxed">
                            {fact.notes}
                        </p>
                    </section>
                )}

                {fact.metric && (
                    <section>
                        <SectionTitle>Metric</SectionTitle>
                        <div className="mt-2">
                            <MetricDisplay metric={fact.metric} />
                        </div>
                    </section>
                )}

                {fact.skills.length > 0 && (
                    <section>
                        <SectionTitle>Skills</SectionTitle>
                        <p className="mt-2 flex flex-wrap gap-2">
                            {fact.skills.map((skill) => (
                                <Link
                                    key={skill.slug}
                                    href={careerDataIndex.url({
                                        query: {
                                            view: 'facts',
                                            skill: skill.slug,
                                        },
                                    })}
                                >
                                    <Badge>{skill.name}</Badge>
                                </Link>
                            ))}
                        </p>
                    </section>
                )}

                <section>
                    <SectionTitle>Where this comes from</SectionTitle>
                    <div className="mt-2">
                        <EvidenceList evidence={fact.evidence} />
                    </div>
                </section>

                {fact.project_slug && (
                    <section>
                        <SectionTitle>More like this</SectionTitle>
                        <p className="mt-2 text-sm">
                            <Link
                                href={careerDataIndex.url({
                                    query: {
                                        view: 'facts',
                                        project: fact.project_slug,
                                    },
                                })}
                                className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                            >
                                Other facts for {project}
                            </Link>
                        </p>
                    </section>
                )}

                <Card className="px-5 py-4">
                    <Disclosure quiet summary="Advanced details">
                        <dl className="text-fg-subtle grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <dt className="text-fg-muted font-medium">
                                    Fact key
                                </dt>
                                <dd className="font-mono break-all">
                                    {fact.key}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-fg-muted font-medium">
                                    Type
                                </dt>
                                <dd>{fact.fact_type}</dd>
                            </div>
                            <div>
                                <dt className="text-fg-muted font-medium">
                                    Attached to
                                </dt>
                                <dd>
                                    {fact.attribution.type.replace('_', ' ')}
                                </dd>
                            </div>
                            {fact.metric && (
                                <>
                                    <div>
                                        <dt className="text-fg-muted font-medium">
                                            Metric value
                                        </dt>
                                        <dd>
                                            {fact.metric.is_range
                                                ? `${fact.metric.value} – ${fact.metric.value_max}`
                                                : fact.metric.value}{' '}
                                            {fact.metric.unit}
                                        </dd>
                                    </div>
                                    {fact.metric.comparator && (
                                        <div>
                                            <dt className="text-fg-muted font-medium">
                                                Comparator
                                            </dt>
                                            <dd>{fact.metric.comparator}</dd>
                                        </div>
                                    )}
                                </>
                            )}
                        </dl>
                        {fact.evidence.some(
                            (e) =>
                                e.document || e.path || e.section || e.locator,
                        ) && (
                            <ul className="text-fg-subtle mt-4 space-y-2 text-xs">
                                {fact.evidence.map((e, i) => (
                                    <li key={i} className="break-all">
                                        <span className="text-fg-muted font-medium">
                                            {e.source}
                                        </span>
                                        {[
                                            e.document,
                                            e.path,
                                            e.section,
                                            e.locator,
                                        ]
                                            .filter(Boolean)
                                            .map((v) => ` · ${v}`)
                                            .join('')}
                                        {e.confirmed_at &&
                                            ` · confirmed ${formatDate(e.confirmed_at)}`}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Disclosure>
                </Card>
            </div>
        </AppShell>
    );
}
