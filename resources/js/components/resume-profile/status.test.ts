import { describe, expect, it } from 'vitest';
import { profileStatus, type StatusInput } from './status';

const base: StatusInput = {
    building: false,
    hasSample: true,
    interview: { total: 28, open: 8 },
    approvedVersion: null,
    approvedStale: false,
    outdated: false,
    draftVersion: null,
    draftStale: false,
};

describe('profileStatus', () => {
    it('starts with the market study when nothing exists yet', () => {
        const s = profileStatus({
            ...base,
            hasSample: false,
            interview: { total: 0, open: 0 },
        });

        expect(s).toMatchObject({
            state: 'not_started',
            usable: false,
            freshness: 'none',
            next: 'build_market',
        });
    });

    it('asks for the interview while questions are open and there is no profile', () => {
        expect(profileStatus(base)).toMatchObject({
            state: 'in_progress',
            next: 'interview',
        });
    });

    it('offers to build the profile once the interview is done', () => {
        expect(
            profileStatus({ ...base, interview: { total: 28, open: 0 } }).next,
        ).toBe('build_profile');
    });

    it('is not usable and asks for approval while only a draft exists', () => {
        const s = profileStatus({ ...base, draftVersion: 1 });

        expect(s).toMatchObject({
            state: 'draft',
            usable: false,
            freshness: 'current',
            next: 'review_draft',
        });
    });

    it('is usable when approved with current evidence', () => {
        const s = profileStatus({ ...base, approvedVersion: 5 });

        expect(s).toMatchObject({
            state: 'approved',
            usable: true,
            freshness: 'current',
            next: 'use',
        });
    });

    it('treats changed evidence as stale: not usable, refresh first', () => {
        const s = profileStatus({
            ...base,
            approvedVersion: 5,
            approvedStale: true,
        });

        expect(s).toMatchObject({
            usable: false,
            freshness: 'stale',
            next: 'refresh',
        });
    });

    it('does not treat an out-of-date inventory alone as an emergency', () => {
        const s = profileStatus({
            ...base,
            approvedVersion: 5,
            outdated: true,
        });

        expect(s).toMatchObject({
            usable: true,
            freshness: 'refresh_available',
            next: 'use',
        });
    });

    it('asks to approve a refreshed draft even while the approved version is stale', () => {
        const s = profileStatus({
            ...base,
            approvedVersion: 5,
            approvedStale: true,
            draftVersion: 6,
        });

        expect(s).toMatchObject({
            state: 'approved',
            usable: false,
            next: 'review_draft',
        });
    });

    it('asks to refresh a stale draft before it can be approved', () => {
        expect(
            profileStatus({ ...base, draftVersion: 2, draftStale: true }).next,
        ).toBe('refresh');
    });

    it('waits while a build is running', () => {
        expect(
            profileStatus({ ...base, building: true, approvedVersion: 5 }),
        ).toMatchObject({ state: 'building', next: 'wait' });
    });
});
