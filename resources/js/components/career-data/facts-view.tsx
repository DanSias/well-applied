import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { VerificationBadge, VisibilityBadge } from '@/components/badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TextInput } from '@/components/ui/field';
import { Pagination } from '@/components/ui/pagination';
import { Select } from '@/components/ui/select';
import { Card, Disclosure } from '@/components/ui/surface';
import {
    buildFactParams,
    hasActiveFilters,
    nextFactState,
    type FactListState,
} from '@/lib/career-data-list';
import { index as careerDataIndex } from '@/routes/career-data';
import * as factRoutes from '@/routes/career-data/facts';
import type { CareerDataIndexProps, FactRow } from '@/types/career-data';
import { PROVENANCE_OPTIONS, provenanceText } from './provenance';

const SEARCH_DEBOUNCE_MS = 300;

function FactItem({ fact, backTo }: { fact: FactRow; backTo: string }) {
    return (
        <li className="px-4 py-3.5">
            <p className="text-fg text-sm leading-relaxed">
                <Link
                    href={factRoutes.show.url(
                        { careerFact: fact.key },
                        { query: { back: backTo } },
                    )}
                    className="rounded-sm font-medium hover:underline"
                >
                    {fact.statement}
                </Link>
            </p>
            <p className="text-fg-subtle mt-1 text-xs leading-relaxed">
                {fact.place.join(' › ')}
            </p>
            <p className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                <VisibilityBadge value={fact.visibility} />
                <VerificationBadge value={fact.verification} />
                {fact.confirmed && (
                    <Badge tone="accent">Confirmed by you</Badge>
                )}
                {fact.has_metric && <Badge>Metric</Badge>}
            </p>
            <Disclosure quiet className="mt-2" summary="Provenance and details">
                <div className="text-fg-muted space-y-2 text-xs leading-relaxed">
                    <p>{provenanceText(fact)}</p>
                    {fact.notes && (
                        <p className="text-fg-subtle">{fact.notes}</p>
                    )}
                    {fact.skills.length > 0 && (
                        <p className="flex flex-wrap gap-1.5">
                            {fact.skills.map((s) => (
                                <Link
                                    key={s.slug}
                                    href={careerDataIndex.url({
                                        query: { view: 'facts', skill: s.slug },
                                    })}
                                >
                                    <Badge>{s.name}</Badge>
                                </Link>
                            ))}
                        </p>
                    )}
                </div>
            </Disclosure>
        </li>
    );
}

/**
 * Searchable, filterable list of the approved facts, paginated on the
 * server. Every control writes to the URL, so a list can be reloaded,
 * shared, and returned to from a fact page.
 */
export function FactsView({
    facts,
    filters,
    pagination,
    options,
}: {
    facts: FactRow[];
    filters: CareerDataIndexProps['filters'];
    pagination: NonNullable<CareerDataIndexProps['pagination']>;
    options: CareerDataIndexProps['filter_options'];
}) {
    const [q, setQ] = useState(filters.q);
    const timer = useRef<number | null>(null);
    const state: FactListState = {
        ...filters,
        q,
        per_page: pagination.per_page,
        page: pagination.page,
    };

    const go = (
        change: Partial<FactListState>,
        { replace = true, top = false } = {},
    ) => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }

        router.get(
            careerDataIndex.url(),
            buildFactParams(nextFactState(state, change)),
            { preserveState: true, replace, preserveScroll: !top },
        );
    };

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    // Back/forward changes the server's filters; adopt them into the box.
    useEffect(() => {
        setQ(filters.q);
    }, [filters.q]);

    const backTo = `${window.location.pathname}${window.location.search}`;
    const projects = options.projects.filter(
        (p) => filters.employer === null || p.employer_id === filters.employer,
    );
    const active = hasActiveFilters(filters);

    return (
        <div>
            <div className="flex flex-wrap items-center gap-3">
                <TextInput
                    type="search"
                    value={q}
                    onChange={(e) => {
                        setQ(e.target.value);

                        if (timer.current !== null) {
                            window.clearTimeout(timer.current);
                        }

                        const value = e.target.value;
                        timer.current = window.setTimeout(
                            () => go({ q: value }),
                            SEARCH_DEBOUNCE_MS,
                        );
                    }}
                    placeholder="Search facts and notes…"
                    aria-label="Search career facts"
                    className="w-full sm:w-72"
                />
                <Select
                    aria-label="Employer"
                    className="sm:max-w-56"
                    value={filters.employer ?? ''}
                    onChange={(e) =>
                        go({
                            employer:
                                e.target.value === ''
                                    ? null
                                    : Number(e.target.value),
                        })
                    }
                >
                    <option value="">All employers</option>
                    {options.employers.map((e) => (
                        <option key={e.id} value={e.id}>
                            {e.name}
                        </option>
                    ))}
                </Select>
                <Select
                    aria-label="Project"
                    className="sm:max-w-56"
                    value={filters.project ?? ''}
                    onChange={(e) =>
                        go({
                            project:
                                e.target.value === '' ? null : e.target.value,
                        })
                    }
                >
                    <option value="">All projects</option>
                    {projects.map((p) => (
                        <option key={p.slug} value={p.slug}>
                            {p.name}
                        </option>
                    ))}
                </Select>
                <Select
                    aria-label="Visibility"
                    className="sm:max-w-56"
                    value={filters.visibility ?? ''}
                    onChange={(e) =>
                        go({
                            visibility:
                                e.target.value === ''
                                    ? null
                                    : (e.target
                                          .value as FactListState['visibility']),
                        })
                    }
                >
                    <option value="">Any visibility</option>
                    <option value="public">Public</option>
                    <option value="restricted">Restricted</option>
                    <option value="private">Private</option>
                </Select>
                <Select
                    aria-label="Where it came from"
                    className="sm:max-w-56"
                    value={filters.provenance ?? ''}
                    onChange={(e) =>
                        go({
                            provenance:
                                e.target.value === ''
                                    ? null
                                    : (e.target
                                          .value as FactListState['provenance']),
                        })
                    }
                >
                    {PROVENANCE_OPTIONS.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </Select>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                <p aria-live="polite" className="text-fg-muted text-sm">
                    {pagination.total === 0
                        ? 'No facts'
                        : `Showing ${pagination.from}–${pagination.to} of ${pagination.total}${active ? ' matching' : ''}`}
                </p>
                {filters.skill !== null && (
                    <Badge tone="accent">Skill: {filters.skill}</Badge>
                )}
                {active && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setQ('');
                            go({
                                q: '',
                                employer: null,
                                project: null,
                                visibility: null,
                                provenance: null,
                                skill: null,
                            });
                        }}
                    >
                        Clear filters
                    </Button>
                )}
            </div>

            {facts.length === 0 ? (
                <Card className="text-fg-muted mt-4 border-dashed p-10 text-center text-sm">
                    {active
                        ? 'No facts match these filters.'
                        : 'No facts have been recorded yet.'}
                </Card>
            ) : (
                <Card className="mt-4 overflow-hidden">
                    <ul className="divide-line divide-y">
                        {facts.map((fact) => (
                            <FactItem
                                key={fact.key}
                                fact={fact}
                                backTo={backTo}
                            />
                        ))}
                    </ul>
                </Card>
            )}

            {pagination.total > 0 && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <label className="text-fg-muted flex items-center gap-2 text-sm">
                        Rows per page
                        <Select
                            aria-label="Rows per page"
                            value={pagination.per_page}
                            onChange={(e) =>
                                go({ per_page: Number(e.target.value) })
                            }
                        >
                            {pagination.per_page_options.map((n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <Pagination
                        page={pagination.page}
                        lastPage={pagination.last_page}
                        onPage={(page) =>
                            go({ page }, { replace: false, top: true })
                        }
                    />
                </div>
            )}
        </div>
    );
}
