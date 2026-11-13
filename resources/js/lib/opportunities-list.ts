/**
 * Pure rules for the Opportunities list: what goes in the URL, and how the
 * batch selection behaves. Kept free of React so they can be unit-tested.
 */

export const DEFAULT_PER_PAGE = 25;
/** Hard cap on how many jobs one analyze/match batch may process. */
export const BATCH_MAX_ITEMS = 50;
/** Shortlisting, dismissing and restoring are cheap writes, so a selection for them may be larger. */
export const DECISION_MAX_ITEMS = 100;

export type ListState = {
    q: string;
    status: string;
    triage: string;
    resume: string;
    disposition: string;
    /** The job open in the review drawer, if any. */
    job?: number | null;
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

    // The Inbox is the default view, so only the other views appear in the URL.
    if (state.disposition !== 'inbox') {
        params.disposition = state.disposition;
    }

    if (state.resume !== 'all') {
        params.resume = state.resume;
    }

    if (state.job !== undefined && state.job !== null) {
        params.job = state.job;
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

type DecisionOutcome = {
    to: string;
    changed: readonly unknown[];
    unchanged: readonly unknown[];
    failed: readonly unknown[];
    not_found: readonly unknown[];
};

const plural = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;

/** "Shortlisted 3 jobs · 1 already there · 1 could not be changed" — zero counts left out. */
export function decisionSummaryText(result: DecisionOutcome): string {
    const changed = result.changed.length;
    const parts: string[] = [];

    if (changed > 0) {
        const jobs = plural(changed, 'job', 'jobs');
        parts.push(
            {
                shortlisted: `Shortlisted ${jobs}`,
                dismissed: `Dismissed ${jobs}`,
                pursued: `Pursued ${jobs}`,
                new: `Moved ${jobs} back to the Inbox`,
                undo: `Undid ${plural(changed, 'decision', 'decisions')}`,
            }[result.to] ?? `Updated ${jobs}`,
        );
    }

    if (result.unchanged.length > 0) {
        parts.push(`${result.unchanged.length} already there`);
    }

    if (result.failed.length > 0) {
        parts.push(`${result.failed.length} could not be changed`);
    }

    if (result.not_found.length > 0) {
        parts.push(`${result.not_found.length} not found`);
    }

    return parts.length > 0 ? parts.join(' · ') : 'Nothing changed';
}

/** A result can be undone only when it changed something and is not itself an undo. */
export function canUndoDecision(result: DecisionOutcome): boolean {
    return result.to !== 'undo' && result.changed.length > 0;
}

/** Which bulk decisions make sense in a view: never the one the view already is. */
export function bulkDecisionsFor(
    view: string,
): ('shortlisted' | 'dismissed' | 'new')[] {
    return (['shortlisted', 'dismissed', 'new'] as const).filter(
        (d) =>
            !(
                (view === 'inbox' && d === 'new') ||
                (view === 'shortlist' && d === 'shortlisted') ||
                (view === 'dismissed' && d === 'dismissed')
            ),
    );
}

/**
 * Whether a job in this disposition still belongs in a Jobs view. After a decision made in the drawer, a job that no
 * longer belongs closes the drawer (rather than leaving it open on a job that has left the list).
 */
export function belongsInView(view: string, disposition: string): boolean {
    return (
        view === 'all' ||
        (view === 'inbox' && disposition === 'new') ||
        (view === 'shortlist' && disposition === 'shortlisted') ||
        (view === 'dismissed' && disposition === 'dismissed')
    );
}

/** Plain left click only: a modified click (new tab, new window) should keep its normal link behavior. */
export function isPlainClick(event: {
    button: number;
    metaKey: boolean;
    ctrlKey: boolean;
    shiftKey: boolean;
    altKey: boolean;
}): boolean {
    return (
        event.button === 0 &&
        !event.metaKey &&
        !event.ctrlKey &&
        !event.shiftKey &&
        !event.altKey
    );
}
