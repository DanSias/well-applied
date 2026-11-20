import { describe, expect, it } from 'vitest';
import type { InspectionResult } from '@/types/application-inspection';
import {
    describeFailure,
    describeLinkOrigin,
    describeOutcome,
    describeStopReason,
    groupWarnings,
    highlightedWarnings,
    hostOf,
    openIntent,
    presentInspection,
    summaryBadge,
} from './inspection-status';
import {
    diagnosticsOf,
    resultOf,
    runOf,
    stateOf,
    summaryOf,
} from './inspection-test-data';

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
    it('says "Needs application URL", not "unsupported", for a listing with no Apply link, and asks for the link', () => {
        const p = presentInspection(
            state(
                run('succeeded'),
                result('application_not_found', 0, 'no_apply_link'),
            ),
        );

        expect(p.headline.label).toBe('Needs application URL');
        expect(p.outcome?.explanation).toMatch(
            /could not find the employer's application form/,
        );
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
        ).toBe('Needs application URL');
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

describe('the listing that had no application form (Jobicy job 348, run 11)', () => {
    const jobicy = diagnosticsOf({
        requested_url:
            'https://jobicy.com/jobs/162959-software-integration-engineer',
        final_url:
            'https://jobicy.com/jobs/162959-software-integration-engineer',
    });
    const noForm = result('application_not_found', 0, 'no_apply_link');

    it('is "Needs application URL", and names the site the inspector did reach', () => {
        const p = presentInspection(
            state(run('succeeded'), { ...noForm, diagnostics: jobicy }),
        );

        expect(p.headline.label).toBe('Needs application URL');
        expect(p.outcome?.explanation).toContain('the listing on jobicy.com');
        expect(p.outcome?.explanation).toMatch(/application form/);
        expect(p.needsApplicationUrl).toBe(true);
    });

    it('stays generic when it does not know the site', () => {
        expect(
            describeOutcome('application_not_found', 'no_apply_link')
                .explanation,
        ).toContain('the job listing');
    });

    it('is still a worker success: the run finished, the application was just not located', () => {
        const p = presentInspection(state(run('succeeded'), noForm));

        expect(p.execution).toEqual({
            label: 'Worker run finished',
            tone: 'ok',
        });
        expect(p.outcome?.tone).toBe('warn');
        expect(p.headline.tone).toBe('warn');
    });

    it('is the same wording in a list row', () => {
        expect(
            summaryBadge(
                summaryOf(
                    'inspected',
                    'application_not_found',
                    0,
                    'no_apply_link',
                ),
            ).label,
        ).toBe(
            presentInspection(state(run('succeeded'), noForm)).headline.label,
        );
    });
});

describe('describeStopReason', () => {
    it.each([
        'no_apply_link',
        'multiple_apply_links',
        'destination_prohibited',
        'destination_unreachable',
        'too_many_redirects',
        'too_many_steps',
        'login_wall',
        'captcha',
        'anti_bot',
        'form_without_adapter',
        'ats_not_extractable',
        'no_fields_extracted',
    ])('explains %s in a sentence', (reason) => {
        const text = describeStopReason(reason);

        expect(text).toMatch(/\.$/);
        expect(text).not.toContain('_');
    });

    it('never guesses: an older record, or a reason it does not know, has no sentence', () => {
        expect(describeStopReason(null)).toBeNull();
        expect(describeStopReason('something_new')).toBeNull();
    });
});

describe('groupWarnings', () => {
    it('shows each code once with its count, in the order first seen', () => {
        const warnings = [
            ...Array.from({ length: 18 }, () => 'blocked_non_get'),
            'apply_is_button',
        ];

        expect(groupWarnings(warnings)).toEqual([
            { code: 'blocked_non_get', count: 18 },
            { code: 'apply_is_button', count: 1 },
        ]);
    });

    it('keeps interleaved codes together without reordering them', () => {
        expect(groupWarnings(['b', 'a', 'b', 'c', 'a', 'b'])).toEqual([
            { code: 'b', count: 3 },
            { code: 'a', count: 2 },
            { code: 'c', count: 1 },
        ]);
    });

    it('is empty for no warnings', () => {
        expect(groupWarnings([])).toEqual([]);
    });
});

describe('highlightedWarnings', () => {
    it('explains only the warnings worth saying, each once, and says an Apply button was not pressed', () => {
        const out = highlightedWarnings([
            'blocked_non_get',
            'apply_is_button',
            'blocked_non_get',
            'apply_is_button',
        ]);

        expect(out.map((w) => w.code)).toEqual(['apply_is_button']);
        expect(out[0].text).toMatch(/Apply button/);
        expect(out[0].text).toMatch(/never presses buttons/);
        expect(out[0].text).toMatch(/automatically/);
    });
});

describe('small helpers', () => {
    it('takes the host from a URL, tolerating a missing or malformed one', () => {
        expect(hostOf('https://www.jobicy.com/jobs/1')).toBe('jobicy.com');
        expect(hostOf('https://jobs.lever.co/acme')).toBe('jobs.lever.co');
        expect(hostOf(null)).toBeNull();
        expect(hostOf('not a url')).toBeNull();
    });

    it('says where a saved application link came from', () => {
        expect(describeLinkOrigin('user')).toBe('you entered this');
        expect(describeLinkOrigin('resolved')).toBe('found by an inspection');
        expect(describeLinkOrigin(null)).toBe('origin not recorded');
    });
});
