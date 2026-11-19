import { describe, expect, it } from 'vitest';
import type { InspectionResult } from '@/types/application-inspection';
import {
    describeFailure,
    describeOutcome,
    openIntent,
    presentInspection,
    summaryBadge,
} from './inspection-status';
import { resultOf, runOf, stateOf, summaryOf } from './inspection-test-data';

const run = runOf;
const result = (
    outcome: string,
    fields = 0,
    stop: string | null = null,
): InspectionResult =>
    resultOf(outcome, { field_count: fields, stop_reason: stop });
const state = stateOf;

describe('presentInspection', () => {
    it('is idle when nothing has run', () => {
        const p = presentInspection(null);

        expect(p.active).toBe(false);
        expect(p.headline.label).toBe('Not inspected yet');
        expect(p.action).toBe('Inspect application form');
    });

    it('treats queued and running as active, without claiming success', () => {
        expect(presentInspection(state(run('pending'))).headline.label).toBe(
            'Inspection queued',
        );

        const running = presentInspection(state(run('running')));

        expect(running.active).toBe(true);
        expect(running.headline.label).toBe('Inspecting…');
        expect(running.outcome).toBeNull();
    });

    it('reports a form that was reached but cannot be read as exactly that, not as a failure, a success or a missing application', () => {
        const p = presentInspection(
            state(
                run('succeeded'),
                result('unsupported', 0, 'form_without_adapter'),
            ),
        );

        expect(p.execution).toEqual({
            label: 'Worker run finished',
            tone: 'ok',
        });
        expect(p.headline).toEqual({
            label: 'Form found, not readable yet',
            tone: 'warn',
        });
        expect(p.outcome?.explanation).toMatch(
            /reached what looks like an application form/,
        );
        expect(p.outcome?.explanation).not.toMatch(/no application/i);
        expect(p.needsApplicationUrl).toBe(false);
        expect(p.action).toBe('Inspect again');
    });

    it('does not reinterpret an older unsupported record that has no stop reason', () => {
        const p = presentInspection(
            state(run('succeeded'), result('unsupported')),
        );

        expect(p.headline.label).toBe('Unsupported (earlier inspection)');
        expect(p.headline.tone).toBe('neutral');
        expect(p.outcome?.explanation).toMatch(
            /cannot say whether an application form was found/,
        );
    });

    it('counts fields for a complete inspection', () => {
        expect(
            presentInspection(state(run('succeeded'), result('complete', 1)))
                .headline.label,
        ).toBe('Inspected — 1 field');
        expect(
            presentInspection(state(run('succeeded'), result('complete', 12)))
                .headline.label,
        ).toBe('Inspected — 12 fields');
    });

    it('names a failed run, and offers a retry', () => {
        const p = presentInspection(state(run('failed')));

        expect(p.execution.tone).toBe('bad');
        expect(p.headline.label).toBe('Inspection failed');
        expect(p.action).toBe('Retry inspection');
    });

    it('keeps an earlier result visible when the latest attempt failed', () => {
        const p = presentInspection(
            state(run('failed'), result('complete', 4)),
        );

        expect(p.outcomeIsEarlier).toBe(true);
        expect(p.headline.label).toBe('Inspected — 4 fields');
        expect(p.execution.label).toBe('Worker run failed');
    });

    it.each([
        'partial',
        'authentication_required',
        'mutation_required',
        'application_not_found',
        'needs_application_url',
        'blocked',
    ])('shows %s as its own outcome', (outcome) => {
        const p = presentInspection(state(run('succeeded'), result(outcome)));

        expect(p.outcome?.tone).toBe('warn');
        expect(p.headline.tone).toBe('warn');
    });
});

describe('summaryBadge', () => {
    const summary = summaryOf;

    it('counts questions only when usable fields were extracted', () => {
        expect(summaryBadge(summary('inspected', 'complete', 7))).toEqual({
            label: 'Inspected · 7 questions',
            tone: 'ok',
        });
        expect(summaryBadge(summary('inspected', 'complete', 1)).label).toBe(
            'Inspected · 1 question',
        );
        expect(summaryBadge(summary('inspected', 'partial', 3))).toEqual({
            label: 'Partial · 3 questions',
            tone: 'warn',
        });
    });

    it('never says Inspected for an outcome with no usable fields', () => {
        expect(
            summaryBadge(summary('inspected', 'authentication_required', 0)),
        ).toEqual({ label: 'Sign-in required', tone: 'warn' });
        expect(
            summaryBadge(summary('inspected', 'mutation_required', 0)).label,
        ).toBe('Needs interaction');
        expect(summaryBadge(summary('inspected', 'complete', 0)).label).toBe(
            'Nothing extracted',
        );
        expect(summaryBadge(summary('unsupported', 'unsupported', 0))).toEqual({
            label: 'Unsupported (earlier inspection)',
            tone: 'neutral',
        });
    });

    it('keeps execution states as execution states', () => {
        expect(summaryBadge(summary('failed')).label).toBe('Inspection failed');
        expect(summaryBadge(summary('queued')).label).toBe('Inspection queued');
        expect(summaryBadge(summary('running')).label).toBe('Inspecting…');
        expect(summaryBadge(summary('not_inspected')).tone).toBe('neutral');
    });
});

describe('truthful outcomes', () => {
    it('says "no application link", not "unsupported", for a listing with no Apply link, and asks for the link', () => {
        const p = presentInspection(
            state(
                run('succeeded'),
                result('application_not_found', 0, 'no_apply_link'),
            ),
        );

        expect(p.headline.label).toBe('No application link found');
        expect(p.outcome?.explanation).toMatch(/could not find an Apply link/);
        expect(p.needsApplicationUrl).toBe(true);
    });

    it.each([
        ['multiple_apply_links', 'Several places to apply', /never choose/],
        [
            'destination_prohibited',
            'Destination not allowed',
            /Nothing was opened there/,
        ],
        ['destination_unreachable', "Destination didn't load", /did not load/],
        ['too_many_redirects', 'Too many redirects', /loop/],
        ['too_many_steps', 'Application not reached', /two/],
    ])('needs_application_url / %s', (stop, label, words) => {
        const o = describeOutcome('needs_application_url', stop);

        expect(o.label).toBe(label);
        expect(o.explanation).toMatch(words);
        expect(
            presentInspection(
                state(
                    run('succeeded'),
                    result('needs_application_url', 0, stop),
                ),
            ).needsApplicationUrl,
        ).toBe(true);
    });

    it('tells a CAPTCHA from an anti-bot block, and a sign-in wall from both', () => {
        expect(describeOutcome('blocked', 'captcha').label).toBe(
            'Blocked by a CAPTCHA',
        );
        expect(describeOutcome('blocked', 'anti_bot').label).toBe(
            'Blocked by the site',
        );
        expect(
            describeOutcome('authentication_required', 'login_wall').label,
        ).toBe('Sign-in required');
        expect(describeOutcome('blocked', 'anti_bot').explanation).toMatch(
            /Nothing was bypassed/,
        );
    });

    it('distinguishes a Greenhouse form that read nothing from a form that cannot be read', () => {
        expect(describeOutcome('partial', 'no_fields_extracted').label).toBe(
            'Form found, no fields read',
        );
        expect(
            describeOutcome('unsupported', 'ats_not_extractable').explanation,
        ).toMatch(/Lever/);
    });

    it('does not offer the link prompt for a result that is only an earlier run, or while one is running', () => {
        expect(
            presentInspection(
                state(
                    run('failed'),
                    result('application_not_found', 0, 'no_apply_link'),
                ),
            ).needsApplicationUrl,
        ).toBe(false);
        expect(
            presentInspection(
                state(
                    run('running'),
                    result('application_not_found', 0, 'no_apply_link'),
                ),
            ).needsApplicationUrl,
        ).toBe(false);
    });

    it('labels list rows with the same wording', () => {
        expect(
            summaryBadge(
                summaryOf(
                    'inspected',
                    'application_not_found',
                    0,
                    'no_apply_link',
                ),
            ).label,
        ).toBe('No application link found');
        expect(
            summaryBadge(
                summaryOf(
                    'inspected',
                    'unsupported',
                    0,
                    'form_without_adapter',
                ),
            ).label,
        ).toBe('Form found, not readable yet');
        expect(
            summaryBadge(
                summaryOf('inspected', 'partial', 0, 'no_fields_extracted'),
            ).label,
        ).toBe('Form found, no fields read');
        expect(
            summaryBadge(summaryOf('inspected', 'blocked', 0, 'anti_bot'))
                .label,
        ).toBe('Blocked by the site');
    });
});

describe('failures', () => {
    it('explains a timeout and a lapsed claim as execution problems, not verdicts on the application', () => {
        expect(
            describeFailure(
                'navigation_timeout',
                'Timed out loading the application URL.',
            ),
        ).toMatch(/Timed out/);
        expect(describeFailure('navigation_timeout', null)).toMatch(
            /ran out of time/,
        );
        expect(describeFailure('claim_timeout', null)).toMatch(
            /never reported back/,
        );
        expect(describeFailure(null, null)).toMatch(/did not finish/);
    });
});

describe('what a click on "Inspect" does', () => {
    const url = 'https://jobicy.com/jobs/1';

    it('starts the first inspection of a job that has none, and only that', () => {
        expect(openIntent(summaryOf('not_inspected'), url)).toBe('start');
    });

    it.each([
        'queued',
        'running',
        'inspected',
        'failed',
        'unsupported',
    ] as const)(
        'only opens what is already there when the state is %s',
        (st) => {
            expect(openIntent(summaryOf(st), url)).toBe('view');
        },
    );

    it('only opens a job with no link to start from', () => {
        expect(openIntent(summaryOf('not_inspected'), null)).toBe('view');
    });
});
