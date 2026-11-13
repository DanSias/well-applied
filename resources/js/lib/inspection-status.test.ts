import { describe, expect, it } from 'vitest';
import type {
    ApplicationInspectionState,
    InspectionResult,
    InspectionSummary,
    InspectionWorkflowRun,
} from '@/types/application-inspection';
import { presentInspection, summaryBadge } from './inspection-status';

const run = (
    status: InspectionWorkflowRun['status'],
): InspectionWorkflowRun => ({
    id: 1,
    status,
    failure_message: null,
    started_at: null,
    finished_at: null,
});
const result = (outcome: string, fields = 0): InspectionResult => ({
    agent_run_id: 1,
    ats_detected: 'greenhouse',
    inspection_outcome: outcome,
    finished_at: null,
    warnings: [],
    field_count: fields,
    unresolved_count: 0,
    questions: [],
});
const state = (
    workflow_run: InspectionWorkflowRun | null,
    latest_result: InspectionResult | null = null,
): ApplicationInspectionState => ({
    application_id: 1,
    workflow_run,
    latest_result,
});

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

    it('reports a finished run that found an unsupported ATS as Unsupported, not as a failure or a success', () => {
        const p = presentInspection(
            state(run('succeeded'), result('unsupported')),
        );

        expect(p.execution).toEqual({
            label: 'Worker run finished',
            tone: 'ok',
        });
        expect(p.headline).toEqual({ label: 'Unsupported ATS', tone: 'warn' });
        expect(p.outcome?.explanation).toMatch(/did not fail/);
        expect(p.action).toBe('Inspect again');
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

    it.each(['partial', 'authentication_required', 'mutation_required'])(
        'shows %s as its own outcome',
        (outcome) => {
            const p = presentInspection(
                state(run('succeeded'), result(outcome)),
            );

            expect(p.outcome?.tone).toBe('warn');
            expect(p.headline.tone).toBe('warn');
        },
    );
});

describe('summaryBadge', () => {
    const summary = (
        state: InspectionSummary['state'],
        outcome: string | null = null,
        field_count: number | null = null,
    ): InspectionSummary => ({
        state,
        application_id: 1,
        field_count,
        latest_attempt_failed: false,
        outcome,
    });

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
            label: 'Unsupported ATS',
            tone: 'warn',
        });
    });

    it('keeps execution states as execution states', () => {
        expect(summaryBadge(summary('failed')).label).toBe('Inspection failed');
        expect(summaryBadge(summary('queued')).label).toBe('Inspection queued');
        expect(summaryBadge(summary('running')).label).toBe('Inspecting…');
        expect(summaryBadge(summary('not_inspected')).tone).toBe('neutral');
    });
});
