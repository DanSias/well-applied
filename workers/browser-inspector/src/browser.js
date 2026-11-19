// Single-use browser lifecycle for one inspection. No pooling, no persistent profile, no cookie/session persistence — a
// fresh, isolated context per inspection, closed reliably even on error. See README.md "Browser lifecycle".
//
// Read-only by construction: downloads are off, service workers are blocked, every request goes through the guard
// (guard.js: http(s) only, no private destinations, GET/HEAD only), and navigation here is the ONLY thing that moves the
// page — nothing is clicked, typed or submitted.
import { chromium } from 'playwright';
import {
    NAVIGATION_TIMEOUT_MS,
    NETWORK_IDLE_TIMEOUT_MS,
    SETTLE_MS,
} from './budget.js';
import { redact } from './guard.js';

export function launchChromium() {
    return chromium.launch({ headless: true });
}

/**
 * @param {import('playwright').Browser} browser
 * @param {ReturnType<import('./guard.js').createGuard>} guard
 */
export async function openGuardedPage(browser, guard) {
    const context = await browser.newContext({
        viewport: { width: 1400, height: 1000 },
        acceptDownloads: false,
        serviceWorkers: 'block',
    });
    await guard.install(context);

    // One page only: a popup is closed at once (and never navigated), so nothing runs outside the guarded page.
    let first = null;
    context.on('page', (popup) => {
        if (first !== null && popup !== first) {
            popup.close().catch(() => {});
        }
    });

    first = await context.newPage();
    await guard.attach(first);

    return { context, page: first };
}

/**
 * Navigates to url and waits, within the deadline, for the page to settle. A readiness warning never stops extraction:
 * `networkidle` is best-effort (a page with analytics may never go idle), so a timeout there only records
 * `networkidle_timeout` and the page is read as it stands.
 *
 * Never throws for an ordinary outcome; returns what happened.
 *
 * @param {import('playwright').Page} page
 * @param {string} url
 * @param {{deadline: ReturnType<import('./budget.js').createDeadline>, guard: ReturnType<import('./guard.js').createGuard>, timeouts?: {navigationMs?: number, networkIdleMs?: number, settleMs?: number}}} options
 */
export async function navigate(page, url, { deadline, guard, timeouts = {} }) {
    const { navigationMs = NAVIGATION_TIMEOUT_MS, networkIdleMs = NETWORK_IDLE_TIMEOUT_MS, settleMs = SETTLE_MS } = timeouts;
    const startedAt = Date.now();
    const warnings = [];
    let response = null;
    let error = null;

    guard.take();

    try {
        response = await page.goto(url, {
            waitUntil: 'domcontentloaded',
            timeout: deadline.slice(navigationMs),
        });
    } catch (e) {
        error = e;
    }

    const violations = guard.take();

    if (error === null) {
        try {
            await page.waitForLoadState('networkidle', {
                timeout: deadline.slice(networkIdleMs),
            });
        } catch {
            warnings.push('networkidle_timeout');
        }
        // Give SPA-rendered forms (e.g. Greenhouse's React app) a brief
        // extra moment to finish rendering after DOMContentLoaded.
        await page.waitForTimeout(deadline.slice(settleMs));
        violations.push(...guard.take());
    }

    return {
        response,
        status: response?.status() ?? null,
        finalUrl: error === null ? page.url() : url,
        warnings,
        violations,
        error,
        navigationMs: Date.now() - startedAt,
    };
}

/**
 * The redirect chain behind a response as trace entries (origin + path only), oldest first.
 *
 * @param {import('playwright').Response | null} response
 * @param {'start'|'link'} kind how the final page was reached
 */
export async function traceFor(response, kind) {
    if (!response) return [];

    const chain = [];
    for (let req = response.request(); req; req = req.redirectedFrom()) chain.unshift(req);

    const entries = [];
    for (const [i, req] of chain.entries()) {
        const last = i === chain.length - 1;
        const res = last ? response : await req.response().catch(() => null);
        entries.push({
            kind: last ? kind : 'redirect',
            url: safeRedact(req.url()),
            status: res?.status() ?? null,
        });
    }

    return entries;
}

export function safeRedact(url) {
    try {
        return redact(url);
    } catch {
        return null;
    }
}
