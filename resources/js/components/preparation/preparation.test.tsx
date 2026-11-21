import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import {
    attemptRow,
    panelOf,
    reviewOf,
    stateOf,
} from '@/lib/preparation-test-data';
import type { PreparationPanel } from '@/types/application-preparation';
import { PreparationDrawer } from './preparation-drawer';
import { PreparationReviewView } from './preparation-review';
import { PreparationSection } from './preparation-section';
import { ScreenshotGallery } from './screenshot-gallery';

const prepFor = (panel: PreparationPanel, over: Record<string, unknown> = {}) =>
    ({
        panel,
        active: false,
        busy: null,
        error: null,
        blocked: null,
        clearError: () => {},
        start: vi.fn(),
        cancel: vi.fn(),
        refresh: vi.fn(),
        ...over,
    }) as never;

const drawer = (panel: PreparationPanel, over: Record<string, unknown> = {}) =>
    renderToStaticMarkup(
        <PreparationDrawer
            prep={prepFor(panel, over)}
            title="Fixture Engineer"
            company="Example Co"
            onClose={() => {}}
        />,
    );

describe('the review', () => {
    it('leads with the outcome, says outright that nothing was submitted, and gives the counts', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView review={reviewOf()} />,
        );

        expect(html).toContain('Needs your input');
        expect(html).toContain('Not submitted.');
        expect(html).toContain(
            'sending it is a separate step that has not happened',
        );
        expect(html).toContain('Filled and verified');
        expect(html).toContain('Need your answer');
        expect(html).toContain('Why do you want to join Example?');
        expect(html).toContain('Location (City)');
    });

    it('shows the resume identity and the upload evidence in words', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView review={reviewOf()} />,
        );

        expect(html).toContain('version 7');
        expect(html).toContain('aaaaaaaaaaaa…');
        expect(html).toContain('Verified');
        expect(html).toContain(
            'The site accepted the upload and the file name appears on the form.',
        );
        expect(html).toContain('HTTP 201');
        expect(html).toContain(
            'exact file snapshotted when the attempt started',
        );
    });

    it('does not call an unverified resume verified', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView
                review={reviewOf({
                    resume: {
                        variant_id: 7,
                        sha256: 'b'.repeat(64),
                        bytes: 1,
                        verified: false,
                        reported_sha256_matches: false,
                        upload: {
                            outcome: 'no_upload_request',
                            http_status: null,
                            filename_visible: false,
                            detail: null,
                        },
                    },
                })}
            />,
        );

        expect(html).toContain('Not verified');
        expect(html).toContain('the page did not send it');
        expect(html).toContain('not the one it was given');
    });

    it('handles a question with no label gracefully instead of borrowing one', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView
                review={reviewOf({
                    needs_answer: [
                        {
                            label: null,
                            required: true,
                            kind: 'checkbox',
                            status: 'unresolved_required',
                            review: 'needs_answer',
                            sensitive: false,
                        },
                    ],
                })}
            />,
        );

        expect(html).toContain(
            'Unlabelled question (the form gave it no label)',
        );
    });

    it('shows safety events prominently for a safety failure, and never as ready', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView
                review={reviewOf({
                    outcome: 'safety_failure',
                    reviewable: false,
                    safety_events: [
                        {
                            kind: 'blocked_mutating_request',
                            detail: 'POST https://x.test/apply',
                            failure: true,
                        },
                    ],
                })}
            />,
        );

        expect(html).toContain('Stopped for safety');
        expect(html).toContain('role="alert"');
        expect(html).toContain(
            'blocked mutating request: POST https://x.test/apply',
        );
        expect(html).not.toContain('Ready for review');
        expect(html).toContain('Not submitted.');
    });

    it('keeps routine background requests out of the alarm, as a calm technical note', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView review={reviewOf()} />,
        );

        expect(html).not.toContain('role="alert"');
        expect(html).toContain('background request');
        expect(html).toContain('none was a submission');
    });

    it('explains an attempt with no report instead of showing an empty review', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView
                review={
                    {
                        agent_run_id: 1,
                        status: 'failed',
                        submission: reviewOf().submission,
                        resume: reviewOf().resume,
                        available: false,
                        failure_category: 'navigation_timeout',
                        failure_message: 'page never loaded',
                    } as never
                }
            />,
        );

        expect(html).toContain('delivered no report');
        expect(html).toContain('page never loaded');
        expect(html).toContain('Not submitted.');
    });

    it('lists screenshots in order, links the stored ones privately, and marks a missing one', () => {
        const html = renderToStaticMarkup(
            <ScreenshotGallery
                screenshots={
                    reviewOf().available
                        ? (reviewOf() as never as { screenshots: never })
                              .screenshots
                        : []
                }
            />,
        );

        expect(html).toContain(
            'src="/preparation-attempts/1/screenshots/before-01of02-y00000.png"',
        );
        expect(html).toContain('Before filling, part 1 of 2');
        expect(html).toContain('aria-label="Screenshots in order"');
        expect(html).toContain('line-through');
        expect(html).not.toContain('/storage/');
    });

    it('says so when no screenshots were kept', () => {
        expect(
            renderToStaticMarkup(<ScreenshotGallery screenshots={[]} />),
        ).toContain('No screenshots were kept');
    });

    it('never renders a typed value: the review has none to render', () => {
        const html = renderToStaticMarkup(
            <PreparationReviewView review={reviewOf()} />,
        );
        expect(html).not.toContain('@example.test');
    });
});

describe('the drawer: starting', () => {
    it('shows the job, the destination and the default resume before anything is dispatched', () => {
        const html = drawer(panelOf(null));

        expect(html).toContain('Prepare application');
        expect(html).toContain('Fixture Engineer at Example Co');
        expect(html).toContain('job-boards.greenhouse.io/example/jobs/123456');
        expect(html).toContain('Your final resume is selected.');
        expect(html).toContain('Generated resume 1');
        expect(html).toContain('Revision of resume 1');
        expect(html).toContain('copied when you start');
        expect(html).toContain('Preparation only · nothing is submitted');
        expect(html).toContain('checked');
    });

    it('falls back to the current resume and says so', () => {
        const html = drawer(
            panelOf(null, {
                resumes: { ...panelOf(null).resumes, default_kind: 'current' },
            }),
        );
        expect(html).toContain(
            'No resume is marked final, so your current one is selected.',
        );
    });

    it('refuses with an actionable reason instead of offering a start', () => {
        const html = drawer(
            panelOf(null, {
                can_prepare: false,
                problem: {
                    code: 'no_resume',
                    message:
                        'This job has no tailored resume yet. Generate one first; a resume is never chosen or created for you.',
                },
            }),
        );

        expect(html).toContain('Preparation cannot start');
        expect(html).toContain('a resume is never chosen or created for you');
        expect(html).not.toContain('>Prepare application</button>');
    });

    it('distinguishes inspection, preparation and submission', () => {
        const html = drawer(panelOf(null));
        expect(html).toContain('Inspection');
        expect(html).toContain('Preparation (this)');
        expect(html).toContain('Submission');
        expect(html).toContain('has not happened');
    });
});

describe('the drawer: progress', () => {
    it('shows a queued attempt with elapsed time, cancel, and no start button', () => {
        const html = drawer(panelOf(stateOf('queued')), { active: true });

        expect(html).toContain('Waiting for a worker');
        expect(html).toContain('Queued for');
        expect(html).toContain('Cancel this attempt');
        expect(html).toContain('Preparation worker offline');
        expect(html).not.toContain('>Prepare application</button>');
    });

    it('keeps worker availability and attempt status separate', () => {
        const html = drawer(panelOf(stateOf('queued')), { active: true });
        // The attempt's own state is "Queued"; the worker line is its own sentence about the worker.
        expect(html).toContain('>Queued<');
        expect(html).toContain('Preparation worker');
    });

    it('shows the real phase for a running attempt, with no cancel and no percentage', () => {
        const html = drawer(panelOf(stateOf('running', { phase: 'filling' })), {
            active: true,
        });

        expect(html).toContain('Filling in your details');
        expect(html).toContain('Running for');
        expect(html).not.toContain('Cancel this attempt');
        expect(html).not.toMatch(/\d+\s?%/);
    });
});

describe('the drawer: results and history', () => {
    it('does not leave a cancelled attempt looking queued, and offers a new attempt', () => {
        const html = drawer(panelOf(stateOf('cancelled')));

        expect(html).toContain('>Cancelled<');
        expect(html).not.toContain('>Queued<');
        expect(html).not.toContain('Cancel this attempt');
        expect(html).toContain('You cancelled this attempt before it started');
        expect(html).toContain('Start a new attempt');
    });

    it('shows a failed execution with its reason and an explicit new attempt, never an automatic retry', () => {
        const html = drawer(
            panelOf(stateOf('failed', { failure: 'claim_timeout' })),
        );

        expect(html).toContain('>Failed<');
        expect(html).toContain('stopped responding');
        expect(html).toContain('not retried automatically');
        expect(html).toContain('Start a new attempt');
    });

    it('offers Prepare again after a finished attempt, beside its review', () => {
        const html = drawer(
            panelOf(stateOf('succeeded', { outcome: 'input_required' })),
        );

        expect(html).toContain('Needs your input');
        expect(html).toContain('Latest attempt');
        expect(html).toContain('Prepare again');
        expect(html).toContain('Loading the review');
    });

    it('keeps earlier attempts on their own, each with its own resume, and says none is a submission', () => {
        const attempts = [
            attemptRow({
                agent_run_id: 2,
                workflow_run_id: 12,
                outcome: 'ready_for_review',
                resume_variant_id: 8,
            }),
            attemptRow({
                agent_run_id: 1,
                workflow_run_id: 11,
                status: 'failed',
                outcome: null,
                resume_variant_id: 7,
            }),
        ];
        const html = drawer(
            panelOf(
                stateOf('succeeded', {
                    id: 2,
                    outcome: 'ready_for_review',
                    attempts,
                }),
            ),
        );

        expect(html).toContain('Earlier attempts');
        expect(html).toContain('(1)');
        expect(html).toContain('resume version 7');
        expect(html).toContain('None of them is a submitted application');
        // The failed earlier attempt has nothing to view.
        expect(html).not.toContain('>View<');
    });

    it('lets a person open an earlier reviewable attempt', () => {
        const attempts = [
            attemptRow({
                agent_run_id: 2,
                workflow_run_id: 12,
                outcome: 'ready_for_review',
            }),
            attemptRow({
                agent_run_id: 1,
                workflow_run_id: 11,
                outcome: 'input_required',
            }),
        ];
        const html = drawer(
            panelOf(
                stateOf('succeeded', {
                    id: 2,
                    outcome: 'ready_for_review',
                    attempts,
                }),
            ),
        );
        expect(html).toContain('>View<');
    });
});

describe('the workspace section', () => {
    const section = (panel: PreparationPanel) =>
        renderToStaticMarkup(
            <PreparationSection
                panel={panel}
                title="Fixture Engineer"
                company="Example Co"
            />,
        );

    it('is its own section, apart from inspection and the recorded applications, and never says submitted', () => {
        const html = section(panelOf(null));

        expect(html).toContain('id="preparation"');
        expect(html).toContain('Application preparation');
        expect(html).toContain('Not started');
        expect(html).toContain('It never submits anything.');
        expect(html).toContain('Prepare application');
        expect(html).not.toMatch(/inspect/i);
    });

    it('shows the live status on the section, and the right button', () => {
        expect(section(panelOf(stateOf('queued')))).toContain('View progress');
        expect(section(panelOf(stateOf('queued')))).toContain('>Queued<');
        expect(
            section(panelOf(stateOf('succeeded', { outcome: 'blocked' }))),
        ).toContain('Blocked');
        expect(
            section(panelOf(stateOf('succeeded', { outcome: 'blocked' }))),
        ).toContain('View preparation');
    });

    it('explains why preparation is unavailable right on the section', () => {
        const html = section(
            panelOf(null, {
                can_prepare: false,
                problem: {
                    code: 'unsupported_ats_host',
                    message:
                        'Applications on this site cannot be prepared yet (only Lever and Greenhouse).',
                },
            }),
        );

        expect(html).toContain('cannot be prepared yet');
        expect(html).toContain('Why not?');
    });
});

describe('job freshness: no NEW preparation of an expired or closed job', () => {
    const BLOCK =
        'This job has expired, so new pursuit work cannot be started on it.';

    it('disables the start button and says why', () => {
        const html = drawer(panelOf(null), { blocked: BLOCK });

        expect(html).toContain(BLOCK);
        expect(html).toMatch(
            /<button[^>]*disabled=""[^>]*>[^<]*(Prepare|Start)[^<]*<\/button>/,
        );
    });

    it('is unchanged when nothing blocks it', () => {
        const html = drawer(panelOf(null));

        expect(html).not.toContain('new pursuit work');
    });
});
