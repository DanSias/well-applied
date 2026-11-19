// The page classifier's rules, from plain signal objects: no browser, so every rule can be exercised directly. The
// principle under test is conservatism — one STRONG signal makes a form, weak ones never do.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { classifyPage } from '../src/page-kind.js';

const control = (over = {}) => ({ type: 'text', name: '', id: '', autocomplete: '', accept: '', label: '', inSearch: false, form: 0, ...over });
const signals = (over = {}) => ({
    title: 'A page',
    host: 'example.com',
    path: '/',
    text: '',
    headings: [],
    controls: [],
    forms: [{ submit: '', text: '' }],
    captchaChallengeVisible: false,
    greenhouseAssets: false,
    leverAssets: false,
    ...over,
});

describe('a single strong signal establishes an application form', () => {
    test('a resume upload alone', () => {
        const r = classifyPage(signals({ controls: [control({ type: 'file', name: 'resume', label: 'Resume' })] }));
        assert.equal(r.kind, 'form');
        assert.deepEqual(r.reasons, ['resume_upload']);
    });

    test('a CV or cover-letter file input counts the same', () => {
        for (const label of ['Upload your CV', 'Cover letter']) {
            assert.equal(classifyPage(signals({ controls: [control({ type: 'file', label })] })).kind, 'form', label);
        }
    });

    test('name and email fields together on a page that is about an application', () => {
        const r = classifyPage(signals({
            title: 'Apply - Engineer',
            controls: [control({ label: 'First name', name: 'first_name' }), control({ type: 'email', label: 'Email' })],
        }));
        assert.equal(r.kind, 'form');
        assert.deepEqual(r.reasons, ['contact_fields_on_application_page']);
    });

    test('a Greenhouse host with any control, and a Greenhouse job page even before its fields render', () => {
        assert.equal(classifyPage(signals({ host: 'boards.greenhouse.io', path: '/acme/jobs/1', controls: [control()] })).kind, 'form');
        const unrendered = classifyPage(signals({ host: 'job-boards.greenhouse.io', path: '/acme/jobs/123' }));
        assert.equal(unrendered.kind, 'form');
        assert.equal(unrendered.ats, 'greenhouse');
    });

    test('a Lever apply page with a control is identified as Lever', () => {
        const r = classifyPage(signals({ host: 'jobs.lever.co', path: '/acme/uuid/apply', controls: [control()] }));
        assert.equal(r.kind, 'form');
        assert.equal(r.ats, 'lever');
    });

    test('a form does not need three fields', () => {
        assert.equal(classifyPage(signals({ controls: [control({ type: 'file', label: 'Resume' })] })).kind, 'form');
    });
});

describe('weak signals are never promoted', () => {
    test('a lone text box, a search field, the word "apply" and a Greenhouse asset alone are not a form', () => {
        assert.equal(classifyPage(signals({ controls: [control({ label: 'Job title' })], text: 'apply now to join us' })).kind, 'listing');
        assert.equal(classifyPage(signals({ controls: [control({ type: 'search', inSearch: true })] })).kind, 'listing');
        assert.equal(classifyPage(signals({ greenhouseAssets: true, text: 'apply for this job' })).kind, 'listing');
    });

    test('name and email on a newsletter, a contact form, or a page that never mentions applying', () => {
        const fields = [control({ label: 'Name', name: 'name' }), control({ type: 'email', label: 'Email' })];
        assert.equal(classifyPage(signals({ title: 'Careers', text: 'apply today', controls: fields, forms: [{ submit: 'Subscribe', text: 'newsletter' }] })).kind, 'listing');
        assert.equal(classifyPage(signals({ title: 'Apply', controls: fields, forms: [{ submit: 'Send message', text: 'contact us' }] })).kind, 'listing');
        assert.equal(classifyPage(signals({ title: 'About us', text: 'our story', controls: fields })).kind, 'listing');
    });

    test('a file input that is not a resume', () => {
        assert.equal(classifyPage(signals({ controls: [control({ type: 'file', label: 'Profile photo', name: 'avatar' })] })).kind, 'listing');
    });
});

describe('barriers', () => {
    test('403 and 429 are blocked; a challenge or captcha wording makes it a captcha', () => {
        assert.deepEqual([classifyPage(signals(), { status: 403 }).kind, classifyPage(signals(), { status: 403 }).stopReason], ['blocked', 'anti_bot']);
        assert.equal(classifyPage(signals({ text: 'please verify you are human' }), { status: 429 }).stopReason, 'captcha');
        assert.equal(classifyPage(signals({ captchaChallengeVisible: true }), { status: 200 }).stopReason, 'captcha');
    });

    test('block-page wording on a near-empty page, with or without an error status', () => {
        const r = classifyPage(signals({ title: 'Access Denied', text: 'access denied you do not have permission' }), { status: 200 });
        assert.deepEqual([r.kind, r.stopReason], ['blocked', 'anti_bot']);
        assert.equal(classifyPage(signals({ title: 'Just a moment...', text: 'checking your browser' })).kind, 'blocked');
    });

    test('a long, ordinary page that merely mentions "captcha" or "access denied" is not blocked', () => {
        const text = 'we protect forms with a captcha. access denied errors mean ... '.repeat(40);
        assert.equal(classifyPage(signals({ text, controls: [control()] })).kind, 'listing');
    });

    test('a real form is not blocked just because it carries captcha markup or says so in small print', () => {
        const form = signals({ text: 'this site is protected by captcha', controls: [control({ type: 'file', label: 'Resume' })] });
        assert.equal(classifyPage(form).kind, 'form');
        assert.equal(classifyPage({ ...form, captchaChallengeVisible: true }).kind, 'form', 'a usable form wins over a visible challenge');
    });

    test('other error statuses are unreachable, not blocked', () => {
        for (const status of [404, 410, 500, 503]) {
            const r = classifyPage(signals(), { status });
            assert.deepEqual([r.kind, r.stopReason], ['unreachable', 'destination_unreachable'], String(status));
        }
    });

    test('a password field with little else is a login wall; a password beside a resume upload is a form', () => {
        assert.equal(classifyPage(signals({ controls: [control({ type: 'email' }), control({ type: 'password' })] })).kind, 'login');
        assert.equal(classifyPage(signals({ controls: [control({ type: 'password' }), control({ type: 'file', label: 'Resume' })] })).kind, 'form');
    });

    test('a sign-in page without a password field yet', () => {
        assert.equal(classifyPage(signals({ headings: ['sign in to apply'], controls: [control({ type: 'email' })] })).kind, 'login');
    });
});

test('a page with nothing on it is a listing, with the reason recorded', () => {
    const r = classifyPage(signals());
    assert.deepEqual([r.kind, r.reasons], ['listing', ['no_form_controls']]);
});
