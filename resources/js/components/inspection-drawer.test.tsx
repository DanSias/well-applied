import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import type { InspectionStarter } from '@/hooks/use-start-inspection';
import {
    diagnosticsOf,
    jobicyNoApplyLinkState,
    jobOf,
    resultOf,
    runOf,
    stateOf,
} from '@/lib/inspection-test-data';
import type { ApplicationInspectionState } from '@/types/application-inspection';
import { InspectionDrawer, workspaceDestination } from './inspection-drawer';

const starterOf = (
    over: Partial<InspectionStarter> = {},
): InspectionStarter => ({
    starting: false,
    start: vi.fn(),
    ...over,
});

const render = (
    inspection: ApplicationInspectionState | null,
    starter: InspectionStarter = starterOf(),
    jobOver: Parameters<typeof jobOf>[1] = {},
) =>
    renderToStaticMarkup(
        <InspectionDrawer
            state="ready"
            job={jobOf(inspection, jobOver)}
            fallback={null}
            destination={workspaceDestination}
            starter={starter}
            onClose={() => {}}
        />,
    );

const question = (position: number, label: string) => ({
    position,
    raw_label: label,
    label_source: 'dom_label_for',
    control_type: 'text',
    required: true,
    options: null,
    section: null,
    extraction_source: 'dom',
});

describe('opening the drawer never starts anything', () => {
    it.each([
        ['no inspection yet', null],
        ['a running inspection', stateOf(runOf('running'))],
        [
            'a saved result',
            stateOf(
                runOf('succeeded'),
                resultOf('complete', {
                    field_count: 1,
                    questions: [question(0, 'First name')],
                }),
            ),
        ],
        ['a failed one', stateOf(runOf('failed'))],
    ])('rendering with %s does not call start', (_name, inspection) => {
        const starter = starterOf();

        render(inspection, starter);

        expect(starter.start).not.toHaveBeenCalled();
    });
});

describe('first inspection', () => {
    it('offers one clearly labelled button, and says nothing has been inspected', () => {
        const html = render(null);

        expect(html).toContain('Not inspected yet');
        expect(html).toContain('Inspect application form');
        expect(html).not.toContain('Technical details');
    });

    it('shows that it is starting, and blocks a second start, between the click and the first poll', () => {
        const html = render(null, starterOf({ starting: true }));

        expect(html).toContain('Starting…');
        expect(html).toMatch(/<button[^>]*disabled[^>]*>[^<]*Queued…/);
    });
});

describe('running and saved inspections', () => {
    it('opens a running inspection on its progress, with the button disabled', () => {
        const html = render(
            stateOf(runOf('running', { started_at: '2026-11-19T12:00:00Z' })),
        );

        expect(html).toContain('Inspecting…');
        expect(html).toMatch(/<button[^>]*disabled[^>]*>[^<]*Inspecting…/);
    });

    it('opens a saved result immediately, with an explicit Inspect again', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('complete', {
                    ats_detected: 'greenhouse',
                    field_count: 2,
                    questions: [
                        question(0, 'First name'),
                        question(1, 'Email'),
                    ],
                }),
            ),
        );

        expect(html).toContain('Inspected — 2 fields');
        expect(html).toContain('First name');
        expect(html).toContain('Inspect again');
        expect(html).not.toContain('Starting…');
    });

    it('offers a deliberate retry after a failure, and explains it as an execution problem', () => {
        const html = render(
            stateOf(
                runOf('failed', {
                    failure_message:
                        'The inspection used its whole 90s time budget while loading the application URL.',
                }),
                null,
                {
                    latest_attempt: {
                        agent_run_id: 1,
                        status: 'failed',
                        failure_category: 'navigation_timeout',
                        diagnostics: diagnosticsOf(),
                    },
                },
            ),
        );

        expect(html).toContain('Retry inspection');
        expect(html).toContain('time budget');
        expect(html).toContain('Worker run failed');
    });
});

describe('truthful explanations', () => {
    it('says a form was found but cannot be read, and does not offer the link prompt as a fix', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('unsupported', {
                    stop_reason: 'form_without_adapter',
                }),
            ),
        );

        expect(html).toContain('Form found, not readable yet');
        expect(html).not.toContain('Unsupported ATS');
        expect(html).not.toContain('<details open');
    });

    it('asks for the link, opened, when no application link was found', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('application_not_found', {
                    stop_reason: 'no_apply_link',
                }),
            ),
        );

        expect(html).toContain('Needs application URL');
        // Prominent and open, not tucked into a collapsed section.
        expect(html).toMatch(
            /<section aria-labelledby="enter-application-url"[^>]*>\s*<h3[^>]*>Enter application URL<\/h3>/,
        );
        expect(html).toContain('Save and inspect');
    });

    it('lists the candidate destinations when there are several, without choosing one', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('needs_application_url', {
                    stop_reason: 'multiple_apply_links',
                    diagnostics: diagnosticsOf({
                        candidates: [
                            {
                                url: 'https://a.example.com/apply',
                                text: 'Apply Now',
                                kind: 'link',
                            },
                            {
                                url: 'https://b.example.com/apply',
                                text: 'Apply on company site',
                                kind: 'link',
                            },
                        ],
                    }),
                }),
            ),
        );

        expect(html).toContain('Several places to apply');
        expect(html).toContain('https://a.example.com/apply');
        expect(html).toContain('https://b.example.com/apply');
    });

    it('says a button-only Apply was not pressed', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('application_not_found', {
                    stop_reason: 'no_apply_link',
                    warnings: ['apply_is_button', 'networkidle_timeout'],
                }),
            ),
        );

        expect(html).toContain('never presses buttons');
        // The raw readiness warning stays in the technical details, not in the main view.
        expect(html.split('Technical details')[0]).not.toContain(
            'networkidle_timeout',
        );
    });
});

describe('a later empty result never hides an earlier useful one', () => {
    it('shows the earlier fields, labelled as earlier, under the latest outcome', () => {
        const earlier = resultOf('complete', {
            agent_run_id: 1,
            field_count: 2,
            questions: [question(0, 'Legal first name'), question(1, 'Phone')],
            finished_at: '2026-11-10T10:00:00Z',
        });
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('blocked', {
                    agent_run_id: 2,
                    stop_reason: 'anti_bot',
                }),
                { earlier_result: earlier },
            ),
        );

        expect(html).toContain('Blocked by the site');
        expect(html).toContain('From an earlier inspection (2)');
        expect(html).toContain('Legal first name');
        expect(html).toContain('may be out of date');
    });
});

describe('the application link and the technical details', () => {
    it('shows where the application is and who chose it, and offers a way back to the listing', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('complete', { field_count: 0 }),
                {
                    application_url: {
                        url: 'https://boards.greenhouse.io/acme/jobs/9',
                        origin: 'user',
                        set_at: null,
                    },
                },
            ),
        );

        expect(html).toContain('https://boards.greenhouse.io/acme/jobs/9');
        expect(html).toContain('(you entered this)');
        expect(html).toContain('Start again from the listing');
        expect(html).toContain('Use a different application URL');
    });

    it('keeps the technical details collapsed, with the URL trace and the page title inside', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('complete', {
                    diagnostics: diagnosticsOf({
                        page_title: 'Careers - Acme',
                        warnings: ['networkidle_timeout'],
                        navigation: [
                            {
                                kind: 'start',
                                url: 'https://jobicy.com/jobs/1',
                                status: 200,
                            },
                            {
                                kind: 'link',
                                url: 'https://boards.greenhouse.io/acme/jobs/9',
                                status: 200,
                            },
                        ],
                    }),
                }),
            ),
        );

        expect(html).toMatch(
            /<details(?![^>]*open)[^>]*>\s*<summary[^>]*>\s*Technical details/,
        );
        expect(html).toContain('Careers - Acme');
        expect(html).toContain('2. link (200)');
        expect(html).toContain('networkidle_timeout');
    });

    it('says so, rather than showing an empty trace, for a record from before URL evidence existed', () => {
        const html = render(
            stateOf(
                runOf('succeeded'),
                resultOf('unsupported', {
                    diagnostics: diagnosticsOf({
                        recorded: false,
                        requested_url: null,
                        final_url: null,
                        navigation: [],
                        start_origin: null,
                        page_title: 'Old page',
                    }),
                }),
            ),
        );

        expect(html).toContain('Unsupported (earlier inspection)');
        expect(html).toContain('Not recorded for this inspection');
        expect(html).toContain('Old page');
    });
});

describe('a listing whose application form was not found (Jobicy job 348, run 11)', () => {
    const html = render(jobicyNoApplyLinkState());
    const main = html.split('Technical details')[0];

    it('says "Needs application URL" and never shows the raw outcome code in the main view', () => {
        expect(main).toContain('Needs application URL');
        expect(main).not.toContain('application_not_found');
        expect(main).not.toContain('no_apply_link');
        // The code is still available, just secondary.
        expect(html.split('Technical details')[1]).toContain(
            'application_not_found / no_apply_link',
        );
    });

    it('explains that the listing was reached but the employer form was not found', () => {
        expect(main).toContain('the listing on jobicy.com');
        expect(main).toContain(
            'application form, so there was nothing to read',
        );
        expect(main).toContain('The inspector itself ran normally');
    });

    it('shows where it started, what application URL is known, and why it stopped', () => {
        expect(main).toContain('Started from');
        expect(main).toContain('The job listing');
        expect(main).toContain(
            'https://jobicy.com/jobs/162959-software-integration-engineer',
        );
        expect(main).toContain('Application URL');
        expect(main).toContain('None yet');
        expect(main).toContain('Why it stopped');
        expect(main).toContain('no link to an application form');
    });

    it('explains that the Apply button could not safely be followed', () => {
        expect(main).toContain('uses an Apply button rather than a link');
        expect(main).toContain('never presses buttons');
    });

    it('shows each repeated warning once with its count, in the technical details only', () => {
        expect(main).not.toContain('blocked_non_get');
        expect(html.split('blocked_non_get').length - 1).toBe(1);
        expect(html).toContain('blocked_non_get ×18');
        expect(html).toContain('apply_is_button');
    });

    it('keeps the worker success distinct from the missing application', () => {
        expect(main).toContain('Needs application URL');
        expect(html.split('Technical details')[1]).toContain(
            'Worker run finished',
        );
        expect(html).not.toContain('Inspection failed');
    });

    it('offers a prominent Enter application URL action, with no second, parallel form', () => {
        expect(main).toMatch(
            /<section aria-labelledby="enter-application-url"/,
        );
        expect(main).toContain('Enter application URL');
        expect(html.split('id="application-url"').length - 1).toBe(1);
    });

    it('starts nothing on its own: the Enter application URL form only renders', () => {
        const start = vi.fn();

        render(jobicyNoApplyLinkState(), starterOf({ start }));

        expect(start).not.toHaveBeenCalled();
    });

    it('shows who set a saved application link', () => {
        const user = render(
            jobicyNoApplyLinkState({
                application_url: {
                    url: 'https://boards.greenhouse.io/plusai/jobs/1',
                    origin: 'user',
                    set_at: null,
                },
            }),
        );
        const found = render(
            jobicyNoApplyLinkState({
                application_url: {
                    url: 'https://boards.greenhouse.io/plusai/jobs/1',
                    origin: 'resolved',
                    set_at: null,
                },
            }),
        );

        expect(user).toContain('(you entered this)');
        expect(found).toContain('(found by an inspection)');
    });
});
