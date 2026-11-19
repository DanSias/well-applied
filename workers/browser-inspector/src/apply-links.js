// Finding where an Apply link goes WITHOUT clicking anything. The page is only read: anchors' href values and iframes'
// src values are collected, ranked, and — when exactly one distinct destination remains — handed to the caller to
// navigate to with an ordinary GET through the guarded browser context. A JavaScript-only "Apply" button is never
// clicked; it is reported (apply_is_button) and the person is asked for the URL instead.
import { hostMatches } from './page-kind.js';

// The accessible name of an Apply link must EQUAL one of these (after normalising). Partial matches do not count, so
// "Apply filters" or "Apply coupon" never qualify.
const APPLY_TEXTS = new Set([
    'apply',
    'apply now',
    'apply here',
    'apply today',
    'apply online',
    'apply for this job',
    'apply for this position',
    'apply for this role',
    'apply to this job',
    'apply to this position',
    'apply to this role',
    'apply on company site',
    'apply on company website',
    'apply on the company site',
    'apply on the company website',
    'apply on employer site',
    'apply on employer website',
    'apply on the employer site',
    'apply on the employer website',
    'apply externally',
    'apply via company website',
    'apply at company site',
    'apply on website',
]);

/** Embedded ATS application frames whose own URL is the form. */
const EMBED = /^\/embed\/job_app\/?$/;

/**
 * Runs in the page. Facts only.
 */
export function collectLinkSignals() {
    const clean = (s) => (s ?? '').replace(/\s+/g, ' ').trim();

    function visible(el) {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
    }

    const anchors = Array.from(document.querySelectorAll('a[href], [role="link"][href]'))
        .filter(visible)
        .slice(0, 400)
        .map((a) => ({
            href: a.getAttribute('href') || '',
            text: clean(a.innerText) || clean(a.getAttribute('aria-label')) || clean(a.getAttribute('title')),
        }));

    const frames = Array.from(document.querySelectorAll('iframe[src]'))
        .slice(0, 40)
        .map((f) => ({ src: f.getAttribute('src') || '' }));

    // An "Apply" control that is a button rather than a link: reported, never clicked.
    const buttons = Array.from(document.querySelectorAll('button, [role="button"], input[type="button"], input[type="submit"]'))
        .filter((b) => visible(b) && !b.closest('a[href]') && !b.closest('form'))
        .slice(0, 200)
        .map((b) => clean(b.innerText) || clean(b.getAttribute('value')) || clean(b.getAttribute('aria-label')));

    return { anchors, frames, buttons };
}

export function normalizeText(text) {
    return (text ?? '')
        .toLowerCase()
        .replace(/[→›»↗➔>!.:]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

const TRACKING = /^(utm_|gclid$|fbclid$|msclkid$|dclid$|mc_cid$|mc_eid$|_hsenc$|_hsmi$|igshid$|yclid$)/i;

/** The same destination whatever its tracking parameters, fragment, parameter order or trailing slash. */
export function destinationKey(url) {
    const u = new URL(url);
    const params = Array.from(u.searchParams.entries())
        .filter(([k]) => !TRACKING.test(k))
        .sort(([a], [b]) => a.localeCompare(b));

    return `${u.protocol}//${u.host.toLowerCase()}${u.pathname.replace(/\/$/, '')}${params.length ? `?${params.map(([k, v]) => `${k}=${v}`).join('&')}` : ''}`;
}

/**
 * @param {ReturnType<typeof collectLinkSignals>} signals
 * @param {string} pageUrl the page the signals were read from
 * @returns {{candidates: Array<{url: string, text: string, kind: 'link'|'embed'|'path'}>, warnings: string[]}}
 */
export function rankApplyCandidates(signals, pageUrl) {
    const page = new URL(pageUrl);
    const warnings = [];
    const tier1 = new Map();
    const tier2 = new Map();
    let sawMailto = false;

    for (const a of signals.anchors) {
        const text = normalizeText(a.text);
        const href = a.href.trim();

        if (/^mailto:/i.test(href) && APPLY_TEXTS.has(text)) {
            sawMailto = true;
            continue;
        }

        let target;
        try {
            target = new URL(href, page);
        } catch {
            continue;
        }

        if (target.protocol !== 'http:' && target.protocol !== 'https:') continue;
        // The same page (or only a different fragment): nothing to follow.
        if (destinationKey(target.toString()) === destinationKey(page.toString())) continue;

        if (APPLY_TEXTS.has(text)) {
            tier1.set(destinationKey(target.toString()), { url: target.toString(), text: a.text.slice(0, 80), kind: 'link' });
            continue;
        }

        const sameOrigin = target.origin === page.origin;
        const knownAts = hostMatches(target.hostname, 'greenhouse.io') || hostMatches(target.hostname, 'lever.co');
        if ((sameOrigin || knownAts) && /(^|\/)apply(\/|$)/i.test(target.pathname)) {
            tier2.set(destinationKey(target.toString()), { url: target.toString(), text: a.text.slice(0, 80), kind: 'path' });
        }
    }

    for (const f of signals.frames) {
        let src;
        try {
            src = new URL(f.src, page);
        } catch {
            continue;
        }

        if (hostMatches(src.hostname, 'greenhouse.io') && EMBED.test(src.pathname)) {
            tier1.set(destinationKey(src.toString()), { url: src.toString(), text: 'Embedded application form', kind: 'embed' });
        }
    }

    const candidates = tier1.size > 0 ? [...tier1.values()] : [...tier2.values()];

    if (candidates.length === 0) {
        if (sawMailto) warnings.push('apply_is_email');
        if (signals.buttons.some((b) => APPLY_TEXTS.has(normalizeText(b)))) warnings.push('apply_is_button');
    }

    return { candidates, warnings };
}
