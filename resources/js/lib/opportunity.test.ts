import { describe, expect, it } from 'vitest';
import { formatCompensation } from './job-display';
import {
    buildOpportunityParams,
    existingWorkSummary,
    nextAction,
    resumeStateLabel,
    resumeStateTone,
    headerAction,
    workspaceNext,
    type ExistingWork,
    resumeSummary,
} from './opportunity';

const resume = (current: number | null, final: number | null) => ({
    state: 'none' as const,
    versions: 0,
    current_variant_id: current,
    final_variant_id: final,
});

describe('the next step for an opportunity', () => {
    it('opens the final resume when one is chosen, and the working copy while one is in progress', () => {
        expect(
            nextAction({ next: 'review_final', resume: resume(7, 7) }),
        ).toEqual({
            label: 'Review final resume',
            to: 'resume',
            variantId: 7,
        });
        expect(
            nextAction({ next: 'continue_resume', resume: resume(5, null) }),
        ).toEqual({
            label: 'Continue resume',
            to: 'resume',
            variantId: 5,
        });
    });

    it('falls back to the job page when a resume step has no resume to open', () => {
        expect(
            nextAction({ next: 'continue_resume', resume: resume(null, null) })
                .to,
        ).toBe('job');
        expect(
            nextAction({ next: 'review_final', resume: resume(null, null) }).to,
        ).toBe('job');
    });

    it('says "View opportunity" instead of Generate for a job that cannot start new work, and leaves other wording alone', () => {
        const none = resume(null, null);
        const blocked = { new_work_block: 'This job has expired.' };
        const f = (x: object) => x as never;

        for (const next of ['analyze', 'prepare_resume'] as const) {
            const step = nextAction({
                next,
                resume: none,
                freshness: f(blocked),
            });

            expect(step.label).toBe('View opportunity');
            expect(step.to).toBe('job');
            expect(step.variantId).toBeNull();
        }

        expect(
            nextAction({
                next: 'analyze',
                resume: none,
                freshness: f({ new_work_block: null }),
            }).label,
        ).toBe('Generate tailored resume');
        expect(
            nextAction({ next: 'analyze', resume: none, freshness: null })
                .label,
        ).toBe('Generate tailored resume');
        expect(nextAction({ next: 'analyze', resume: none }).label).toBe(
            'Generate tailored resume',
        );
        expect(
            nextAction({
                next: 'processing',
                resume: none,
                freshness: f(blocked),
            }).label,
        ).toBe('View progress');
    });

    it('sends every other step to the job, where analysis and resume generation already live', () => {
        const none = resume(null, null);

        expect(nextAction({ next: 'analyze', resume: none })).toEqual({
            label: 'Generate tailored resume',
            to: 'job',
            variantId: null,
        });
        expect(nextAction({ next: 'prepare_resume', resume: none }).label).toBe(
            'Generate tailored resume',
        );
        expect(
            nextAction({ next: 'answer_questions', resume: none }).label,
        ).toBe('Answer questions');
        expect(nextAction({ next: 'processing', resume: none }).label).toBe(
            'View progress',
        );
    });
});

describe('resume state and filters', () => {
    it('labels the three resume states and gives each a tone', () => {
        expect(resumeStateLabel('none')).toBe('No resume yet');
        expect(resumeStateLabel('in-progress')).toBe('Resume in progress');
        expect(resumeStateLabel('final')).toBe('Final resume chosen');
        expect(resumeStateTone('final')).toBe('ok');
        expect(resumeStateTone('none')).toBe('neutral');
    });

    it('keeps the default view out of the URL', () => {
        expect(buildOpportunityParams('all')).toEqual({});
        expect(buildOpportunityParams('in-progress')).toEqual({
            resume: 'in-progress',
        });
        expect(buildOpportunityParams('final')).toEqual({ resume: 'final' });
    });
});

describe('existing work', () => {
    const base: ExistingWork = {
        job_id: 1,
        title: 'Engineer',
        company: 'Acme',
        disposition: 'new',
        resume_variants: 0,
        current_resume_variant_id: null,
        final_resume_variant_id: null,
        applications: [],
        clarification_sessions: 0,
    };

    it('describes resume work and calls an application an inspection draft, never a submission', () => {
        expect(
            existingWorkSummary({
                ...base,
                final_resume_variant_id: 3,
                resume_variants: 8,
            }),
        ).toBe('Final resume chosen · 8 resume versions');
        expect(
            existingWorkSummary({
                ...base,
                applications: [{ id: 1, status: 'draft' }],
            }),
        ).toBe('1 inspection draft');
        expect(
            existingWorkSummary({
                ...base,
                resume_variants: 1,
                applications: [
                    { id: 1, status: 'draft' },
                    { id: 2, status: 'draft' },
                ],
                clarification_sessions: 1,
            }),
        ).toBe('1 resume version · 2 inspection drafts · questions started');
        expect(
            existingWorkSummary({
                ...base,
                applications: [{ id: 1, status: 'draft' }],
            }),
        ).not.toMatch(/submit/i);
    });
});

describe('compensation', () => {
    it('keeps currency and range together, and says nothing when the posting states no pay', () => {
        const none = {
            compensation_min: null,
            compensation_max: null,
            compensation_currency: null,
        };

        expect(formatCompensation(none)).toBeNull();
        expect(
            formatCompensation({
                ...none,
                compensation_min: 120000,
                compensation_max: 150000,
                compensation_currency: 'USD',
            }),
        ).toBe('USD\u00a0120,000\u2060–\u2060150,000');
        expect(formatCompensation({ ...none, compensation_max: 90000 })).toBe(
            '90,000',
        );
    });
});

describe('workspaceNext', () => {
    const resume = (current: number | null, final: number | null) => ({
        state: 'none' as const,
        versions: 0,
        current_variant_id: current,
        final_variant_id: final,
    });

    it('links to the resume workspace when there is a resume to open', () => {
        expect(
            workspaceNext({ next: 'continue_resume', resume: resume(4, null) }),
        ).toMatchObject({
            kind: 'link',
            label: 'Continue resume',
            variantId: 4,
        });
        expect(
            workspaceNext({ next: 'review_final', resume: resume(7, 7) }),
        ).toMatchObject({ kind: 'link', variantId: 7 });
    });

    it('points at the resume section for steps that happen on the workspace itself', () => {
        for (const next of [
            'analyze',
            'prepare_resume',
            'answer_questions',
            'processing',
        ] as const) {
            const step = workspaceNext({ next, resume: resume(null, null) });

            expect(step.kind).toBe('section');
            expect(step.label).toBe(
                nextAction({ next, resume: resume(null, null) }).label,
            );
        }
    });

    it('falls back to the section when a resume step has no variant to open', () => {
        expect(
            workspaceNext({
                next: 'continue_resume',
                resume: resume(null, null),
            }).kind,
        ).toBe('section');
    });
});

describe('workspaceNext and the pipeline', () => {
    const none = {
        state: 'none' as const,
        versions: 0,
        current_variant_id: null,
        final_variant_id: null,
    };

    it('names resume generation, not manual analysis, for a job with no resume', () => {
        for (const next of ['analyze', 'prepare_resume'] as const) {
            const step = workspaceNext({ next, resume: none });

            expect(step.kind).toBe('section');
            expect(step.label).toBe('Generate tailored resume');
        }
    });

    it('says so when the description has to be confirmed', () => {
        const step = workspaceNext(
            { next: 'prepare_resume', resume: none },
            { state: 'idle', needs_confirmation: true },
        );

        expect(step.hint).toMatch(/confirm/);
        expect(
            workspaceNext(
                { next: 'prepare_resume', resume: none },
                { state: 'idle', needs_confirmation: false },
            ).hint,
        ).not.toMatch(/confirm/);
    });

    it('follows the pipeline when it is running or failed, and keeps Continue resume primary otherwise', () => {
        expect(
            workspaceNext(
                { next: 'processing', resume: none },
                { state: 'running', needs_confirmation: false },
            ).hint,
        ).toMatch(/running/);
        expect(
            workspaceNext(
                { next: 'analyze', resume: none },
                { state: 'failed', needs_confirmation: false },
            ).hint,
        ).toMatch(/failed/);
        expect(
            workspaceNext(
                { next: 'processing', resume: none },
                { state: 'interrupted', needs_confirmation: false },
            ).hint,
        ).toMatch(/stopped/);
        expect(
            workspaceNext(
                {
                    next: 'continue_resume',
                    resume: { ...none, current_variant_id: 3 },
                },
                { state: 'idle', needs_confirmation: false },
            ),
        ).toMatchObject({ kind: 'link', label: 'Continue resume' });
    });
});

describe('resumeSummary', () => {
    const none = { state: 'none' as const, versions: 0 };

    it('says Not started only when nothing is generating and no resume exists', () => {
        expect(resumeSummary(none, null).label).toBe('Not started');
        expect(resumeSummary(none, { state: 'idle' }).label).toBe(
            'Not started',
        );
    });

    it('follows the persisted generation state, matching the button', () => {
        expect(resumeSummary(none, { state: 'running' })).toEqual({
            label: 'Generating',
            tone: 'accent',
        });
        expect(resumeSummary(none, { state: 'failed' })).toEqual({
            label: 'Generation failed',
            tone: 'bad',
        });
        expect(resumeSummary(none, { state: 'interrupted' }).tone).toBe('warn');
        expect(
            resumeSummary(none, { state: 'needs_clarification' }).label,
        ).toBe('Questions to answer');
    });

    it('moves from generating to completed without a reload', () => {
        expect(resumeSummary(none, { state: 'running' }).label).toBe(
            'Generating',
        );
        expect(resumeSummary(none, { state: 'ready' }).label).toBe(
            'Resume in progress',
        );
    });

    it('keeps the version count and falls back to the stored resume state', () => {
        const two = { state: 'in-progress' as const, versions: 2 };

        expect(resumeSummary(two, { state: 'running' }).label).toBe(
            'Generating · 2 versions',
        );
        expect(resumeSummary(two, { state: 'ready' }).label).toBe(
            'Resume in progress · 2 versions',
        );
        expect(resumeSummary({ state: 'final', versions: 1 }, null)).toEqual({
            label: 'Final resume chosen',
            tone: 'ok',
        });
    });
});

describe('headerAction', () => {
    const gen = (
        state: string,
        extra: Record<string, unknown> = {},
    ): Parameters<typeof headerAction>[1] =>
        ({
            state,
            needs_confirmation: false,
            profile_resume: null,
            preview_url: null,
            ...extra,
        }) as Parameters<typeof headerAction>[1];

    it('offers to generate when nothing has been done', () => {
        expect(
            headerAction(
                { next: 'analyze', resume: resume(null, null) },
                gen('idle'),
            ),
        ).toEqual({ kind: 'generate', label: 'Generate tailored resume' });
    });

    it('lets a run in progress, a failure or an interruption decide before anything else', () => {
        const op = { next: 'review_final' as const, resume: resume(7, 7) };

        expect(headerAction(op, gen('running')).kind).toBe('busy');
        expect(headerAction(op, gen('failed'))).toEqual({
            kind: 'generate',
            label: 'Retry generation',
        });
        expect(headerAction(op, gen('interrupted'))).toEqual({
            kind: 'generate',
            label: 'Continue generating',
        });
        expect(headerAction(op, gen('needs_clarification'))).toEqual({
            kind: 'anchor',
            label: 'Answer questions',
        });
    });

    it('opens the resume the opportunity already has', () => {
        expect(
            headerAction(
                { next: 'review_final', resume: resume(7, 7) },
                gen('idle'),
            ),
        ).toEqual({ kind: 'link', label: 'Review final resume', variantId: 7 });
    });

    it('moves to reviewing a resume that finished after the page loaded', () => {
        expect(
            headerAction(
                { next: 'analyze', resume: resume(null, null) },
                gen('ready', {
                    profile_resume: { review_url: '/resume/9' },
                }),
            ),
        ).toEqual({ kind: 'url', label: 'Review resume', url: '/resume/9' });
    });

    it('sends a waiting step to the Resume step instead of pretending to start it', () => {
        expect(
            headerAction(
                { next: 'answer_questions', resume: resume(null, null) },
                gen('idle'),
            ),
        ).toEqual({ kind: 'anchor', label: 'Answer questions' });
    });
});
