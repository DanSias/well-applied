// Destination Resolution over LOCAL fixture sites only (real Chromium, no live network). Hosts named like the real aggregators and ATSs are mapped to
// local servers inside the browser, and the guard allows exactly those host:port pairs and refuses everything else. The aggregator fixtures are synthetic:
// authored from the URL shapes in stored discovery data, never captured, so they say nothing about how the live sites behave.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { resolveDestination, ResolutionStatus } from '../src/resolution/resolve.js';
import { startSites, fixture } from './support/fixture-server.js';

const NAMES = { adzuna: 'www.adzuna.com', jobicy: 'jobicy.com', himalayas: 'himalayas.app', employer: 'careers.acmecorp.com', mailtrack: 'click.mailtrack-example.com', linkfwd: 'go.linkforward-example.io', gh: 'job-boards.greenhouse.io', lever: 'jobs.lever.co' };
const UUID = '11111111-1111-4111-8111-111111111111';
const FAST = { settleMs: 100, networkIdleMs: 800 };
const seen = []; // every request that reaches a fixture server: [site, method, path]
let sites;
let url;

const route = (site, handler) => (ctx) => {
    seen.push([site, ctx.method, null]);

    return handler(ctx);
};
const page = (file, replace = {}) => () => ({ body: Object.entries(replace).reduce((t, [k, v]) => t.split(k).join(v), fixture(file)) });
const redirect = (to) => ({ origins }) => ({ status: 302, headers: { location: typeof to === 'function' ? to(origins) : to } });

before(async () => {
    const R = (site, table) => Object.fromEntries(Object.entries(table).map(([p, h]) => [p, route(site, h)]));
    sites = await startSites({
        adzuna: R('adzuna', {
            '/details/1001': page('resolution/adzuna-details.html', { __ID__: '1001' }),
            '/details/2002': page('resolution/adzuna-details.html', { __ID__: '2002' }),
            '/details/3003': page('resolution/adzuna-details.html', { __ID__: '3003' }),
            '/land/ad/1001': redirect((o) => `${o.gh}/acme/jobs/1?gh_src=adzuna`),
            '/land/ad/2002': redirect((o) => `${o.employer}/jobs/42`),
            '/land/ad/3003': redirect('http://10.0.0.5:81/internal'),
        }),
        jobicy: R('jobicy', {
            '/jobs/101': page('resolution/jobicy-listing.html'),
            '/jobs/102': page('phase1/no-apply.html'),
            '/jobs/103': page('phase1/multiple-apply.html'),
            '/jobs/104': page('phase1/apply-private.html'),
            '/jobs/105': page('phase1/duplicate-apply.html'),
            // A tracking-style Apply link that is NOT Adzuna's: a click host whose query carries the employer URL.
            '/jobs/201': ({ origins }) => ({ body: `<title>Eng | Jobicy</title><a href="${origins.mailtrack}/c?url=${encodeURIComponent(`${origins.employer}/jobs/42`)}">Apply Now</a>` }),
            // An unknown third-party redirector: /redirect on a host nobody has reviewed.
            '/jobs/202': ({ origins }) => ({ body: `<title>Eng | Jobicy</title><a href="${origins.linkfwd}/redirect?u=${encodeURIComponent(`${origins.gh}/acme/jobs/1`)}">Apply Now</a>` }),
            // Legitimate direct links that must stay allowed: a known ATS carrying URL-valued and tracking parameters.
            '/jobs/203': ({ origins }) => ({ body: `<title>Eng | Jobicy</title><a href="${origins.gh}/acme/jobs/1?gh_jid=1&gh_src=jobicy&utm_source=jobicy&ref=${encodeURIComponent('https://jobicy.com/jobs/203')}&redirect_url=${encodeURIComponent('https://jobicy.com/')}">Apply Now</a>` }),
            // ...and an employer link with a relative "next" parameter and utm parameters.
            '/jobs/204': ({ origins }) => ({ body: `<title>Eng | Jobicy</title><a href="${origins.employer}/jobs/42?next=%2Fapply&utm_source=jobicy&utm_medium=listing">Apply Now</a>` }),
            // An unknown-host redirector with NO structural signal that chains through a second host: not caught before navigation, but the host it
            // passes THROUGH is flagged afterwards. A single bounce (/plain) is indistinguishable from a normal employer-to-ATS redirect and is not flagged.
            '/jobs/205': ({ origins }) => ({ body: `<title>Eng | Jobicy</title><a href="${origins.linkfwd}/chain?x=1">Apply Now</a>` }),
            '/jobs/206': ({ origins }) => ({ body: `<title>Eng | Jobicy</title><a href="${origins.linkfwd}/plain?x=1">Apply Now</a>` }),
            '/loop/a': ({ origins }) => ({ body: `<title>A</title><a href="${origins.jobicy}/loop/b">Apply Now</a>` }),
            '/loop/b': ({ origins }) => ({ body: `<title>B</title><a href="${origins.jobicy}/loop/c">Apply Now</a>` }),
            '/loop/c': ({ origins }) => ({ body: `<title>C</title><a href="${origins.jobicy}/loop/d">Apply Now</a>` }),
            '/loop/d': () => ({ body: '<title>D</title><p>never reached</p>' }),
        }),
        himalayas: R('himalayas', {
            '/companies/acme/jobs/staff': page('resolution/himalayas-job.html'),
            '/companies/acme/jobs/button': page('resolution/himalayas-button-only.html'),
            '/companies/acme/jobs/to-form': ({ origins }) => ({ body: `<title>Eng | Himalayas</title><a href="${origins.employer}/form">Apply now</a>` }),
            '/companies/acme/jobs/to-login': ({ origins }) => ({ body: `<title>Eng | Himalayas</title><a href="${origins.employer}/auth">Apply now</a>` }),
            '/companies/acme/jobs/to-captcha': ({ origins }) => ({ body: `<title>Eng | Himalayas</title><a href="${origins.employer}/captcha">Apply now</a>` }),
            '/companies/acme/jobs/to-dead': ({ origins }) => ({ body: `<title>Eng | Himalayas</title><a href="${origins.employer}/dead">Apply now</a>` }),
            '/companies/acme/jobs/to-home': ({ origins }) => ({ body: `<title>Eng | Himalayas</title><a href="${origins.employer}/home">Apply now</a>` }),
            '/companies/acme/jobs/to-private-redirect': ({ origins }) => ({ body: `<title>Eng | Himalayas</title><a href="${origins.employer}/to-private">Apply now</a>` }),
            '/companies/acme/jobs/own-form': page('phase1/unknown-ats-form.html'),
        }),
        employer: R('employer', {
            '/jobs/42': page('resolution/employer-job.html'),
            '/jobs/two': page('resolution/employer-job-two-applies.html'),
            '/form': page('phase1/unknown-ats-form.html'),
            '/auth': page('phase1/login.html'),
            '/captcha': page('phase1/captcha.html'),
            '/dead': () => ({ status: 404, body: '<title>Not found</title><h1>Page not found</h1>' }),
            '/home': page('resolution/employer-home.html'),
            '/to-private': redirect('http://10.0.0.5:81/internal'),
            '/apply-a': () => ({ body: fixture('greenhouse-form.html') }),
            '/apply-b': () => ({ body: fixture('greenhouse-form.html') }),
        }),
        mailtrack: R('mailtrack', { '/c': redirect((o) => `${o.employer}/jobs/42`), '/hop': redirect((o) => `${o.gh}/acme/jobs/1`) }),
        linkfwd: R('linkfwd', { '/redirect': redirect((o) => `${o.gh}/acme/jobs/1`), '/plain': redirect((o) => `${o.gh}/acme/jobs/1`), '/chain': redirect((o) => `${o.mailtrack}/hop`) }),
        gh: R('gh', { '/acme/jobs/1': () => ({ body: fixture('greenhouse-form.html') }) }),
        lever: R('lever', {
            [`/acme/${UUID}`]: page('resolution/lever-job.html'),
            [`/acme/${UUID}/apply`]: page('phase1/unknown-ats-form.html'),
        }),
    });
    for (const [k, host] of Object.entries(NAMES)) sites.origins[k] = `http://${host}:${sites.site(k).port}`;
    url = (site, path) => `${sites.origins[site]}${path}`;
});
after(() => sites.close());

const hostsWithPorts = () => Object.entries(NAMES).map(([k, h]) => `${h}:${sites.site(k).port}`);
let launched = 0;
const resolve = (u, extra = {}) =>
    resolveDestination(u, {
        guard: sites.guard(hostsWithPorts()),
        launch: () => {
            launched += 1;

            return sites.launch(Object.values(NAMES).map((h) => `MAP ${h} 127.0.0.1`))();
        },
        timeouts: FAST,
        budgetMs: 60_000,
        ...extra,
    });
const hitsOn = (site) => seen.filter(([s]) => s === site).length;
const stripVolatile = (r) => JSON.parse(JSON.stringify(r).replace(/:\d{4,5}/g, ':PORT'));

describe('direct ATS links', () => {
    test('a Greenhouse application URL: the form is observed at the source URL, so it is verified_direct', async () => {
        const r = await resolve(url('gh', '/acme/jobs/1'));
        assert.deepEqual([r.status, r.reason], [ResolutionStatus.VerifiedDirect, 'form_observed_at_source_url']);
        assert.deepEqual([r.destination.host_type, r.destination.platform, r.destination.form_observed], ['known_ats', 'greenhouse', true]);
        assert.deepEqual([r.hops.length, r.hops[0].page_kind], [1, 'form']);
        assert.equal(r.needs_human_review, false);
    });

    test('a Lever job page whose Apply link stays on the same board is verified_direct', async () => {
        const r = await resolve(url('lever', `/acme/${UUID}`));
        assert.deepEqual([r.status, r.reason], [ResolutionStatus.VerifiedDirect, 'form_observed_on_the_same_board_as_the_source_url']);
        assert.deepEqual([r.hops.length, r.destination.platform], [2, 'lever']);
    });
});

describe('aggregator listings', () => {
    test('Jobicy: listing, then an employer page, then a Greenhouse form: PROPOSED (never verified), with every hop recorded', async () => {
        const r = await resolve(url('jobicy', '/jobs/101'));
        assert.deepEqual([r.status, r.reason, r.source.host_type], [ResolutionStatus.Proposed, 'form_observed_after_following_apply_link', 'aggregator']);
        assert.deepEqual([r.destination.host_type, r.destination.platform, r.destination.form_observed, r.needs_human_review], ['known_ats', 'greenhouse', true, false]);
        assert.deepEqual(r.hops.map((h) => [h.index, h.final.host_type, h.page_kind]), [[0, 'aggregator', 'listing'], [1, 'other_site', 'listing'], [2, 'known_ats', 'form']]);
        assert.equal(r.hops[0].apply_candidates.length, 1);
        assert.equal(r.hops[0].apply_candidates[0].host, NAMES.employer);
        assert.ok(!JSON.stringify(r).includes('utm_source') && !JSON.stringify(r).includes('gh_jid'), 'no query strings are recorded');
    });

    test('Himalayas: an Apply link straight to a Lever application form: proposed, Lever', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/staff'));
        assert.deepEqual([r.status, r.destination.platform, r.destination.form_observed], [ResolutionStatus.Proposed, 'lever', true]);
        assert.equal(r.hops.length, 2);
    });

    test('a destination that is a form on a non-ATS site is proposed, but flagged for a person: its owner is not confirmed', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/to-form'));
        assert.deepEqual([r.status, r.destination.host_type, r.needs_human_review], [ResolutionStatus.Proposed, 'other_site', true]);
        assert.match(r.review_reason, /not on a recognised ATS/);
    });

    test('an Apply link to a page that is not a recognised form is proposed on its host alone, and always flagged', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/to-home'));
        assert.deepEqual([r.status, r.reason, r.destination.form_observed, r.destination.basis, r.needs_human_review], [ResolutionStatus.Proposed, 'destination_reached_form_not_recognised', false, 'host_only', true]);
    });

    test('a form hosted by the aggregator itself is not an employer application: unresolved', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/own-form'));
        assert.deepEqual([r.status, r.reason, r.destination.basis], [ResolutionStatus.Unresolved, 'application_hosted_by_aggregator', 'aggregator_hosted_form']);
    });

    test('duplicate links to one destination are one candidate, not an ambiguity', async () => {
        const r = await resolve(url('jobicy', '/jobs/105'));
        assert.equal(r.reason === 'multiple_apply_links', false);
        assert.equal(r.hops[0].apply_candidates.length, 1);
    });
});

describe('unresolved, with a precise reason', () => {
    test('no Apply link', async () => {
        const r = await resolve(url('jobicy', '/jobs/102'));
        assert.deepEqual([r.status, r.reason, r.destination, r.needs_human_review], [ResolutionStatus.Unresolved, 'no_apply_link', null, false]);
    });

    test('an Apply control that is a button is reported, never clicked', async () => {
        const before = hitsOn('himalayas');
        const r = await resolve(url('himalayas', '/companies/acme/jobs/button'));
        assert.deepEqual([r.status, r.reason], [ResolutionStatus.Unresolved, 'apply_is_button']);
        assert.equal(hitsOn('himalayas') - before, 1, 'only the listing itself was requested');
        assert.equal(r.provenance.clicks, 0);
    });

    test('several different Apply destinations are ambiguous: unresolved, with the candidates, for a person to choose', async () => {
        const r = await resolve(url('jobicy', '/jobs/103'));
        assert.deepEqual([r.status, r.reason, r.needs_human_review], [ResolutionStatus.Unresolved, 'multiple_apply_links', true]);
        assert.equal(r.apply_candidates.length, 2);
        assert.equal(r.destination, null, 'no destination is chosen for the person');
    });

    test('the page limit stops a chain of Apply links', async () => {
        const r = await resolve(url('jobicy', '/loop/a'));
        assert.deepEqual([r.status, r.reason, r.needs_human_review], [ResolutionStatus.Unresolved, 'too_many_steps', true]);
        assert.equal(r.hops.length, 3);
        assert.equal(r.provenance.limits.max_pages, 3);
    });
});

describe('authentication, unreachable and blocked destinations', () => {
    test('a sign-in wall at the destination is login_required, and nothing is typed', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/to-login'));
        assert.deepEqual([r.status, r.reason], [ResolutionStatus.LoginRequired, 'login_wall_at_destination']);
        assert.equal(r.provenance.form_fields_read, 0);
    });

    test('a sign-in wall at the source itself', async () => {
        const r = await resolve(url('employer', '/auth'));
        assert.deepEqual([r.status, r.reason], [ResolutionStatus.LoginRequired, 'login_wall_at_source']);
    });

    test('a CAPTCHA is blocked by the site, and is never solved', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/to-captcha'));
        assert.deepEqual([r.status, r.reason, r.blocked_by], [ResolutionStatus.Blocked, 'captcha', 'site']);
    });

    test('an HTTP 404 destination is unreachable, with its status', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/to-dead'));
        assert.deepEqual([r.status, r.reason, r.last_page.http_status ?? r.hops.at(-1).http_status], [ResolutionStatus.Unreachable, 'http_404', 404]);
    });

    test('a destination outside the allowed hosts is blocked by policy, before any request to it', async () => {
        const r = await resolve('http://not-allowed.example/job');
        assert.deepEqual([r.status, r.reason, r.blocked_by], [ResolutionStatus.Blocked, 'destination_prohibited', 'policy']);
        assert.equal(r.hops.length, 0);
    });

    test('an Apply link to a private address is blocked by policy, and never requested', async () => {
        const r = await resolve(url('jobicy', '/jobs/104'));
        assert.deepEqual([r.status, r.reason, r.blocked_by], [ResolutionStatus.Blocked, 'destination_prohibited', 'policy']);
    });

    test('a redirect into a private address is blocked by policy', async () => {
        const r = await resolve(url('himalayas', '/companies/acme/jobs/to-private-redirect'));
        assert.deepEqual([r.status, r.blocked_by], [ResolutionStatus.Blocked, 'policy']);
    });
});

describe('tracker redirects (Adzuna click-through) need their own permission', () => {
    test('by default a tracker source URL is refused BEFORE any browser or request', async () => {
        const hits = hitsOn('adzuna');
        const launches = launched;
        const r = await resolve(url('adzuna', '/land/ad/1001?se=x'));
        assert.deepEqual([r.status, r.reason, r.tracker.rule, r.tracker.where], [ResolutionStatus.Unresolved, 'tracker_redirect_requires_permission', 'adzuna_land_ad', 'source_url']);
        assert.equal(hitsOn('adzuna'), hits, 'the tracker was not requested');
        assert.equal(launched, launches, 'no browser was launched');
        assert.equal(r.provenance.tracker_redirects_allowed, false);
    });

    test('by default an Apply link that is a tracker is refused before it is requested: the listing is read, the tracker is not', async () => {
        const before = seen.length;
        const r = await resolve(url('adzuna', '/details/1001'));
        assert.deepEqual([r.status, r.reason, r.tracker.where], [ResolutionStatus.Unresolved, 'tracker_redirect_requires_permission', 'apply_link']);
        assert.deepEqual(seen.slice(before).map(([s]) => s), ['adzuna'], 'exactly one request: the listing');
        assert.equal(r.hops[0].apply_candidates.length, 1, 'the candidate is still recorded as evidence');
    });

    test('with permission, a tracker redirect to an ATS form is followed through the guard: proposed, with the redirect recorded as a hop', async () => {
        const r = await resolve(url('adzuna', '/land/ad/1001?se=x'), { allowTrackerRedirects: true });
        assert.deepEqual([r.status, r.destination.platform], [ResolutionStatus.Proposed, 'greenhouse']);
        assert.equal(r.provenance.tracker_redirects_allowed, true);
        assert.deepEqual(r.hops[0].redirect_chain.map((c) => [c.kind, c.host]), [['redirect', NAMES.adzuna], ['start', NAMES.gh]]);
    });

    test('with permission, Adzuna details to a tracker to an employer page to a form: three pages, all recorded', async () => {
        const r = await resolve(url('adzuna', '/details/2002'), { allowTrackerRedirects: true });
        assert.equal(r.status, ResolutionStatus.Proposed);
        assert.deepEqual(r.hops.map((h) => h.final.host_type), ['aggregator', 'other_site', 'known_ats']);
        assert.ok(r.hops[1].redirect_chain.some((c) => c.kind === 'redirect' && c.host === NAMES.adzuna));
    });

    test('with permission, a tracker that redirects into a private address is still blocked by the guard', async () => {
        const r = await resolve(url('adzuna', '/land/ad/3003?se=x'), { allowTrackerRedirects: true });
        assert.deepEqual([r.status, r.blocked_by], [ResolutionStatus.Blocked, 'policy']);
    });
});

describe('unknown redirectors and click-throughs are flagged for review BEFORE navigation', () => {
    test('a non-Adzuna tracking-style Apply link (a click host whose query carries the destination) is refused before it is requested', async () => {
        const hits = hitsOn('mailtrack');
        const r = await resolve(url('jobicy', '/jobs/201'));
        assert.deepEqual([r.status, r.reason, r.needs_human_review], [ResolutionStatus.Unresolved, 'unknown_redirector_requires_review', true]);
        assert.deepEqual([r.redirector.where, r.redirector.host], ['apply_link', NAMES.mailtrack]);
        assert.deepEqual(r.redirector.signals.sort(), ['destination_in_query', 'tracking_host_label']);
        assert.equal(hitsOn('mailtrack'), hits, 'the tracker was never requested');
        assert.equal(r.destination, null, 'the destination inside the query is not trusted or followed');
        assert.equal(r.hops[0].apply_candidates.length, 1, 'the candidate is kept as evidence');
        assert.equal(JSON.stringify(r).includes('mailtrack-example.com/c?'), false, 'no query string is recorded');
    });

    test('an unknown third-party redirector endpoint is refused before it is requested, and a source URL of that shape is refused with no browser at all', async () => {
        const hits = hitsOn('linkfwd');
        const r = await resolve(url('jobicy', '/jobs/202'));
        assert.deepEqual([r.reason, r.redirector.signals.sort()], ['unknown_redirector_requires_review', ['destination_in_query', 'tracking_path']]);
        assert.equal(hitsOn('linkfwd'), hits);
        const launches = launched;
        const src = await resolve(`${url('linkfwd', '/redirect')}?u=${encodeURIComponent(url('gh', '/acme/jobs/1'))}`);
        assert.deepEqual([src.reason, src.redirector.where], ['unknown_redirector_requires_review', 'source_url']);
        assert.equal(launched, launches);
    });

    test('once a PERSON has reviewed and cleared that host, the redirector is followed through the guard, and the hop is still recorded and flagged', async () => {
        const r = await resolve(url('jobicy', '/jobs/202'), { reviewedRedirectHosts: [NAMES.linkfwd] });
        assert.deepEqual([r.status, r.destination.platform], [ResolutionStatus.Proposed, 'greenhouse']);
        assert.deepEqual(r.provenance.reviewed_redirect_hosts, [NAMES.linkfwd]);
        assert.equal(r.hops.some((h) => h.redirect_chain.some((c) => c.host === NAMES.linkfwd)), true);
    });

    test('a legitimate direct ATS link stays allowed, even with tracking and URL-valued parameters', async () => {
        const r = await resolve(url('jobicy', '/jobs/203'));
        assert.deepEqual([r.status, r.destination.platform, r.needs_human_review], [ResolutionStatus.Proposed, 'greenhouse', false]);
        assert.equal(r.reason, 'form_observed_after_following_apply_link');
        assert.equal(r.redirector, undefined);
    });

    test('a normal employer application URL stays allowed, with relative "next" and utm parameters', async () => {
        const r = await resolve(url('jobicy', '/jobs/204'));
        assert.notEqual(r.reason, 'unknown_redirector_requires_review');
        assert.equal(r.status, ResolutionStatus.Proposed);
        assert.equal(r.hops[1].final.host, NAMES.employer);
    });

    test('an unknown redirector with no structural signal is followed, but its intermediate host is flagged for review after the fact', async () => {
        const r = await resolve(url('jobicy', '/jobs/205'));
        assert.equal(r.status, ResolutionStatus.Proposed);
        assert.equal(r.needs_human_review, true);
        assert.deepEqual(r.intermediate_redirect_hosts, [NAMES.mailtrack]);
        assert.ok(r.warnings.includes('unknown_intermediate_redirect_host'));
    });

    test('KNOWN LIMITATION: a single bounce through an unknown host with no structural signal is followed and not flagged (it looks like any employer-to-ATS redirect)', async () => {
        const r = await resolve(url('jobicy', '/jobs/206'));
        assert.deepEqual([r.status, r.needs_human_review, r.intermediate_redirect_hosts], [ResolutionStatus.Proposed, false, undefined]);
        assert.ok(r.hops.some((h) => h.redirect_chain.some((c) => c.host === NAMES.linkfwd)), 'still recorded in the hop evidence');
    });

    test('the Adzuna redirect (the source host itself) is NOT an unknown intermediate host', async () => {
        const r = await resolve(url('adzuna', '/land/ad/1001?se=x'), { allowTrackerRedirects: true });
        assert.equal(r.intermediate_redirect_hosts, undefined);
        assert.equal(r.needs_human_review, false);
    });
});

describe('redirector signals are narrow: normal application URLs are never caught', async () => {
    const { redirectorSignalsFor } = await import('../src/resolution/hosts.js');
    const page = 'https://jobicy.com/jobs/1';
    const legit = [
        'https://job-boards.greenhouse.io/acme/jobs/123?gh_jid=123&gh_src=x&utm_source=jobicy',
        'https://boards.greenhouse.io/embed/job_app?for=acme&token=9&redirect=https%3A%2F%2Facme.com%2F',
        'https://jobs.lever.co/acme/11111111-1111-4111-8111-111111111111/apply?lever-source=jobicy',
        'https://jobs.ashbyhq.com/acme/22222222-2222-4222-8222-222222222222/application?utm_source=x',
        'https://acme.wd5.myworkdayjobs.com/en-US/Careers/job/Remote/Engineer_R123?source=jobicy',
        'https://apply.workable.com/acme/j/ABC123/apply/',
        'https://jobs.smartrecruiters.com/Acme/743999873519601-engineer',
        'https://careers.acme.com/jobs/42?utm_source=jobicy&utm_medium=listing&ref=jobicy',
        'https://careers.acme.com/apply?next=%2Fjobs%2F42',
        'https://www.acme.com/careers/go/engineer/1234/',
        'https://acme.com/out/engineer-123',
        'https://go.acme.com/apply/engineer',
        'https://jobs.acme.co.uk/redirect-policy?jid=1',
    ];
    const suspect = [
        ['https://click.mailer.example/c?url=https%3A%2F%2Facme.com%2Fjobs%2F1', ['destination_in_query', 'tracking_host_label']],
        ['https://links.example.net/redirect?to=https://acme.com/jobs/1', ['destination_in_query', 'tracking_path']],
        ['https://trk.example.org/x', ['tracking_host_label']],
        ['https://example.net/track/click/abc123', ['tracking_path']],
        ['https://example.net/a?redirect_url=http://acme.com/jobs/1', ['destination_in_query']],
    ];
    for (const u of legit) test(`allowed: ${u.replace(/\?.*/, '')}`, () => assert.deepEqual(redirectorSignalsFor(u, page), []));
    for (const [u, signals] of suspect) test(`flagged: ${u.replace(/\?.*/, '')}`, () => assert.deepEqual(redirectorSignalsFor(u, page).sort(), signals.sort()));
    test('the same site as the linking page is never a redirector, whatever the path (and a registrable-domain trap is not exempt)', () => {
        assert.deepEqual(redirectorSignalsFor('https://careers.acme.com/redirect?url=https://acme.com/x', 'https://www.acme.com/jobs/1'), []);
        assert.deepEqual(redirectorSignalsFor('https://click.evil.co.uk/c?url=https://x.com', 'https://jobs.good.co.uk/1'), ['destination_in_query', 'tracking_host_label']);
    });
    test('an aggregator\'s own pages are listings, not redirectors', () => assert.deepEqual(redirectorSignalsFor('https://himalayas.app/companies/x/jobs/y?ref=https://a.com', page), []));
    test('a source URL (no linking page) is judged on the same signals', () => assert.deepEqual(redirectorSignalsFor('https://click.mailer.example/c?u=https://acme.com/'), ['destination_in_query', 'tracking_host_label']));
});

describe('safety and shape', () => {
    test('nothing but GET ever reached a server, across every case above', () => {
        assert.ok(seen.length > 20);
        assert.deepEqual([...new Set(seen.map(([, m]) => m))], ['GET']);
    });

    test('a resolution carries evidence and provenance, no form fields, and nothing that identifies the person', async () => {
        const r = await resolve(url('jobicy', '/jobs/101'));
        assert.equal(r.schema, 'destination-resolution/1');
        assert.deepEqual(Object.keys(r.provenance.limits).sort(), ['max_follows', 'max_pages', 'max_redirects']);
        assert.deepEqual([r.provenance.clicks, r.provenance.form_fields_read], [0, 0]);
        assert.equal('fields' in r, false);
        for (const h of r.hops) assert.ok(h.redirect_chain.length >= 1 && 'http_status' in h && Array.isArray(h.page_reasons));
        assert.equal(/cookie|password|token|authorization/i.test(JSON.stringify(r)), false);
    });

    test('the source is recorded without its query string', async () => {
        const r = await resolve(url('adzuna', '/land/ad/1001?se=secret-token'));
        assert.equal(JSON.stringify(r).includes('secret-token'), false);
    });

    test('the same input gives the same output (modulo ports)', async () => {
        const a = stripVolatile(await resolve(url('jobicy', '/jobs/101')));
        const b = stripVolatile(await resolve(url('jobicy', '/jobs/101')));
        assert.deepEqual(a, b);
    });
});
