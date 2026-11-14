import { describe, expect, it } from 'vitest';
import {
    noRecommendationText,
    qualificationAction,
    qualificationToast,
    reasonsHeading,
    roleShape,
    stackConcernText,
} from './qualification';
import type {
    JobQualificationView,
    QualificationResult,
    QualificationStackConcern,
} from '@/types/job-posting';

const result = {} as QualificationResult;
const view = (over: Partial<JobQualificationView>): JobQualificationView => ({
    state: 'none',
    stale: false,
    attempt: null,
    current: null,
    ...over,
});

describe('qualificationAction', () => {
    it('offers Qualify for a job never screened', () => {
        expect(qualificationAction(view({}))).toEqual({
            label: 'Qualify',
            disabled: false,
            refresh: false,
        });
    });

    it('offers nothing while an attempt is in flight', () => {
        for (const state of ['queued', 'running'] as const) {
            expect(qualificationAction(view({ state })).disabled).toBe(true);
        }
    });

    it('offers an explicit re-run for a valid result, and a plain re-qualify for an out-of-date one', () => {
        expect(
            qualificationAction(view({ state: 'ready', current: result })),
        ).toMatchObject({ label: 'Re-run', refresh: true });
        expect(
            qualificationAction(
                view({ state: 'ready', current: result, stale: true }),
            ),
        ).toMatchObject({ label: 'Re-qualify', refresh: false });
    });

    it('retries a failure, forcing a refresh only when the earlier result is still valid', () => {
        expect(qualificationAction(view({ state: 'failed' }))).toMatchObject({
            label: 'Try again',
            refresh: false,
        });
        expect(
            qualificationAction(view({ state: 'failed', current: result })),
        ).toMatchObject({ refresh: true });
        expect(
            qualificationAction(
                view({ state: 'failed', current: result, stale: true }),
            ),
        ).toMatchObject({ refresh: false });
    });
});

describe('wording', () => {
    it('describes the shape of a role', () => {
        expect(
            roleShape({
                role_type: 'full_stack',
                hands_on: 'hands_on',
                seniority: 'staff_or_above',
            }),
        ).toBe('Full-stack · Hands-on · Staff or above');
    });

    it('reads an unknown value as plain words rather than a code', () => {
        expect(
            roleShape({
                role_type: 'something_new',
                hands_on: 'unclear',
                seniority: 'unspecified',
            }),
        ).toBe('something new · Hands-on level unclear · Seniority not stated');
    });

    it('says what a mandatory stack asks and whether equivalents are welcome', () => {
        const base: QualificationStackConcern = {
            name: 'Ruby on Rails',
            requirement: 'mandatory',
            familiarity: 'unfamiliar',
            depth: 'deep_production',
            transferable_accepted: false,
            evidence: [],
        };

        expect(stackConcernText(base)).toBe(
            'Ruby on Rails: asks for deep production experience; not evidenced in your profile',
        );
        expect(
            stackConcernText({
                ...base,
                depth: 'working',
                transferable_accepted: true,
            }),
        ).toBe(
            'Ruby on Rails: expects hands-on use; equivalent experience welcome',
        );
    });

    it('explains why a result has no recommendation', () => {
        expect(noRecommendationText('ineligible')).toContain('does not fit');
        expect(noRecommendationText('insufficient_information')).toContain(
            'enough',
        );
    });
});

describe('qualificationToast', () => {
    it('reports each outcome without alarm', () => {
        expect(qualificationToast({ outcome: 'started' }).title).toBe(
            'Qualifying this job',
        );
        expect(qualificationToast({ outcome: 'in_flight' }).title).toBe(
            'Already being qualified',
        );
        expect(qualificationToast({ outcome: 'up_to_date' })).toMatchObject({
            kind: 'info',
            title: 'Already up to date',
        });
    });
});

describe('reasonsHeading', () => {
    it('reads as the case against a poor match and positively otherwise', () => {
        expect(reasonsHeading('poor')).toBe("Why it doesn't fit");

        for (const verdict of [
            'strong',
            'good',
            'needs_review',
            null,
        ] as const) {
            expect(reasonsHeading(verdict)).toBe('Why it fits');
        }
    });
});
