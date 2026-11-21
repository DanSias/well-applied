// What KIND of site a host is. Pure: a hostname in, a label out. It reuses the inspector's own suffix matching (hostMatches).
// These lists classify; they never widen what the guard allows and they never decide that a destination is verified.
import { hostMatches } from '../page-kind.js';

/** Applicant-tracking systems the evaluation names. A white-labelled careers site on one of these (jobs.nvidia.com on Eightfold) is NOT recognised by host. */
export const ATS_HOSTS = Object.freeze([
    ['greenhouse.io', 'greenhouse'], ['lever.co', 'lever'], ['ashbyhq.com', 'ashby'], ['myworkdayjobs.com', 'workday'], ['myworkdaysite.com', 'workday'],
    ['workable.com', 'workable'], ['smartrecruiters.com', 'smartrecruiters'], ['eightfold.ai', 'eightfold'],
    ['icims.com', 'icims'], ['jobvite.com', 'jobvite'], ['bamboohr.com', 'bamboohr'], ['recruitee.com', 'recruitee'], ['breezy.hr', 'breezy'], ['teamtailor.com', 'teamtailor'],
]);

/** Job aggregators and boards: a listing there is not an employer's application. */
export const AGGREGATOR_HOSTS = Object.freeze([
    'adzuna.com', 'adzuna.co.uk', 'jobicy.com', 'himalayas.app', 'indeed.com', 'linkedin.com', 'ziprecruiter.com', 'glassdoor.com', 'simplyhired.com',
    'jooble.org', 'talent.com', 'remoteok.com', 'weworkremotely.com', 'builtin.com', 'wellfound.com', 'startup.jobs', 'dailyremote.com', 'usajobs.gov',
]);

/**
 * Click-tracking redirects: a plain GET to one of these registers an apply click with a third party and then redirects. Listed only
 * where the shape is KNOWN from stored data. Following one needs its own permission (see README.md, "Tracker redirects").
 */
export const TRACKER_RULES = Object.freeze([{ id: 'adzuna_land_ad', host: 'adzuna.com', path: /^\/land\/ad\//, note: 'Adzuna click-through redirect: 182 of the 205 stored Adzuna source URLs have this shape' }]);

export function platformOf(host) {
    for (const [domain, platform] of ATS_HOSTS) if (hostMatches(host, domain)) return platform;

    return null;
}

/** @returns {'known_ats'|'aggregator'|'other_site'} */
export function hostType(host) {
    if (!host) return 'other_site';
    if (platformOf(host)) return 'known_ats';
    if (AGGREGATOR_HOSTS.some((d) => hostMatches(host, d))) return 'aggregator';

    return 'other_site';
}

export function trackerRuleFor(url) {
    let u;
    try {
        u = new URL(url);
    } catch {
        return null;
    }

    return TRACKER_RULES.find((r) => hostMatches(u.hostname, r.host) && r.path.test(u.pathname)) ?? null;
}
