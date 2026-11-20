// The PURE half of the context-aware classifier: evidence in, recognised-or-not out. No browser. Every rule that keeps an
// ordinary form from being read as an application is pinned here, one at a time.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { Context, contextFor, isStrongContext, assessApplicationForm, exclusionsFor, APPLICATION_WORDS } from '../src/page-context.js';

const ctl = (label, over = {}) => ({ type: 'text', name: '', id: '', autocomplete: '', accept: '', label, inSearch: false, form: 0, ...over });
const page = (over = {}) => ({
    title: 'Apply - Engineer, Example Co', headings: ['apply: engineer'], text: 'apply for this role', host: 'jobs.example.test', path: '/apply',
    controls: [ctl('Work arrangement', { type: 'select' }), ctl('Preferred location'), ctl('Notice period')], customControls: [], buttons: [{ text: 'Submit application', inForm: true }], sections: [],
    forms: [{ submit: 'Submit application', text: 'work arrangement' }], searchRole: false, ...over,
});
const assess = (signals, context = Context.None) => assessApplicationForm(signals, { context });

describe('context: how the worker came to be on this page', () => {
    test('a followed Apply link is strong context; the first page takes the claim\'s origin', () => {
        assert.equal(contextFor({ hop: 1, startOrigin: 'source' }), Context.ApplyLink);
        assert.equal(contextFor({ hop: 2, startOrigin: null }), Context.ApplyLink);
        assert.equal(contextFor({ hop: 0, startOrigin: 'user' }), Context.UserSupplied);
        assert.equal(contextFor({ hop: 0, startOrigin: 'resolved' }), Context.Resolved);
        assert.equal(contextFor({ hop: 0, startOrigin: 'provider' }), Context.Provider);
    });
    test('a URL found on a job board listing is NOT context, and neither is an unknown origin', () => {
        for (const o of ['source', 'unknown', undefined, null, '']) assert.equal(contextFor({ hop: 0, startOrigin: o }), Context.None, String(o));
        assert.equal(isStrongContext(Context.None), false);
        for (const c of [Context.UserSupplied, Context.ApplyLink, Context.Resolved, Context.Provider]) assert.equal(isStrongContext(c), true);
    });
});

describe('what makes a page an application', () => {
    test('fields alone are never enough, with or without wording', () => {
        const bare = page({ title: 'Form', headings: [], buttons: [], forms: [{ submit: '', text: '' }] });
        assert.equal(assess(bare).recognised, false);
        const wordsOnly = page({ buttons: [], forms: [{ submit: '', text: '' }] });
        assert.equal(assess(wordsOnly).recognised, false, 'wording and fields, but no submit or self-identification section');
    });
    test('without context: fields AND application wording AND an application submit button', () => {
        const r = assess(page());
        assert.deepEqual([r.recognised, r.basis], [true, 'content']);
        assert.deepEqual(r.evidence, ['three_or_more_labelled_fields', 'application_wording', 'application_submit_button']);
    });
    test('without context: a self-identification section can stand in for the submit button', () => {
        const r = assess(page({ buttons: [{ text: 'Continue', inForm: true }], sections: ['Voluntary Self-Identification'] }));
        assert.deepEqual([r.recognised, r.basis], [true, 'content']);
    });
    test('a bare "Submit" is not an application submit, and "Apply filters" never is', () => {
        assert.equal(assess(page({ buttons: [{ text: 'Submit' }] })).recognised, false);
        assert.equal(assess(page({ buttons: [{ text: 'Apply filters' }] })).recognised, false);
        assert.equal(assess(page({ buttons: [{ text: 'Apply now' }] })).recognised, true);
    });
    test('with strong context the fields alone are enough, and the basis says so', () => {
        const bare = page({ title: 'Careers', headings: ['tell us about you'], buttons: [{ text: 'Continue' }], forms: [{ submit: 'Continue', text: '' }] });
        for (const c of [Context.UserSupplied, Context.ApplyLink, Context.Resolved, Context.Provider]) {
            const r = assess(bare, c);
            assert.deepEqual([r.recognised, r.basis], [true, 'context'], c);
        }
        assert.equal(assess(bare, Context.None).recognised, false);
        assert.equal(assess(page(), Context.UserSupplied).basis, 'context_and_content');
    });
    test('context never replaces the fields: a page with fewer than three labelled questions is not read', () => {
        const few = page({ controls: [ctl('Email', { type: 'email' }), ctl('Notice period')] });
        assert.equal(assess(few, Context.UserSupplied).recognised, false);
    });
    test('unlabelled controls do not count, and neither do bare prompts', () => {
        const r = assess(page({ controls: [ctl(''), ctl('Select'), ctl('Choose'), ctl('Notice period')] }), Context.UserSupplied);
        assert.equal(r.recognised, false);
    });
    test('custom widgets count, once per group', () => {
        const p = page({ controls: [], customControls: [ctl('Work authorization', { type: 'radio' }), ctl('Preferred language', { type: 'listbox_button' }), ctl('Preferred office', { type: 'listbox_button' })] });
        assert.equal(assess(p).recognised, true);
    });
    test('the application wording is the existing classifier\'s', () => {
        for (const w of ['apply', 'application', 'applicant', 'candidate', 'resume', 'cv', 'curriculum']) assert.ok(APPLICATION_WORDS.test(`please ${w} here`), w);
        assert.equal(APPLICATION_WORDS.test('careers at example'), false);
    });
});

describe('what a page IS NOT, whatever the context', () => {
    const cases = [
        ['search_or_filter', page({ searchRole: true })],
        ['search_or_filter', page({ buttons: [{ text: 'Search jobs' }, { text: 'Apply filters' }] })],
        ['sign_in', page({ headings: ['sign in'], title: 'Sign in' })],
        ['registration_or_alert_signup', page({ buttons: [{ text: 'Create account' }] })],
        ['registration_or_alert_signup', page({ headings: ['create an account to apply'] })],
        ['registration_or_alert_signup', page({ headings: ['get job alerts'] })],
        ['newsletter_contact_or_other_site_form', page({ buttons: [{ text: 'Subscribe' }] })],
        ['newsletter_contact_or_other_site_form', page({ buttons: [{ text: 'Send message' }] })],
        ['newsletter_contact_or_other_site_form', page({ headings: ['request a quote'] })],
        ['cookie_or_consent', page({ buttons: [{ text: 'Accept all' }, { text: 'Save preferences' }] })],
    ];
    for (const [why, signals] of cases) {
        test(`${why}: not recognised, even with strong context`, () => {
            for (const c of [Context.None, Context.UserSupplied, Context.ApplyLink]) {
                const r = assess(signals, c);
                assert.equal(r.recognised, false, c);
                assert.ok(r.excluded.includes(why), `${why} in ${r.excluded}`);
            }
        });
    }
    test('a password field excludes it and is reported, so the caller can call it a sign-in barrier', () => {
        const p = page({ controls: [ctl('Email', { type: 'email' }), ctl('Password', { type: 'password' }), ctl('Country', { type: 'select' }), ctl('Language', { type: 'select' })] });
        assert.deepEqual(exclusionsFor(p).includes('password_field'), true);
        assert.equal(assess(p, Context.UserSupplied).recognised, false);
    });
    test('a header "Sign in" button does not hide a real application (an application submit button is present)', () => {
        const r = assess(page({ buttons: [{ text: 'Sign in' }, { text: 'Submit application' }] }));
        assert.equal(r.excluded.includes('sign_in'), false);
        assert.equal(r.recognised, true);
    });
    test('a lone "Sign in" button with no application button is a sign-in', () => {
        assert.ok(assess(page({ buttons: [{ text: 'Sign in' }] })).excluded.includes('sign_in'));
    });
    test('a field-less page, or one whose only controls are search, has nothing to read', () => {
        assert.equal(assess(page({ controls: [] }), Context.UserSupplied).recognised, false);
        assert.equal(assess(page({ controls: [ctl('q', { inSearch: true })] }), Context.UserSupplied).recognised, false);
    });
});

describe('barrier text is reported so a barrier can win', () => {
    test('a human-verification notice on an otherwise recognised page sets barrier', () => {
        const r = assess(page({ text: 'verify you are human to continue' }), Context.UserSupplied);
        assert.deepEqual([r.recognised, r.barrier], [true, true]);
    });
    test('an ordinary page does not', () => assert.equal(assess(page()).barrier, false));
});

describe('deterministic and pure', () => {
    test('the same signals always give the same answer, and the input is not modified', () => {
        const p = page();
        const before = JSON.stringify(p);
        assert.deepEqual(assess(p, Context.ApplyLink), assess(p, Context.ApplyLink));
        assert.equal(JSON.stringify(p), before);
    });
});
