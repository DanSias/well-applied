import { describe, expect, it } from 'vitest';
import { formatCompensation } from './job-display';
import {
    buildOpportunityParams,
    existingWorkSummary,
    nextAction,
    resumeStateLabel,
    resumeStateTone,
    type ExistingWork,
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

    it('sends every other step to the job, where analysis and resume generation already live', () => {
        const none = resume(null, null);

        expect(nextAction({ next: 'analyze', resume: none })).toEqual({
            label: 'Analyze job',
            to: 'job',
            variantId: null,
        });
        expect(nextAction({ next: 'prepare_resume', resume: none }).label).toBe(
            'Prepare resume',
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
