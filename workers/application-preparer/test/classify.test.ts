import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { classify, looksLikeQuestion } from '../src/classify.ts';
import type { DiscoveredField, PrepareInput } from '../src/contracts.ts';

const input: PrepareInput = {
    schemaVersion: 1,
    applicationUrl: 'https://jobs.lever.co/x/1',
    resumePdfPath: '/x.pdf',
    applicant: {
        first_name: 'Alex',
        last_name: 'Example',
        email: 'alex.example@example.test',
        phone: '+1 555 010 0199',
        location: 'Springfield',
        current_company: 'Example Co',
    },
};

const field = (over: Partial<DiscoveredField>): DiscoveredField => ({
    id: 'f0',
    autocomplete: null,
    key: 'k',
    occurrence: 0,
    ambiguous: false,
    position: 0,
    kind: 'text',
    tag: 'input',
    inputType: 'text',
    name: null,
    domId: null,
    label: null,
    labelSource: 'dom_label_for',
    required: false,
    options: null,
    sensitive: false,
    ...over,
});
const c = (label: string | null, over: Partial<DiscoveredField> = {}) =>
    classify(field({ label, ...over }), input);

describe('looksLikeQuestion', () => {
    it('recognises questions, instructions and long sentences', () => {
        for (const l of [
            'Are you legally authorized to work in the United States?',
            'Do you have a relative at our company',
            'Please describe your experience',
            'Tell us about your address history',
            'What is your current employer',
            'Describe the company you would like to build today',
        ]) {
            assert.equal(looksLikeQuestion(l.toLowerCase()), true, l);
        }
    });
    it('does not treat short field names as questions', () => {
        for (const l of [
            'company',
            'preferred name',
            'current company name',
            'phone number (mobile)',
            'e-mail address',
            'location (city)',
        ]) {
            assert.equal(looksLikeQuestion(l), false, l);
        }
    });
});

describe('custom questions that mention standard-field words are NOT possible contact fields', () => {
    for (const label of [
        'Are you legally authorized to work in the United States for our Company?',
        'Do you currently have a relative who works for our company?',
        'Have you ever been employed by the Company before?',
        'Please describe your relationship with the employer',
        'What is the best phone number and time to reach you? Please explain',
        'Why do you want to work at our company?',
        'Please confirm your current address and location history',
        'Which location would you prefer to work from?',
        'If you are located outside the US, what is your visa name?',
    ]) {
        it(`unknown, not uncertain: ${label}`, () => {
            const r = c(label);
            assert.equal(r.classification, 'unknown');
            assert.equal(r.mappedTo, null);
            assert.equal(r.note, undefined);
        });
    }

    it('REGRESSION (Discord): "...for our Company?" is no longer "uncertain", and so is never confused with Current company', () => {
        const r = c(
            'Are you legally authorized to work in the United States for our Company? *',
            { required: true },
        );
        assert.equal(r.classification, 'unknown');
    });
});

describe('short labels that really resemble a standard field are still held back as uncertain', () => {
    for (const label of [
        'Preferred name',
        'Company name',
        'Current company name',
        'Mobile phone number',
        'Work e-mail address',
        'Home address',
    ]) {
        it(`uncertain: ${label}`, () => {
            const r = c(label);
            assert.equal(r.classification, 'uncertain');
            assert.equal(r.mappedTo, null);
        });
    }
});

describe('exact standard fields are still recognised', () => {
    it('by exact label', () => {
        assert.equal(c('Current company').mappedTo, 'current_company');
        assert.equal(c('Email').mappedTo, 'email');
        assert.equal(c('First Name *').mappedTo, 'first_name');
        assert.equal(c('Phone').mappedTo, 'phone');
    });
    it('by the observed name attribute', () => {
        assert.equal(c(null, { name: 'org' }).mappedTo, 'current_company');
        assert.equal(c('Anything', { name: 'email' }).mappedTo, 'email');
    });
    it('by input type', () => {
        assert.equal(
            c('Your details', { inputType: 'email' }).mappedTo,
            'email',
        );
        assert.equal(c('Your details', { inputType: 'tel' }).mappedTo, 'phone');
    });
});

describe('autocomplete tokens are structural evidence; "off" and unknown tokens are ignored', () => {
    it('maps the standardised tokens', () => {
        const cases: Array<[string, string]> = [
            ['given-name', 'first_name'],
            ['family-name', 'last_name'],
            ['name', 'full_name'],
            ['email', 'email'],
            ['tel', 'phone'],
            ['tel-national', 'phone'],
            ['organization', 'current_company'],
        ];
        for (const [token, key] of cases)
            assert.equal(
                c('Your details', { autocomplete: token }).mappedTo,
                key,
                token,
            );
    });
    it('uses the last token of a sectioned value', () => {
        assert.equal(
            c('Your details', { autocomplete: 'section-apply shipping email' })
                .mappedTo,
            'email',
        );
    });
    it('confirms a matching label', () => {
        const r = c('Company', { autocomplete: 'organization' });
        assert.equal(r.classification, 'known');
        assert.equal(r.mappedTo, 'current_company');
    });
    it('a conflict between structural evidence and the label is refused, not resolved by guessing', () => {
        const r = c('Phone', { autocomplete: 'email' });
        assert.equal(r.classification, 'uncertain');
        assert.match(r.note!, /conflicting matches/);
    });
    it('ignores "off", "on" and tokens with no applicant meaning', () => {
        for (const token of [
            'off',
            'on',
            'nope',
            'address-level2',
            'country',
            'one-time-code',
        ]) {
            assert.equal(
                c('Your details', { autocomplete: token }).classification,
                'unknown',
                token,
            );
        }
    });
    it('does not apply to non-text controls', () => {
        assert.equal(
            classify(
                field({
                    kind: 'select',
                    label: 'Your details',
                    autocomplete: 'email',
                    options: ['a'],
                }),
                input,
            ).mappedTo,
            null,
        );
    });
});
