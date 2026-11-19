// End-to-end inspect() against LOCAL fixture sites only (real Chromium, no live network). The guard in these tests allows
// exactly the fixture servers' host:port pairs and refuses everything else, so no fixture can reach out.
// Fixtures are synthetic: authored from the design's assumptions about listing pages, not captured from live sites.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { inspect } from '../src/inspect.js';
import { startSites, fixture } from './support/fixture-server.js';

const html = (name) => () => ({ body: fixture(`phase1/${name}`) });
const greenhouseForm = () => ({ body: fixture('greenhouse-form.html') });
const FAST = { settleMs: 100, networkIdleMs: 800 };

let sites;
let lever;

before(async () => {
    sites = await startSites({
        board: {
            '/jobicy': html('jobicy-listing.html'),
            '/adzuna/redirect': ({ origins }) => ({ status: 302, headers: { location: `${origins.board}/adzuna/listing` } }),
            '/adzuna/listing': html('adzuna-listing.html'),
            '/no-apply': html('no-apply.html'),
            '/multiple': html('multiple-apply.html'),
            '/duplicate': html('duplicate-apply.html'),
            '/private': html('apply-private.html'),
            '/metadata': html('apply-metadata.html'),
            '/redirects-private': html('apply-redirects-private.html'),
            '/redirect-private': () => ({ status: 302, headers: { location: 'http://10.0.0.5:81/internal' } }),
            '/button-only': html('apply-button-only.html'),
            '/denied': () => ({ status: 403, body: fixture('phase1/access-denied.html') }),
            '/denied-200': html('access-denied.html'),
            '/login': html('login.html'),
            '/captcha': html('captcha.html'),
            '/newsletter': html('newsletter-only.html'),
            '/unknown-form': html('unknown-ats-form.html'),
            '/embed-page': html('employer-embed.html'),
            '/hang': () => ({ hang: true }),
            '/hang-main': () => ({ hang: true }),
            '/slow-form': () => ({
                body: fixture('greenhouse-form.html').replace('</body>', '<script>fetch("/hang").catch(()=>{})</script></body>'),
            }),
            '/loop-a': ({ origins }) => ({ body: `<title>A</title><a href="${origins.board}/loop-b">Apply Now</a>` }),
            '/loop-b': ({ origins }) => ({ body: `<title>B</title><a href="${origins.board}/loop-c">Apply Now</a>` }),
            '/loop-c': ({ origins }) => ({ body: `<title>C</title><a href="${origins.board}/loop-d">Apply Now</a>` }),
            '/loop-d': () => ({ body: '<title>D</title><p>never reached</p>' }),
            '/server-error': () => ({ status: 503, body: '<title>Unavailable</title><p>down</p>' }),
        },
        employer: {
            '/greenhouse/job': ({ origins }) => ({ body: `<title>Redirecting</title><a href="${origins.ats}/acme/jobs/1?gh_jid=1">Apply Now</a>` }),
            '/careers/job-42': html('employer-careers.html'),
            '/apply-a': greenhouseForm,
            '/apply-b': greenhouseForm,
        },
        ats: {
            '/acme/jobs/1': greenhouseForm,
            '/embed/job_app': greenhouseForm,
        },
    });

    // A Greenhouse-named host, mapped inside the browser only to the fixture "ats" server.
    sites.origins.gh = `http://boards.greenhouse.io:${sites.site('ats').port}`;

    // Another named host (Lever), likewise mapped inside the browser only.
    lever = await startSites({
        site: {
            '/acme/11111111-1111-4111-8111-111111111111/apply': html('unknown-ats-form.html'),
        },
    });
});

after(async () => {
    await sites.close();
    await lever.close();
});

const run = (url, extra = {}) =>
    inspect(url, { guard: sites.guard(), launch: sites.launch(), timeouts: FAST, budgetMs: 60_000, ...extra });

describe('reaching the application form', () => {
    test('1: a Jobicy-style listing with one Apply link leads through an employer page to a Greenhouse form, and extracts as before', async () => {
        const result = await run(`${sites.origins.board}/jobicy`);

        assert.equal(result.status, 'succeeded');
        assert.equal(result.inspection_outcome, 'complete');
        assert.equal(result.ats, 'greenhouse');
        assert.ok(result.fields.length >= 10, 'the existing Greenhouse extractor still runs on the form');
        assert.match(result.resolved_url, /\/acme\/jobs\/1\?gh_jid=1/, 'the functional gh_jid parameter is kept in the resolved URL');
        assert.equal(result.diagnostics.pages_visited, 3);
        assert.equal(result.diagnostics.follows, 2);
        assert.deepEqual(result.diagnostics.navigation.map((n) => n.kind), ['start', 'link', 'link']);
        for (const n of result.diagnostics.navigation) assert.ok(!n.url.includes('?'), 'no query string in the trace');
        assert.ok(!JSON.stringify(result.diagnostics).includes('utm_source'));
    });

    test('2: an Adzuna-style tracking redirect lands on a listing whose "Apply on company site" link leads on to the form', async () => {
        const result = await run(`${sites.origins.board}/adzuna/redirect`);

        assert.equal(result.inspection_outcome, 'complete');
        assert.equal(result.diagnostics.follows, 2);
        const kinds = result.diagnostics.navigation.map((n) => n.kind);
        assert.deepEqual(kinds.slice(0, 2), ['redirect', 'start'], 'the redirect hop is recorded before the page it led to');
        assert.equal(result.diagnostics.navigation[0].status, 302);
    });

    test('3: a page that already contains a Greenhouse form is extracted directly, with no link followed', async () => {
        const result = await run(`${sites.origins.ats}/acme/jobs/1`);

        assert.equal(result.inspection_outcome, 'complete');
        assert.equal(result.diagnostics.follows, 0);
        assert.equal(result.diagnostics.pages_visited, 1);
        assert.ok(result.fields.length >= 10);
    });

    test('an embedded Greenhouse application frame is followed to its own URL, token intact', async () => {
        const ghHost = `boards.greenhouse.io:${sites.site('ats').port}`;
        const result = await run(`${sites.origins.board}/embed-page`, {
            guard: sites.guard([ghHost]),
            launch: sites.launch(['MAP boards.greenhouse.io 127.0.0.1']),
        });

        assert.equal(result.inspection_outcome, 'complete');
        assert.match(result.resolved_url, /\/embed\/job_app\?for=acme&token=9/);
    });

    test('10: networkidle_timeout is a warning, and the form is still extracted', async () => {
        const result = await run(`${sites.origins.board}/slow-form`, { timeouts: { settleMs: 100, networkIdleMs: 500 } });

        assert.equal(result.inspection_outcome, 'complete');
        assert.ok(result.warnings.includes('networkidle_timeout'));
        assert.ok(result.fields.length >= 10);
    });

    test('11: an unknown ATS form with visible fields is reported as reached but not extractable, never as "no application"', async () => {
        const result = await run(`${sites.origins.board}/unknown-form`);

        assert.equal(result.status, 'succeeded');
        assert.equal(result.inspection_outcome, 'unsupported');
        assert.equal(result.stop_reason, 'form_without_adapter');
        assert.equal(result.ats, null);
        assert.deepEqual(result.fields, []);
        assert.match(result.resolved_url, /\/unknown-form$/);
    });

    test('a Lever application form is identified as Lever, and still reported as not extractable', async () => {
        const origin = `http://jobs.lever.co:${lever.site('site').port}`;
        const result = await inspect(`${origin}/acme/11111111-1111-4111-8111-111111111111/apply`, {
            guard: lever.guard([`jobs.lever.co:${lever.site('site').port}`]),
            launch: lever.launch([`MAP jobs.lever.co 127.0.0.1`]),
            timeouts: FAST,
            budgetMs: 60_000,
        });

        assert.equal(result.inspection_outcome, 'unsupported');
        assert.equal(result.stop_reason, 'ats_not_extractable');
        assert.equal(result.ats, 'lever');
    });
});

describe('stopping with a truthful outcome', () => {
    test('4: a job description with no Apply link is application_not_found, not "unsupported"', async () => {
        const result = await run(`${sites.origins.board}/no-apply`);

        assert.equal(result.inspection_outcome, 'application_not_found');
        assert.equal(result.stop_reason, 'no_apply_link');
        assert.equal(result.resolved_url, null);
        assert.equal(result.diagnostics.page_title, 'Software Engineer - Lockheed Martin');
    });

    test('a page whose only form is a newsletter signup is a listing, not an application', async () => {
        const result = await run(`${sites.origins.board}/newsletter`);

        assert.equal(result.inspection_outcome, 'application_not_found');
    });

    test('5: several distinct Apply destinations are never chosen between; the candidates are reported', async () => {
        const result = await run(`${sites.origins.board}/multiple`);

        assert.equal(result.inspection_outcome, 'needs_application_url');
        assert.equal(result.stop_reason, 'multiple_apply_links');
        assert.equal(result.diagnostics.candidates.length, 2);
        assert.equal(result.diagnostics.follows, 0);
        assert.equal(result.diagnostics.pages_visited, 1, 'nothing was followed');
    });

    test('the same destination linked twice (tracking or fragment differences only) is one candidate and is followed', async () => {
        const result = await run(`${sites.origins.board}/duplicate`);

        assert.equal(result.inspection_outcome, 'complete');
        assert.equal(result.diagnostics.follows, 1);
    });

    test('an Apply control that is only a button or a mailto link is reported and not clicked', async () => {
        const result = await run(`${sites.origins.board}/button-only`);

        assert.equal(result.inspection_outcome, 'application_not_found');
        assert.ok(result.warnings.includes('apply_is_button'));
        assert.ok(result.warnings.includes('apply_is_email'));
    });

    test('8: Access Denied is blocked/anti_bot, whether it comes with a 403 or a 200', async () => {
        for (const path of ['/denied', '/denied-200']) {
            const result = await run(`${sites.origins.board}${path}`);

            assert.equal(result.inspection_outcome, 'blocked', path);
            assert.equal(result.stop_reason, 'anti_bot', path);
        }
    });

    test('9: a login page is authentication_required, and a visible CAPTCHA challenge is blocked/captcha', async () => {
        const login = await run(`${sites.origins.board}/login`);
        assert.equal(login.inspection_outcome, 'authentication_required');
        assert.equal(login.stop_reason, 'login_wall');

        const captcha = await run(`${sites.origins.board}/captcha`);
        assert.equal(captcha.inspection_outcome, 'blocked');
        assert.equal(captcha.stop_reason, 'captcha');
    });

    test('an error status is destination_unreachable, with the status kept as evidence', async () => {
        const result = await run(`${sites.origins.board}/server-error`);

        assert.equal(result.inspection_outcome, 'needs_application_url');
        assert.equal(result.stop_reason, 'destination_unreachable');
        assert.equal(result.diagnostics.http_status, 503);
    });

    test('a chain of listings stops at three pages, never following a third link', async () => {
        const result = await run(`${sites.origins.board}/loop-a`);

        assert.equal(result.inspection_outcome, 'needs_application_url');
        assert.equal(result.stop_reason, 'too_many_steps');
        assert.equal(result.diagnostics.pages_visited, 3);
        assert.equal(result.diagnostics.follows, 2);
        assert.ok(!result.diagnostics.navigation.some((n) => n.url.endsWith('/loop-d')));
    });
});

describe('navigation safety', () => {
    test('6: an Apply link to a private-network address is refused before any request is made', async () => {
        for (const path of ['/private', '/metadata']) {
            const result = await run(`${sites.origins.board}${path}`);

            assert.equal(result.inspection_outcome, 'needs_application_url', path);
            assert.equal(result.stop_reason, 'destination_prohibited', path);
            assert.equal(result.diagnostics.pages_visited, 1, 'the link was never navigated to');
        }
    });

    test('7: a redirect chain that ends at a prohibited destination is cut off, and says so', async () => {
        const result = await run(`${sites.origins.board}/redirects-private`);

        assert.equal(result.inspection_outcome, 'needs_application_url');
        assert.equal(result.stop_reason, 'destination_prohibited');
    });

    test('a start URL on the private network is refused without launching a browser', async () => {
        let launched = false;
        const result = await inspect('http://192.168.1.10/apply', { launch: async () => { launched = true; } });

        assert.equal(result.inspection_outcome, 'needs_application_url');
        assert.equal(result.stop_reason, 'destination_prohibited');
        assert.equal(launched, false);
    });

    test('a start URL with embedded credentials is refused', async () => {
        const result = await inspect('https://user:pw@example.com/apply', { launch: async () => { throw new Error('must not launch'); } });

        assert.equal(result.stop_reason, 'destination_prohibited');
        assert.equal(result.diagnostics.refused, 'credentials_in_url');
    });
});

describe('time budget', () => {
    test('12: a page that never answers ends as a failed navigation_timeout inside the budget, keeping its evidence', async () => {
        const startedAt = Date.now();
        const result = await run(`${sites.origins.board}/hang-main`, { budgetMs: 7_000, timeouts: { navigationMs: 20_000 } });

        assert.equal(result.status, 'failed');
        assert.equal(result.failure_category, 'navigation_timeout');
        assert.ok(Date.now() - startedAt < 7_000, 'finished before the whole budget, cleanup included');
        assert.equal(result.diagnostics.pages_visited, 1);
        assert.ok(result.diagnostics.navigation.length >= 1, 'how far it got is kept');
        assert.ok(!/\?/.test(result.failure_message), 'no query string in the message');
    });
});
