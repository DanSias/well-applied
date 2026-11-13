/**
 * The app's two kinds of destination, kept apart: the job-search workflow (Home, Jobs, Opportunities, Applications),
 * which is the primary navigation, and personal career information (Career Data, Resume Profiles), which lives in the
 * profile menu. Pure, so the labels, the destinations and which one is active can be tested.
 */

export type PrimaryKey = 'home' | 'jobs' | 'opportunities' | 'applications';

export const PRIMARY_NAV: { key: PrimaryKey; label: string; href: string }[] = [
    { key: 'home', label: 'Home', href: '/' },
    { key: 'jobs', label: 'Jobs', href: '/jobs' },
    { key: 'opportunities', label: 'Opportunities', href: '/opportunities' },
    { key: 'applications', label: 'Applications', href: '/applications' },
];

/** Personal career information: existing destinations only, none of them part of the job-search workflow. */
export const PROFILE_MENU: {
    key: string;
    label: string;
    href: string;
    prefix: string;
}[] = [
    {
        key: 'career-data',
        label: 'Career Data',
        href: '/career-data',
        prefix: '/career-data',
    },
    {
        key: 'resume-profiles',
        label: 'Resume Profiles',
        href: '/resume-profiles',
        prefix: '/resume-profiles',
    },
];

const pathOf = (url: string) => url.split(/[?#]/)[0].replace(/(.)\/$/, '$1');

/**
 * Which primary destination a page belongs to, for both index and detail pages. `/applications` is the list of
 * applications the person recorded; `/applications/{id}` is a browser inspection record, reached from an opportunity, so
 * it belongs to Opportunities. Pages of the profile menu belong to no primary destination.
 */
export function activePrimary(url: string): PrimaryKey | null {
    const path = pathOf(url);

    if (path === '/') {
        return 'home';
    }

    if (path === '/applications') {
        return 'applications';
    }

    if (
        path.startsWith('/applications/') ||
        path.startsWith('/opportunities')
    ) {
        return 'opportunities';
    }

    if (path.startsWith('/jobs')) {
        return 'jobs';
    }

    return null;
}

/** The profile-menu destination the page belongs to, if any (so the menu can show where you are). */
export function activeProfileItem(url: string): string | null {
    const path = pathOf(url);

    return (
        PROFILE_MENU.find(
            (item) =>
                path === item.prefix || path.startsWith(`${item.prefix}/`),
        )?.key ?? null
    );
}

/**
 * Initials for the profile avatar, from the profile's own name: first and last word, so "Daniel Sias" is "DS". One word
 * gives one letter; nothing usable gives an empty string (the caller shows a neutral mark instead).
 */
export function initialsFrom(name: string | null | undefined): string {
    const words = (name ?? '')
        .split(/[\s\-_.,]+/)
        .map((word) => word.trim())
        .filter((word) => /\p{L}/u.test(word));

    if (words.length === 0) {
        return '';
    }

    const first = Array.from(words[0])[0];
    const last = words.length > 1 ? Array.from(words[words.length - 1])[0] : '';

    return `${first}${last}`.toLocaleUpperCase();
}
