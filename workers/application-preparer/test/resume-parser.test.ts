// The resume parser's visible status through the real engine, against the
// Lever-style fixture. Four things are kept apart and each test asserts them
// separately: bytes attached and verified, parse request allowed, parser
// visibly succeeded, parser failed or unresolved.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { startServer, type TestServer } from './support/server.ts';
import {
    LEVER_AUTH_QUESTION,
    fixtureHtml,
    runOn,
    startHeld,
} from './support/run.ts';

let server: TestServer;
let leverHtml: string;
before(async () => {
    server = await startServer();
    leverHtml = await fixtureHtml('lever-form.html');
});
after(async () => server.close());

const ANSWERS = { [LEVER_AUTH_QUESTION]: 'Yes' };
const fast = { parserWaitMs: 700, finishParserWaitMs: 700 };
const run = (path = '/lever-form.html', options = {}) =>
    runOn(server, path, 'lever', {
        input: { answers: ANSWERS },
        options: { ...fast, ...options },
    });
const reset = () => {
    server.parseDelayMs = 0;
    server.parseStatus = 200;
};

describe('resume parser status', () => {
    it('SUCCESS: attached + verified, request allowed, and a visible success indicator', async () => {
        reset();
        const { report } = await run();
        assert.equal(report.resume.verified, true);
        assert.equal(report.resume.verification, 'content_sha256');
        assert.deepEqual(
            {
                observable: report.resume.parser.observable,
                requestAllowed: report.resume.parser.requestAllowed,
                status: report.resume.parser.status,
                atPreparation: report.resume.parser.atPreparation,
            },
            {
                observable: true,
                requestAllowed: true,
                status: 'success',
                atPreparation: 'success',
            },
        );
        assert.match(report.resume.parser.detail, /visible success indicator/);
        assert.equal(
            report.warnings.some((w) => /resume parser/.test(w)),
            false,
        );
        assert.equal(report.outcome, 'ready_for_review');
    });

    it('FAILURE: the request was allowed and the bytes verified, but the page visibly reports a parse failure', async () => {
        reset();
        server.parseStatus = 500;
        const { report } = await run();
        reset();
        assert.equal(
            report.resume.verified,
            true,
            'hash verification is independent of parsing',
        );
        assert.equal(report.resume.parser.requestAllowed, true);
        assert.equal(report.resume.parser.status, 'failure');
        assert.match(report.resume.parser.detail, /visible failure indicator/);
        assert.ok(
            report.warnings.some((w) => /resume parser: failure/.test(w)),
        );
        assert.equal(
            report.outcome,
            'ready_for_review',
            'the parser is not what decides readiness',
        );
    });

    it('FAILURE (refused): with the exception absent the guard blocks the request, so requestAllowed is false and the page shows a failure', async () => {
        reset();
        const { report } = await run('/lever-form.html', {
            uploadExceptions: [],
        });
        assert.equal(report.resume.verified, true);
        assert.equal(report.resume.parser.requestAllowed, false);
        assert.equal(report.resume.parser.status, 'failure');
        assert.equal(
            report.outcome,
            'safety_failure',
            'the blocked mutating request is still a safety failure',
        );
        assert.deepEqual(server.mutations, []);
    });

    it('UNKNOWN (timeout): still analyzing when the bounded wait ends; neither success nor failure is claimed', async () => {
        reset();
        server.parseDelayMs = 5000;
        const started = Date.now();
        const { report } = await run('/lever-form.html', { parserWaitMs: 800 });
        reset();
        assert.equal(report.resume.verified, true, 'bytes verified');
        assert.equal(
            report.resume.parser.requestAllowed,
            true,
            'request allowed',
        );
        assert.equal(report.resume.parser.status, 'unknown');
        assert.match(
            report.resume.parser.detail,
            /still analyzing after \d+ms/,
        );
        assert.ok(
            report.resume.parser.waitedMs >= 700 &&
                report.resume.parser.waitedMs < 4000,
            `waited ${report.resume.parser.waitedMs}ms: bounded`,
        );
        assert.ok(
            report.warnings.some((w) => /resume parser: unknown/.test(w)),
        );
        assert.ok(Date.now() - started < 40_000);
    });

    it('UNKNOWN (no indicator): the request was allowed but the page shows nothing; allowed does not mean success', async () => {
        reset();
        const html = leverHtml
            .replace(/show\('working'\);/, '')
            .replace(/show\('success'\);/, '');
        assert.notEqual(html, leverHtml);
        server.add('/parser-silent.html', html);
        const { report } = await run('/parser-silent.html');
        assert.equal(report.resume.parser.requestAllowed, true);
        assert.equal(report.resume.verified, true);
        assert.equal(report.resume.parser.status, 'unknown');
        assert.match(
            report.resume.parser.detail,
            /no parser indicator became visible/,
        );
    });

    it('NOT ATTEMPTED: no resume field, so nothing was attached or parsed', async () => {
        reset();
        const html = leverHtml.replace(
            /<li class="application-question resume">[\s\S]*?<\/li>/,
            '',
        );
        assert.notEqual(html, leverHtml);
        server.add('/parser-no-resume.html', html);
        const { report } = await run('/parser-no-resume.html');
        assert.equal(report.resume.uploaded, false);
        assert.equal(report.resume.parser.status, 'not_attempted');
        assert.equal(report.resume.parser.requestAllowed, false);
    });

    it('NOT OBSERVABLE: an ATS with no known parser indicator never claims one', async () => {
        reset();
        const { report } = await runOn(
            server,
            '/greenhouse-form.html',
            'greenhouse',
            { options: fast },
        );
        assert.equal(report.resume.parser.observable, false);
        assert.equal(report.resume.parser.status, 'not_observable');
        assert.equal(report.resume.parser.requestAllowed, false);
        // The browser held exactly the chosen resume; for an upload-step ATS that alone is not an
        // attachment (see greenhouse-upload.test.ts), so `verified` is not what this test is about.
        assert.equal(report.resume.inputHeldChosenResume, true);
        assert.equal(report.resume.verification, 'input_only');
    });
});

describe('resume parser across a hold', () => {
    it('unresolved at the end of preparation, then visibly successful by the end of the hold: both are reported', async () => {
        reset();
        server.parseDelayMs = 2500;
        const held = await startHeld(server, '/lever-form.html', 'lever', {
            input: { answers: ANSWERS },
            options: { parserWaitMs: 500, finishParserWaitMs: 8000 },
        });
        const atHold = await held.waitForPreparation();
        assert.equal(atHold.resume.parser.status, 'unknown');
        await held.stop();
        const { report } = await held.promise;
        reset();
        assert.equal(
            report.resume.parser.atPreparation,
            'unknown',
            'what preparation saw is kept',
        );
        assert.equal(
            report.resume.parser.status,
            'success',
            'the final capture observed the visible success',
        );
        assert.equal(report.resume.parser.requestAllowed, true);
        assert.ok(report.resume.parser.waitedMs > 0);
        assert.equal(report.diagnostics.hold?.endedBy, 'stop_file');
    });

    it('still unresolved at the end of the hold: the final wait is bounded and the result stays unknown', async () => {
        reset();
        server.parseDelayMs = 30_000;
        const held = await startHeld(server, '/lever-form.html', 'lever', {
            input: { answers: ANSWERS },
            options: { parserWaitMs: 400, finishParserWaitMs: 900 },
        });
        await held.waitForPreparation();
        const stoppedAt = Date.now();
        await held.stop();
        const { report } = await held.promise;
        const took = Date.now() - stoppedAt;
        server.parseDelayMs = 0;
        assert.equal(report.resume.parser.status, 'unknown');
        assert.match(report.resume.parser.detail, /still analyzing/);
        assert.ok(took < 8000, `shutdown took ${took}ms: the wait is bounded`);
        assert.ok(
            report.screenshots.some((s) => s.phase === 'end_of_hold'),
            'final evidence was still captured',
        );
    });
});
