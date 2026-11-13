import { describe, expect, it } from 'vitest';
import {
    BATCH_MAX_ITEMS,
    buildListParams,
    nextListState,
    pruneSelection,
    toggleAllSelection,
    toggleSelection,
    bulkDecisionsFor,
    canUndoDecision,
    decisionSummaryText,
    DECISION_MAX_ITEMS,
    type ListState,
} from './opportunities-list';

const base: ListState = {
    q: '',
    status: 'all',
    triage: 'all',
    resume: 'all',
    disposition: 'inbox',
    per_page: 25,
    page: 1,
};
const ids = (n: number, from = 1) =>
    Array.from({ length: n }, (_, i) => from + i);

describe('list URL state', () => {
    it('omits defaults so the default list has a clean URL', () => {
        expect(buildListParams(base)).toEqual({});
    });

    it('carries search, filters, page size and page', () => {
        expect(
            buildListParams({
                q: 'acme',
                status: 'inspected',
                triage: 'raw',
                resume: 'in-progress',
                disposition: 'shortlist',
                per_page: 50,
                page: 3,
            }),
        ).toEqual({
            q: 'acme',
            status: 'inspected',
            triage: 'raw',
            resume: 'in-progress',
            disposition: 'shortlist',
            per_page: 50,
            page: 3,
        });
    });

    it('resets to page 1 when search, filters or page size change', () => {
        const onPage3 = { ...base, page: 3 };

        expect(nextListState(onPage3, { q: 'x' }).page).toBe(1);
        expect(nextListState(onPage3, { triage: 'failed' }).page).toBe(1);
        expect(nextListState(onPage3, { status: 'inspected' }).page).toBe(1);
        expect(nextListState(onPage3, { per_page: 100 }).page).toBe(1);
    });

    it('keeps the requested page on an explicit page change and preserves the filters', () => {
        const state = nextListState(
            { ...base, q: 'acme', triage: 'raw', per_page: 50 },
            { page: 2 },
        );

        expect(state).toMatchObject({
            q: 'acme',
            triage: 'raw',
            per_page: 50,
            page: 2,
        });
        expect(buildListParams(state)).toEqual({
            q: 'acme',
            triage: 'raw',
            per_page: 50,
            page: 2,
        });
    });
});

describe('batch selection', () => {
    it('checks and unchecks a row independently', () => {
        const one = toggleSelection(new Set(), 7, true);

        expect([...one]).toEqual([7]);
        expect(toggleSelection(one, 7, false).size).toBe(0);
    });

    it('refuses a row beyond the cap instead of silently truncating', () => {
        const full = new Set(ids(BATCH_MAX_ITEMS));
        const after = toggleSelection(full, 999, true);

        expect(after.size).toBe(BATCH_MAX_ITEMS);
        expect(after.has(999)).toBe(false);
    });

    it('selects every row on a page, then clears them on the second toggle', () => {
        const page = ids(25);
        const all = toggleAllSelection(new Set(), page);

        expect(all.size).toBe(25);
        expect(toggleAllSelection(all, page).size).toBe(0);
    });

    it('completes a partial selection rather than clearing it', () => {
        const page = ids(5);

        expect(toggleAllSelection(new Set([1, 2]), page).size).toBe(5);
    });

    it('refuses select-all for more rows than a batch may hold', () => {
        const page = ids(100);
        const partial = new Set([1, 2]);

        expect([...toggleAllSelection(partial, page)]).toEqual([1, 2]);
    });

    it('drops selected ids that are no longer on screen (page, search or filter change)', () => {
        const selected = new Set([1, 2, 3]);

        expect([...pruneSelection(selected, [2, 3, 4])]).toEqual([2, 3]);
        expect(pruneSelection(selected, [30, 31]).size).toBe(0);
    });

    it('returns the same selection when nothing was pruned, so no re-render is forced', () => {
        const selected = new Set([1, 2]);

        expect(pruneSelection(selected, [1, 2, 3])).toBe(selected);
    });
});

describe('job decision views and results', () => {
    it('keeps the Inbox out of the URL but writes every other view, including All', () => {
        expect(buildListParams(base)).toEqual({});
        expect(buildListParams({ ...base, disposition: 'all' })).toEqual({
            disposition: 'all',
        });
        expect(
            buildListParams({ ...base, disposition: 'dismissed', q: 'x' }),
        ).toEqual({ q: 'x', disposition: 'dismissed' });
    });

    it('returns to page 1 when the decision view changes', () => {
        expect(
            nextListState({ ...base, page: 4 }, { disposition: 'all' }).page,
        ).toBe(1);
    });

    it('lets a decision selection run past the analysis limit, but not past its own', () => {
        const some = ids(DECISION_MAX_ITEMS);

        expect(
            toggleAllSelection(new Set(), some, DECISION_MAX_ITEMS).size,
        ).toBe(DECISION_MAX_ITEMS);
        expect(
            toggleAllSelection(
                new Set(),
                ids(DECISION_MAX_ITEMS + 1),
                DECISION_MAX_ITEMS,
            ).size,
        ).toBe(0);
    });

    it('summarizes what a decision did and leaves out zero counts', () => {
        const none = { changed: [], unchanged: [], failed: [], not_found: [] };

        expect(
            decisionSummaryText({
                ...none,
                to: 'shortlisted',
                changed: [1, 2, 3],
            }),
        ).toBe('Shortlisted 3 jobs');
        expect(
            decisionSummaryText({ ...none, to: 'dismissed', changed: [1] }),
        ).toBe('Dismissed 1 job');
        expect(
            decisionSummaryText({ ...none, to: 'new', changed: [1, 2] }),
        ).toBe('Moved 2 jobs back to the Inbox');
        expect(
            decisionSummaryText({
                ...none,
                to: 'shortlisted',
                changed: [1],
                unchanged: [2],
                failed: [3],
                not_found: [4],
            }),
        ).toBe(
            'Shortlisted 1 job · 1 already there · 1 could not be changed · 1 not found',
        );
        expect(
            decisionSummaryText({ ...none, to: 'undo', changed: [1, 2] }),
        ).toBe('Undid 2 decisions');
        expect(decisionSummaryText({ ...none, to: 'shortlisted' })).toBe(
            'Nothing changed',
        );
    });

    it('offers undo only for a decision that changed something and is not itself an undo', () => {
        const base = { unchanged: [], failed: [], not_found: [] };

        expect(
            canUndoDecision({ ...base, to: 'shortlisted', changed: [1] }),
        ).toBe(true);
        expect(
            canUndoDecision({ ...base, to: 'shortlisted', changed: [] }),
        ).toBe(false);
        expect(canUndoDecision({ ...base, to: 'undo', changed: [1] })).toBe(
            false,
        );
    });

    it('never offers the decision a view already is', () => {
        expect(bulkDecisionsFor('inbox')).toEqual(['shortlisted', 'dismissed']);
        expect(bulkDecisionsFor('shortlist')).toEqual(['dismissed', 'new']);
        expect(bulkDecisionsFor('dismissed')).toEqual(['shortlisted', 'new']);
        expect(bulkDecisionsFor('all')).toEqual([
            'shortlisted',
            'dismissed',
            'new',
        ]);
    });
});
