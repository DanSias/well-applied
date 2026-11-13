import { describe, expect, it } from 'vitest';
import {
    PRIMARY_NAV,
    PROFILE_MENU,
    activePrimary,
    activeProfileItem,
    initialsFrom,
} from './navigation';

describe('primary navigation', () => {
    it('is the job-search workflow, in order', () => {
        expect(PRIMARY_NAV.map((i) => i.label)).toEqual([
            'Home',
            'Jobs',
            'Opportunities',
            'Applications',
        ]);
        expect(PRIMARY_NAV.map((i) => i.href)).toEqual([
            '/',
            '/jobs',
            '/opportunities',
            '/applications',
        ]);
    });

    it('keeps personal career data out of it', () => {
        const labels = PRIMARY_NAV.map((i) => i.label);

        expect(labels).not.toContain('Career Data');
        expect(labels).not.toContain('Resume Profiles');
    });
});

describe('active primary destination', () => {
    it.each([
        ['/', 'home'],
        ['/?tab=x', 'home'],
        ['/jobs', 'jobs'],
        ['/jobs?disposition=shortlist&page=2', 'jobs'],
        ['/jobs/12', 'jobs'],
        ['/jobs/create', 'jobs'],
        ['/opportunities', 'opportunities'],
        ['/opportunities?resume=final', 'opportunities'],
        ['/opportunities/4', 'opportunities'],
        ['/applications', 'applications'],
        ['/applications?x=1', 'applications'],
    ])('%s belongs to %s', (url, key) => {
        expect(activePrimary(url)).toBe(key);
    });

    it('treats an inspection record as part of Opportunities, not Applications', () => {
        expect(activePrimary('/applications/17')).toBe('opportunities');
    });

    it('has no primary destination for the profile menu pages', () => {
        expect(activePrimary('/career-data')).toBeNull();
        expect(activePrimary('/resume-profiles/engineering')).toBeNull();
    });

    it('does not confuse a prefix that merely looks similar', () => {
        expect(activePrimary('/jobsearch')).toBe('jobs');
    });
});

describe('profile menu', () => {
    it('offers only existing destinations: Career Data and Resume Profiles', () => {
        expect(PROFILE_MENU.map((i) => i.label)).toEqual([
            'Career Data',
            'Resume Profiles',
        ]);
        expect(PROFILE_MENU.map((i) => i.href)).toEqual([
            '/career-data',
            '/resume-profiles',
        ]);
    });

    it('knows which of its pages you are on, index or detail', () => {
        expect(activeProfileItem('/career-data')).toBe('career-data');
        expect(activeProfileItem('/career-data/facts/3?x=1')).toBe(
            'career-data',
        );
        expect(activeProfileItem('/resume-profiles/engineering')).toBe(
            'resume-profiles',
        );
        expect(activeProfileItem('/jobs')).toBeNull();
        expect(activeProfileItem('/career-datax')).toBeNull();
    });
});

describe('initialsFrom', () => {
    it.each([
        ['Daniel Sias', 'DS'],
        ['  daniel   sias ', 'DS'],
        ['Mary Jane Watson', 'MW'],
        ['Anne-Marie Smith', 'AS'],
        ['Madonna', 'M'],
        ['Élodie Ångström', 'ÉÅ'],
        ['', ''],
        [null, ''],
        [undefined, ''],
        ['  ---  ', ''],
    ])('%j -> %j', (name, initials) => {
        expect(initialsFrom(name as string | null | undefined)).toBe(initials);
    });
});
