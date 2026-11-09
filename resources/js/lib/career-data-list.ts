import type { FactFilters } from '@/types/career-data';

export const DEFAULT_PER_PAGE = 25;

export type FactListState = FactFilters & { per_page: number; page: number };

/**
 * Query params for the Career Facts view. Defaults are omitted so URLs stay
 * short and shareable; the view itself is carried so a reload or a copied
 * link opens the same list.
 */
export function buildFactParams(
    state: FactListState,
): Record<string, string | number> {
    const params: Record<string, string | number> = { view: 'facts' };

    if (state.q !== '') {
        params.q = state.q;
    }

    if (state.employer !== null) {
        params.employer = state.employer;
    }

    if (state.project !== null) {
        params.project = state.project;
    }

    if (state.visibility !== null) {
        params.visibility = state.visibility;
    }

    if (state.provenance !== null) {
        params.provenance = state.provenance;
    }

    if (state.skill !== null) {
        params.skill = state.skill;
    }

    if (state.per_page !== DEFAULT_PER_PAGE) {
        params.per_page = state.per_page;
    }

    if (state.page > 1) {
        params.page = state.page;
    }

    return params;
}

/** Any change other than an explicit page change returns to page 1. */
export function nextFactState(
    current: FactListState,
    change: Partial<FactListState>,
): FactListState {
    const next = { ...current, page: 1, ...change };

    // A project belongs to one employer: changing the employer drops a project from another one.
    if ('employer' in change && !('project' in change)) {
        next.project = null;
    }

    return next;
}

export function hasActiveFilters(f: FactFilters): boolean {
    return (
        f.q !== '' ||
        f.employer !== null ||
        f.project !== null ||
        f.visibility !== null ||
        f.provenance !== null ||
        f.skill !== null
    );
}

/** Only an in-app Career Data path may be used as a "back" target. */
export function safeBackPath(value: string | null): string | null {
    if (
        value === null ||
        !value.startsWith('/career-data') ||
        value.startsWith('//')
    ) {
        return null;
    }

    return value;
}
