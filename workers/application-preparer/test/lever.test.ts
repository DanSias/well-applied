import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { startServer, type TestServer } from './support/server.ts';
import { LEVER_AUTH_QUESTION, runOn } from './support/run.ts';
import { lever } from '../src/adapters/index.ts';

let server: TestServer;
before(async () => void (server = await startServer()));
after(async () => server.close());

const byName = (
    r: { report: { fields: Array<{ name: string | null }> } },
    name: string,
) => {
    const f = r.report.fields.find((x) => x.name === name);
    assert.ok(f, `field ${name} not discovered`);
    return f as (typeof r.report.fields)[number] & {
        status: string;
        expected?: string;
        actual?: string;
        classification: string;
    };
};

describe('Lever-style form', () => {
    it('fills known fields, verifies the resume by content, and stops with required input outstanding', async () => {
        const r = await runOn(server, '/lever-form.html', 'lever');
        const { report } = r;

        assert.equal(report.ats, 'lever');
        assert.equal(
            report.outcome,
            'input_required',
            JSON.stringify(report.reasons),
        );
        assert.match(
            report.reasons.join(' '),
            /unresolved_required: Are you legally authorized/,
        );

        assert.equal(
            byName(r, 'name').actual,
            'Alex Example',
            'full name derived from first + last',
        );
        assert.equal(byName(r, 'email').actual, 'alex.example@example.test');
        assert.equal(byName(r, 'phone').status, 'filled_verified');
        assert.equal(byName(r, 'location').status, 'filled_verified');
        assert.equal(byName(r, 'org').actual, 'Example Co');
        assert.equal(byName(r, 'urls[LinkedIn]').status, 'filled_verified');
        assert.equal(byName(r, 'urls[GitHub]').status, 'filled_verified');
        assert.equal(byName(r, 'urls[Portfolio]').status, 'filled_verified');

        assert.equal(report.resume.uploaded, true);
        assert.equal(report.resume.verification, 'content_sha256');
        assert.equal(report.resume.verified, true);
        assert.equal(report.resume.observedFilename, 'sample-resume.pdf');

        assert.deepEqual(
            server.mutations,
            [],
            'the server must never receive a POST',
        );
        assert.deepEqual(report.safetyEvents, []);
        assert.equal(report.submission.assertion, 'worker_did_not_submit');
    });

    it('is ready_for_review when required answers are approved, and optional gaps are not a problem', async () => {
        const r = await runOn(server, '/lever-form.html', 'lever', {
            input: { answers: { [LEVER_AUTH_QUESTION]: 'Yes' } },
        });
        const { report } = r;
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
        assert.deepEqual(report.reasons, []);

        const radio = report.fields.find((f) => f.kind === 'radio');
        assert.equal(radio?.status, 'filled_verified');
        assert.equal(radio?.actual, 'Yes');
        assert.equal(report.summary.unresolvedRequired, 0);
        assert.ok(
            report.summary.unansweredOptional >= 4,
            'other website, how-did-you-hear, comments, gender',
        );

        const other = byName(r, 'urls[Other]');
        assert.equal(other.status, 'unanswered_optional');
        const gender = r.report.fields.find((f) => f.name === 'eeo[gender]')!;
        assert.equal(gender.sensitive, true);
        assert.equal(gender.status, 'unanswered_optional');
        assert.equal(
            gender.expected,
            undefined,
            'sensitive values are never recorded',
        );
        assert.deepEqual(server.mutations, []);
    });

    it('refuses an approved answer that is not exactly one of the options', async () => {
        const r = await runOn(server, '/lever-form.html', 'lever', {
            input: { answers: { [LEVER_AUTH_QUESTION]: 'yes, I am' } },
        });
        const radio = r.report.fields.find((f) => f.kind === 'radio');
        assert.equal(radio?.classification, 'uncertain');
        assert.equal(radio?.status, 'unresolved_uncertain');
        assert.equal(r.report.outcome, 'input_required');
    });

    it('records the controls it saw, and never touches the submit button', async () => {
        const { report } = await runOn(server, '/lever-form.html', 'lever');
        assert.deepEqual(
            report.controls.map((c) => c.role),
            ['submit'],
        );
        assert.equal(report.blockers.multistep, false);
    });

    it('writes private evidence: screenshots and a report readable only by the owner', async () => {
        const { report, reportPath, runDir } = await runOn(
            server,
            '/lever-form.html',
            'lever',
        );
        assert.deepEqual(report.screenshots, [
            '01-before.png',
            '02-after-fill.png',
        ]);
        for (const s of report.screenshots)
            assert.ok(statSync(join(runDir, s)).size > 1000, s);
        assert.ok(existsSync(reportPath));
        assert.equal(
            statSync(reportPath).mode & 0o077,
            0,
            'report must not be group/world readable',
        );
    });

    it('rewrites only the exact posting URL shape to /apply', () => {
        const posting = new URL(
            'https://jobs.lever.co/acme/12345678-1234-1234-1234-123456789abc?lever-source=x',
        );
        assert.equal(
            lever.formUrl(posting).toString(),
            'https://jobs.lever.co/acme/12345678-1234-1234-1234-123456789abc/apply',
        );
        const already = new URL(
            'https://jobs.lever.co/acme/12345678-1234-1234-1234-123456789abc/apply',
        );
        assert.equal(lever.formUrl(already).toString(), already.toString());
        const other = new URL('https://jobs.lever.co/acme');
        assert.equal(lever.formUrl(other).toString(), other.toString());
    });
});
