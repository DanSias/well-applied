// The local fixture sites Destination Resolution is tested (and dry-run) against: hosts named like the real aggregators and ATSs, mapped to local servers
// inside the browser only. Synthetic: authored from URL shapes in stored discovery data, never captured, so they say nothing about the live sites.
import { startSites, fixture } from './fixture-server.js';

export const NAMES = { adzuna: 'www.adzuna.com', jobicy: 'jobicy.com', himalayas: 'himalayas.app', employer: 'careers.acmecorp.com', mailtrack: 'click.mailtrack-example.com', linkfwd: 'go.linkforward-example.io', gh: 'job-boards.greenhouse.io', lever: 'jobs.lever.co' };
export const UUID = '11111111-1111-4111-8111-111111111111';
export const FAST = { settleMs: 100, networkIdleMs: 800 };

const page = (file, replace = {}) => () => ({ body: Object.entries(replace).reduce((t, [k, v]) => t.split(k).join(v), fixture(file)) });
const redirect = (to) => ({ origins }) => ({ status: 302, headers: { location: typeof to === 'function' ? to(origins) : to } });

/** @returns {Promise<{sites: object, seen: Array<[string,string,null]>, url: (site: string, path: string) => string, hostsWithPorts: () => string[], mapped: () => string[], close: () => Promise<void>}>} */
export async function startResolutionSites() {
    const seen = []; // every request that reaches a fixture server: [site, method, null]
    const route = (site, handler) => (ctx) => {
        seen.push([site, ctx.method, null]);

        return handler(ctx);
    };
    const R = (site, table) => Object.fromEntries(Object.entries(table).map(([p, h]) => [p, route(site, h)]));
    const sites = await startSites({
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

    return {
        sites,
        seen,
        url: (site, path) => `${sites.origins[site]}${path}`,
        hostsWithPorts: () => Object.entries(NAMES).map(([k, h]) => `${h}:${sites.site(k).port}`),
        mapped: () => Object.values(NAMES).map((h) => `MAP ${h} 127.0.0.1`),
        close: () => sites.close(),
    };
}
