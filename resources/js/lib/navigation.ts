/**
 * The app's two kinds of destination, kept apart: the job-search workflow (Home, Sources, Jobs, Opportunities, Applications),
 * which is the primary navigation, and personal career information (Career Data, Resume Profiles), which lives in the
 * profile menu. Pure, so the labels, the destinations and which one is active can be tested.
 */

export type PrimaryKey = 'home' | 'sources' | 'jobs' | 'opportunities' | 'applications';

export const PRIMARY_NAV: { key: PrimaryKey; label: string; href: string }[] = [
    { key: 'home', label: 'Home', href: '/' },
    { key: 'sources', label: 'Sources', href: '/sources' },
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
 * The header section the server says this page belongs to (the shared prop `nav.section`, decided from the route and, for
 * a page about one job, the job's state: see App\Support\Navigation\NavigationContext). It is not worked out from the
 * address, so a pursued job's analysis or resume page keeps Opportunities lit. `profile` and anything unknown light no
 * primary item.
 */
export function activePrimary(
    section: string | null | undefined,
): PrimaryKey | null {
    return PRIMARY_NAV.find((item) => item.key === section)?.key ?? null;
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
