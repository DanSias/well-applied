/**
 * Pure rules for the Opportunities list: what goes in the URL, and how the
 * batch selection behaves. Kept free of React so they can be unit-tested.
 */

export const DEFAULT_PER_PAGE = 25;
/** Hard cap on how many opportunities one batch may process. */
export const BATCH_MAX_ITEMS = 50;

export type ListState = {
    q: string;
    status: string;
    triage: string;
    per_page: number;
    page: number;
};

/**
 * Query string params for a list state. Defaults are omitted so URLs stay
 * clean and shareable; everything else (search, filters, page size, page)
 * is carried so a reload or a copied link shows the same list.
 */
export function buildListParams(
    state: ListState,
): Record<string, string | number> {
    const params: Record<string, string | number> = {};

    if (state.q !== '') {
        params.q = state.q;
    }

    if (state.status !== 'all') {
        params.status = state.status;
    }

    if (state.triage !== 'all') {
        params.triage = state.triage;
    }

    if (state.per_page !== DEFAULT_PER_PAGE) {
        params.per_page = state.per_page;
    }

    if (state.page > 1) {
        params.page = state.page;
    }

    return params;
}

/**
 * The next list state after a change. Anything other than an explicit page
 * change goes back to page 1, so a new search, filter or page size never
 * strands the user on a page that no longer exists.
 */
export function nextListState(
    current: ListState,
    change: Partial<ListState>,
): ListState {
    return { ...current, page: 1, ...change };
}

/** Check or uncheck one row. Checking beyond the cap is refused, never truncated. */
export function toggleSelection(
    selected: ReadonlySet<number>,
    id: number,
    checked: boolean,
    cap: number = BATCH_MAX_ITEMS,
): Set<number> {
    const next = new Set(selected);

    if (!checked) {
        next.delete(id);

        return next;
    }

    if (next.has(id) || next.size >= cap) {
        return next;
    }

    next.add(id);

    return next;
}

/**
 * Select-all for the rows on screen: clears when they are all selected,
 * otherwise selects them all. Refuses (returns the selection unchanged) when
 * the rows exceed the batch cap rather than silently selecting some.
 */
export function toggleAllSelection(
    selected: ReadonlySet<number>,
    visibleIds: readonly number[],
    cap: number = BATCH_MAX_ITEMS,
): Set<number> {
    if (visibleIds.length > cap) {
        return new Set(selected);
    }

    const all =
        visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));

    return all ? new Set() : new Set(visibleIds);
}

/**
 * Keep only selected ids that are still on screen. A new page, search or
 * filter therefore always clears rows that left the list, and a row that is
 * not visible can never be submitted in a batch.
 */
export function pruneSelection(
    selected: ReadonlySet<number>,
    visibleIds: readonly number[],
): ReadonlySet<number> {
    const visible = new Set(visibleIds);
    const kept = [...selected].filter((id) => visible.has(id));

    return kept.length === selected.size ? selected : new Set(kept);
}
