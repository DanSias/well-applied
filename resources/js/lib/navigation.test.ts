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
            'Sources',
            'Jobs',
            'Opportunities',
            'Applications',
        ]);
        expect(PRIMARY_NAV.map((i) => i.href)).toEqual([
            '/',
            '/sources',
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
    it.each([['home'], ['sources'], ['jobs'], ['opportunities'], ['applications']])(
        'lights %s when the server says so',
        (section) => {
            expect(activePrimary(section)).toBe(section);
        },
    );

    it('lights nothing for the profile menu, an unknown section, or none', () => {
        expect(activePrimary('profile')).toBeNull();
        expect(activePrimary('nonsense')).toBeNull();
        expect(activePrimary(null)).toBeNull();
        expect(activePrimary(undefined)).toBeNull();
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
