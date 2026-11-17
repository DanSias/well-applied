import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { startServer, type TestServer } from './support/server.ts';
import { LEVER_AUTH_QUESTION, runOn } from './support/run.ts';
import type { FieldResult, PrepareReport } from '../src/contracts.ts';
import { lever } from '../src/adapters/index.ts';

let server: TestServer;
before(async () => void (server = await startServer()));
after(async () => server.close());

const byName = (r: { report: PrepareReport }, name: string): FieldResult => {
    const f = r.report.fields.find((x) => x.name === name);
    assert.ok(f, `field ${name} not discovered`);
    return f;
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
        assert.equal(byName(r, 'urls[Github]').status, 'filled_verified');

        assert.equal(report.resume.uploaded, true);
        assert.equal(report.resume.verification, 'content_sha256');
        assert.equal(report.resume.verified, true);
        assert.equal(report.resume.observedFilename, 'sample-resume.pdf');

        assert.deepEqual(server.unexpected(), []);
        assert.deepEqual(
            server.mutations,
            ['POST /parseResume'],
            'only the allowed resume parse',
        );
        assert.deepEqual(
            report.safetyEvents.map((e) => e.kind),
            ['allowed_exception_used'],
            'the one allowed resume parse is recorded, nothing else',
        );
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

        const other = byName(r, 'urls[Other Website]');
        assert.equal(other.status, 'unanswered_optional');
        const gender = r.report.fields.find((f) => f.name === 'eeo[gender]')!;
        assert.equal(gender.sensitive, true);
        assert.equal(gender.status, 'unanswered_optional');
        assert.equal(
            gender.expected,
            undefined,
            'sensitive values are never recorded',
        );
        assert.deepEqual(server.unexpected(), []);
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
            report.controls.map((c) => c.role).sort(),
            ['other', 'other', 'submit'],
            'the cookie banner buttons are not submit or navigation',
        );
        assert.equal(report.blockers.multistep, false);
    });

    it('writes private evidence: screenshots and a report readable only by the owner', async () => {
        const { report, reportPath, runDir } = await runOn(
            server,
            '/lever-form.html',
            'lever',
        );
        assert.deepEqual(
            [...new Set(report.screenshots.map((s) => s.phase))],
            ['before', 'after_fill'],
        );
        for (const s of report.screenshots)
            assert.ok(statSync(join(runDir, s.file)).size > 1000, s.file);
        assert.ok(existsSync(reportPath));
        assert.equal(
            statSync(reportPath).mode & 0o077,
            0,
            'report must not be group/world readable',
        );
    });

    it('reflects the characteristics seen on the real form: required flags, controls, captcha state, pronouns, EEO', async () => {
        const r = await runOn(server, '/lever-form.html', 'lever');
        const { report } = r;
        const required = (name: string) => byName(r, name).required;
        assert.deepEqual(['name', 'email', 'phone', 'org'].map(required), [
            true,
            true,
            true,
            true,
        ]);
        assert.equal(required('resume'), false, 'the resume is not required');
        assert.equal(required('location'), false);

        const pronouns = report.fields.filter((f) => f.name === 'pronouns');
        assert.equal(pronouns.length, 10);
        assert.ok(
            pronouns.every(
                (f) =>
                    f.kind === 'checkbox' &&
                    f.status === 'unanswered_optional' &&
                    f.sensitive,
            ),
        );

        const roles = report.controls.map((c) => `${c.text}:${c.role}`);
        assert.ok(
            roles.includes('Deny:other') && roles.includes('Accept:other'),
            'cookie banner buttons are not submit/navigation',
        );
        assert.ok(roles.includes('Submit application:submit'));
        assert.equal(
            report.controls.find((c) => c.role === 'submit')?.type,
            'button',
        );
        assert.equal(report.blockers.multistep, false);

        assert.equal(
            report.blockers.captcha,
            'inactive_markup',
            'hidden hCaptcha-style markup is not a blocker',
        );

        const race = report.fields.find((f) => f.name === 'eeo[race]')!;
        assert.equal(race.kind, 'radio');
        assert.ok(
            race.options![0]!.startsWith('Hispanic or Latino') &&
                race.options![0]!.length > 60,
            'option text includes the description',
        );
        assert.equal(race.sensitive, true);
        const age = report.fields.find((f) => /age range/.test(f.label ?? ''))!;
        assert.equal(age.status, 'unanswered_optional');
        assert.equal(
            report.fields.filter((f) => f.sensitive).length >= 15,
            true,
        );
    });

    it('never fills a sensitive question even when an approved answer matches an option exactly', async () => {
        const full =
            'Hispanic or LatinoA person of Cuban, Mexican, Puerto Rican, South or Central American, or other Spanish culture or origin regardless of race.';
        const { report } = await runOn(server, '/lever-form.html', 'lever', {
            input: { answers: { race: full } },
        });
        const race = report.fields.find((f) => f.name === 'eeo[race]')!;
        assert.equal(race.status, 'unanswered_optional');
        assert.equal(race.expected, undefined);
    });

    it('answers a non-sensitive descriptive-option question only on an exact option match', async () => {
        const q = 'what is your age range?';
        const ok = await runOn(server, '/lever-form.html', 'lever', {
            input: { answers: { [q]: '30-39' } },
        });
        assert.equal(
            ok.report.fields.find((f) => /age range/.test(f.label ?? ''))
                ?.status,
            'filled_verified',
        );
        const near = await runOn(server, '/lever-form.html', 'lever', {
            input: { answers: { [q]: '30 to 39' } },
        });
        assert.equal(
            near.report.fields.find((f) => /age range/.test(f.label ?? ''))
                ?.status,
            'unanswered_optional',
        );
        assert.equal(
            near.report.fields.find((f) => /age range/.test(f.label ?? ''))
                ?.classification,
            'uncertain',
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
