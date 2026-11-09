import { describe, expect, it } from 'vitest';
import {
    buildFactParams,
    hasActiveFilters,
    nextFactState,
    safeBackPath,
    type FactListState,
} from './career-data-list';

const base: FactListState = {
    q: '',
    employer: null,
    project: null,
    visibility: null,
    provenance: null,
    skill: null,
    per_page: 25,
    page: 1,
};

describe('career facts URL state', () => {
    it('carries only the view for the default list', () => {
        expect(buildFactParams(base)).toEqual({ view: 'facts' });
    });

    it('carries every active filter, the page size and the page', () => {
        expect(
            buildFactParams({
                q: 'billing',
                employer: 3,
                project: 'widget',
                visibility: 'public',
                provenance: 'confirmed',
                skill: 'php',
                per_page: 50,
                page: 2,
            }),
        ).toEqual({
            view: 'facts',
            q: 'billing',
            employer: 3,
            project: 'widget',
            visibility: 'public',
            provenance: 'confirmed',
            skill: 'php',
            per_page: 50,
            page: 2,
        });
    });

    it('resets to page 1 when a filter or the page size changes', () => {
        const onPage3 = { ...base, page: 3 };

        expect(nextFactState(onPage3, { q: 'x' }).page).toBe(1);
        expect(nextFactState(onPage3, { provenance: 'confirmed' }).page).toBe(
            1,
        );
        expect(nextFactState(onPage3, { per_page: 100 }).page).toBe(1);
    });

    it('keeps the requested page on an explicit page change', () => {
        expect(nextFactState({ ...base, q: 'x' }, { page: 4 })).toMatchObject({
            q: 'x',
            page: 4,
        });
    });

    it('drops a project when the employer changes, but not when both change together', () => {
        const withProject = { ...base, employer: 1, project: 'old' };

        expect(nextFactState(withProject, { employer: 2 }).project).toBeNull();
        expect(
            nextFactState(withProject, { employer: 2, project: 'new' }).project,
        ).toBe('new');
    });

    it('knows when filters are active', () => {
        expect(hasActiveFilters(base)).toBe(false);
        expect(hasActiveFilters({ ...base, skill: 'php' })).toBe(true);
        expect(hasActiveFilters({ ...base, q: 'x' })).toBe(true);
    });
});

describe('back link safety', () => {
    it('accepts only in-app Career Data paths', () => {
        expect(safeBackPath('/career-data?view=facts&page=2')).toBe(
            '/career-data?view=facts&page=2',
        );
        expect(safeBackPath('/career-data')).toBe('/career-data');
        expect(safeBackPath('https://evil.example/career-data')).toBeNull();
        expect(safeBackPath('//evil.example')).toBeNull();
        expect(safeBackPath('/jobs')).toBeNull();
        expect(safeBackPath(null)).toBeNull();
    });
});
