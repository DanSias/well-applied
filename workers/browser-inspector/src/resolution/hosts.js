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

// ---- unknown redirectors / click-throughs -----------------------------------------------------------------------------------------
// Narrow, structural signals only, so a normal employer or ATS application URL is never caught. A URL is NEVER suspect when it is
//   - on a known ATS host (those carry tracking-ish parameters all the time: gh_jid, gh_src, utm_*), or
//   - on the same site as the page that links to it, or
//   - merely carrying utm_* / ref parameters whose values are not themselves URLs.
// A cross-site URL is flagged only when it shows one of these:
//   destination_in_query   a parameter such as url=, u=, redirect=, target=, dest=, to=, next=, link= whose value is itself an absolute http(s) URL
//   tracking_host_label    the first host label is click, clk, track, trk, tracking, redirect or redir
//   tracking_path          the first path segment is redirect, redir, click, clk, track, trk, clickthrough, jump or away
// Deliberately NOT signals: /go/, /out/, /r/ and a bare "go." host label (too common on genuine employer sites). An unknown redirector using
// one of those is not caught before navigation; it is still recorded in the hop's redirect chain and flagged for review afterwards.
const DEST_PARAMS = /^(url|u|uri|redirect|redirect_url|redirect_uri|redir|target|dest|destination|to|next|link|goto|return|returnurl|continue)$/i;
const TRACK_LABEL = /^(click|clk|track|trk|tracking|redirect|redir)$/i;
const TRACK_PATH = /^(redirect|redir|click|clk|track|trk|clickthrough|jump|away)$/i;

const TWO_LEVEL = new Set(['co.uk', 'org.uk', 'ac.uk', 'com.au', 'net.au', 'co.nz', 'co.jp', 'co.in', 'com.br', 'com.mx', 'co.za', 'com.sg', 'com.cn', 'co.il']);
const registrable = (host) => {
    const labels = host.split('.');

    return labels.slice(TWO_LEVEL.has(labels.slice(-2).join('.')) ? -3 : -2).join('.');
};

/** @returns {string[]} the signals found (empty for a normal URL). `pageUrl` is the page that links to it; omit it for a source URL. */
export function redirectorSignalsFor(url, pageUrl = null) {
    let u;
    try {
        u = new URL(url);
    } catch {
        return [];
    }
    const host = u.hostname.toLowerCase();
    if (platformOf(host)) return [];
    if (pageUrl) {
        try {
            const page = new URL(pageUrl).hostname.toLowerCase();
            if (page === host || registrable(page) === registrable(host)) return [];
        } catch {
            /* an unparseable page URL gives no same-site exemption */
        }
    }
    // An aggregator's own pages are listings, not redirectors; the known tracker rules cover the aggregator click-throughs.
    if (hostType(host) === 'aggregator') return [];
    const signals = [];
    for (const [key, value] of u.searchParams.entries()) {
        if (DEST_PARAMS.test(key) && /^https?:\/\//i.test(value)) {
            signals.push('destination_in_query');
            break;
        }
    }
    if (TRACK_LABEL.test(host.split('.')[0])) signals.push('tracking_host_label');
    if (TRACK_PATH.test(u.pathname.split('/').filter(Boolean)[0] ?? '')) signals.push('tracking_path');

    return signals;
}
