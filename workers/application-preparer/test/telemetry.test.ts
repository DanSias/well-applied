// The one Greenhouse analytics beacon seen on live boards (a Snowplow POST) is still BLOCKED; it is
// only reported as page noise instead of a possible submission. Every part must match exactly.
// The guard aborts these requests before any network I/O, so nothing here reaches a real host.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, type Browser } from 'playwright';
import { SubmissionGuard } from '../src/guard.ts';
import { SAFETY_FAILURE_KINDS } from '../src/contracts.ts';
import { isGreenhouseTelemetry } from '../src/request-policy.ts';
import { startServer, type TestServer } from './support/server.ts';

const OBSERVED = new URL(
    'https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
);
const JSON_TYPE = 'application/json';

describe('isGreenhouseTelemetry (pure)', () => {
    it('matches exactly the observed request', () => {
        assert.equal(
            isGreenhouseTelemetry('POST', OBSERVED, 'fetch', JSON_TYPE),
            true,
        );
        assert.equal(
            isGreenhouseTelemetry(
                'post',
                OBSERVED,
                'fetch',
                'application/json; charset=UTF-8',
            ),
            true,
        );
    });

    const near: Array<[string, string, URL, string, string | null]> = [
        ['GET', 'GET', OBSERVED, 'fetch', JSON_TYPE],
        ['PUT', 'PUT', OBSERVED, 'fetch', JSON_TYPE],
        ['xhr instead of fetch', 'POST', OBSERVED, 'xhr', JSON_TYPE],
        ['ping/beacon', 'POST', OBSERVED, 'ping', JSON_TYPE],
        ['text/plain body', 'POST', OBSERVED, 'fetch', 'text/plain'],
        [
            'form body',
            'POST',
            OBSERVED,
            'fetch',
            'application/x-www-form-urlencoded',
        ],
        [
            'multipart body',
            'POST',
            OBSERVED,
            'fetch',
            'multipart/form-data; boundary=x',
        ],
        ['no content type', 'POST', OBSERVED, 'fetch', null],
        [
            'http scheme',
            'POST',
            new URL(
                'http://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'other port',
            'POST',
            new URL(
                'https://c.spl.greenhouse.io:8443/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'other subdomain',
            'POST',
            new URL(
                'https://d.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'parent host',
            'POST',
            new URL(
                'https://spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'host with a suffix',
            'POST',
            new URL(
                'https://c.spl.greenhouse.io.evil.test/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'host with a prefix',
            'POST',
            new URL(
                'https://evilc.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'userinfo trick',
            'POST',
            new URL(
                'https://c.spl.greenhouse.io@evil.test/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'credentials in the URL',
            'POST',
            new URL(
                'https://user:pw@c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'a different tracker path',
            'POST',
            new URL(
                'https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp1',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'a longer path',
            'POST',
            new URL(
                'https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2/extra',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'a trailing slash',
            'POST',
            new URL(
                'https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2/',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'a different case in the path',
            'POST',
            new URL(
                'https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/TP2',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'the application endpoint',
            'POST',
            new URL(
                'https://job-boards.greenhouse.io/discord/jobs/1/applications',
            ),
            'fetch',
            JSON_TYPE,
        ],
        [
            'root of the same host',
            'POST',
            new URL('https://c.spl.greenhouse.io/'),
            'fetch',
            JSON_TYPE,
        ],
        [
            'an unrelated Greenhouse host',
            'POST',
            new URL('https://boards-api.greenhouse.io/v1/boards/x/jobs/1'),
            'fetch',
            JSON_TYPE,
        ],
    ];
    for (const [name, method, url, type, ct] of near)
        it(`does not match: ${name}`, () =>
            assert.equal(isGreenhouseTelemetry(method, url, type, ct), false));
});

describe('guard: telemetry stays blocked, is reported as telemetry, and nothing else qualifies', () => {
    let server: TestServer;
    let browser: Browser;
    before(async () => {
        server = await startServer();
        browser = await chromium.launch({ headless: true });
    });
    after(async () => {
        await browser.close();
        await server.close();
    });

    /** Issues one request from a page under the guard; returns the guard's events. */
    async function attempt(
        send: string,
    ): Promise<{ kinds: string[]; details: string[]; failures: number }> {
        const guard = new SubmissionGuard(new URL(server.origin), []);
        const context = await browser.newContext();
        await guard.install(context);
        const page = await context.newPage();
        guard.setMainPage(page);
        await page.goto(`${server.origin}/greenhouse-form.html`);
        guard.lockNavigation();
        await page.evaluate(async (code) => {
            try {
                await (0, eval)(`(async () => { ${code} })()`);
            } catch {
                /* the request is aborted by the guard: expected */
            }
        }, send);
        await page.waitForTimeout(300);
        const events = [...guard.events];
        await context.close();
        return {
            kinds: events.map((e) => e.kind),
            details: events.map((e) => e.detail),
            failures: events.filter((e) => SAFETY_FAILURE_KINDS.has(e.kind))
                .length,
        };
    }
    const post = (
        url: string,
        headers = '{"content-type":"application/json"}',
    ) =>
        `await fetch(${JSON.stringify(url)}, { method: 'POST', headers: ${headers}, body: '{"e":"pv"}' });`;

    it('the observed beacon is recorded as blocked_telemetry_request and is not a failure', async () => {
        const r = await attempt(post(OBSERVED.href));
        assert.deepEqual(r.kinds, ['blocked_telemetry_request']);
        assert.equal(r.failures, 0);
        assert.match(
            r.details[0]!,
            /^greenhouse snowplow analytics beacon: POST https:\/\/c\.spl\.greenhouse\.io\/com\.snowplowanalytics\.snowplow\/tp2 \[fetch, application\/json, \d+B\]$/,
        );
    });

    it('a query string is never written to the report', async () => {
        const r = await attempt(post(`${OBSERVED.href}?aid=secret-app&p=web`));
        assert.deepEqual(r.kinds, ['blocked_telemetry_request']);
        assert.doesNotMatch(r.details.join(' '), /secret-app|\?/);
    });

    it('SAFETY_FAILURE_KINDS does not include the telemetry kind, and never allows the request', () => {
        assert.equal(
            SAFETY_FAILURE_KINDS.has('blocked_telemetry_request'),
            false,
        );
        // "Blocked" is asserted end to end below: the guard aborts before any network I/O.
    });

    it('the request is aborted: the page sees a network error, not a response', async () => {
        const guard = new SubmissionGuard(new URL(server.origin), []);
        const context = await browser.newContext();
        await guard.install(context);
        const page = await context.newPage();
        guard.setMainPage(page);
        await page.goto(`${server.origin}/greenhouse-form.html`);
        guard.lockNavigation();
        const outcome = await page.evaluate(() =>
            fetch(
                'https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
                {
                    method: 'POST',
                    headers: { 'content-type': 'application/json' },
                    body: '{}',
                },
            ).then(
                () => 'response',
                () => 'network error',
            ),
        );
        assert.equal(outcome, 'network error');
        await context.close();
    });

    const stillFailures: Array<[string, string]> = [
        [
            'a different tracker path on the same host',
            post(
                'https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp1',
            ),
        ],
        [
            'the same path on a sibling host',
            post(
                'https://d.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2',
            ),
        ],
        [
            'plain-text body to the same URL',
            post(OBSERVED.href, '{"content-type":"text/plain"}'),
        ],
        [
            'a PUT to the same URL',
            `await fetch(${JSON.stringify(OBSERVED.href)}, { method: 'PUT', headers: {"content-type":"application/json"}, body: '{}' });`,
        ],
        [
            'an XHR (not fetch) to the same URL',
            `await new Promise((res) => { const x = new XMLHttpRequest(); x.open('POST', ${JSON.stringify(OBSERVED.href)}); x.setRequestHeader('content-type', 'application/json'); x.onloadend = res; x.send('{}'); });`,
        ],
        [
            'the application endpoint on Greenhouse',
            post(
                'https://job-boards.greenhouse.io/discord/jobs/1/applications',
            ),
        ],
        [
            'an unrelated Greenhouse API host',
            post('https://boards-api.greenhouse.io/v1/boards/x/jobs/1'),
        ],
    ];
    for (const [name, code] of stillFailures)
        it(`still fails closed: ${name}`, async () => {
            const r = await attempt(code);
            assert.deepEqual(r.kinds, ['blocked_mutating_request']);
            assert.equal(r.failures, 1);
        });

    it('lookalike hosts outside Greenhouse stay ordinary third-party noise, never telemetry', async () => {
        const r = await attempt(
            post(
                'https://c.spl.greenhouse.io.evil.test/com.snowplowanalytics.snowplow/tp2',
            ),
        );
        assert.deepEqual(r.kinds, ['blocked_third_party_request']);
    });
});
