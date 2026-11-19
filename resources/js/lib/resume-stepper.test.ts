import { describe, expect, it } from 'vitest';
import { resumeSteps } from './resume-stepper';
import type {
    TailoredResumeStage,
    TailoredResumeState,
} from '@/types/tailored-resume';

const stage = (
    key: TailoredResumeStage['key'],
    status: TailoredResumeStage['status'],
    phase: string | null = null,
): TailoredResumeStage => ({
    key,
    label: key,
    status,
    phase,
    duration_ms: null,
});

const states = (state: TailoredResumeState, stages: TailoredResumeStage[]) =>
    resumeSteps({ state, stages }).map((s) => s.state);

describe('resumeSteps', () => {
    it('shows no steps when nothing has started', () => {
        expect(states('idle', [])).toEqual([]);
    });

    it('is exactly the three user-facing steps, in order', () => {
        expect(
            resumeSteps({
                state: 'running',
                stages: [
                    stage('analysis', 'running'),
                    stage('match', 'pending'),
                    stage('resume', 'pending'),
                    stage('pdf', 'pending'),
                ],
            }).map((s) => s.label),
        ).toEqual(['Analyze requirements', 'Compose resume', 'Validate PDF']);
    });

    it('marks finished steps done, the current one active, the rest pending', () => {
        expect(
            states('running', [
                stage('analysis', 'done'),
                stage('resume', 'running'),
                stage('pdf', 'pending'),
            ]),
        ).toEqual(['done', 'active', 'pending']);
    });

    it('treats a queued stage as active and says it is waiting', () => {
        const steps = resumeSteps({
            state: 'running',
            stages: [stage('analysis', 'queued')],
        });

        expect(steps[0]).toMatchObject({
            state: 'active',
            note: 'waiting for worker',
        });
    });

    it('treats reused results as complete', () => {
        expect(
            states('needs_clarification', [
                stage('analysis', 'reused'),
                stage('match', 'reused'),
                stage('resume', 'pending'),
                stage('pdf', 'pending'),
            ]),
        ).toEqual(['done', 'pending', 'pending']);
    });

    it('folds the evidence-match stage into composing the resume', () => {
        const stages = (match: TailoredResumeStage['status']) => [
            stage('analysis', 'done'),
            stage('match', match),
            stage('resume', 'pending'),
            stage('pdf', 'pending'),
        ];

        expect(states('running', stages('running'))[1]).toBe('active');
        // Matching finished and composing is about to be queued: still the step in progress.
        expect(states('running', stages('done'))[1]).toBe('active');
        // But a stopped run does not claim progress.
        expect(states('interrupted', stages('done'))[1]).toBe('pending');
    });

    it('shows a failure on the step that failed, keeping earlier steps done', () => {
        const steps = resumeSteps({
            state: 'failed',
            stages: [
                stage('analysis', 'done'),
                stage('resume', 'failed', 'wording'),
                stage('pdf', 'pending'),
            ],
        });

        expect(steps.map((s) => s.state)).toEqual([
            'done',
            'failed',
            'pending',
        ]);
        expect(steps[1].note).toBe('wording');
    });

    it('shows an interrupted step as interrupted, never running', () => {
        expect(
            states('interrupted', [
                stage('analysis', 'done'),
                stage('resume', 'interrupted'),
                stage('pdf', 'pending'),
            ]),
        ).toEqual(['done', 'interrupted', 'pending']);
    });

    it('is all done for a ready run, including one with the match stage', () => {
        expect(
            states('ready', [
                stage('analysis', 'reused'),
                stage('match', 'done'),
                stage('resume', 'done'),
                stage('pdf', 'done'),
            ]),
        ).toEqual(['done', 'done', 'done']);
    });
});
