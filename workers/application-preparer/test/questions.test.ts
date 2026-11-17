import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import type { FieldResult } from '../src/contracts.ts';
import { buildQuestions } from '../src/questions.ts';

let n = 0;
const result = (over: Partial<FieldResult> = {}): FieldResult => ({
    id: `f${n++}`,
    autocomplete: null,
    key: 'k',
    occurrence: 0,
    ambiguous: false,
    position: n,
    kind: 'text',
    tag: 'input',
    inputType: 'text',
    name: null,
    domId: null,
    label: 'Label',
    labelSource: 'dom_label_for',
    required: false,
    options: null,
    sensitive: false,
    group: null,
    checked: null,
    selected: null,
    classification: 'unknown',
    mappedTo: null,
    status: 'unanswered_optional',
    ...over,
});
const box = (
    option: string,
    groupKey: string | null,
    over: Partial<FieldResult> = {},
    groupLabel: string | null = 'Language Skill(s)',
) =>
    result({
        kind: 'checkbox',
        inputType: 'checkbox',
        name: 'cards[abc][field0]',
        label: option,
        checked: false,
        group:
            groupKey === null
                ? null
                : {
                      key: groupKey,
                      label: groupLabel,
                      labelSource: groupLabel
                          ? 'dom_wrapping_label'
                          : 'unresolved',
                      marked: false,
                  },
        ...over,
    });

describe('buildQuestions: groups', () => {
    it('REGRESSION (Palantir): 33 required language checkboxes are ONE question with 33 options', () => {
        const options = Array.from({ length: 33 }, (_, i) => `Language ${i}`);
        const fields = options.map((o) =>
            box(o, 'name:cards[abc][field0]', {
                required: true,
                status: 'unresolved_required',
            }),
        );
        const { questions, summary } = buildQuestions(fields);
        assert.equal(questions.length, 1);
        const q = questions[0]!;
        assert.equal(q.kind, 'checkbox_group');
        assert.equal(q.label, 'Language Skill(s)');
        assert.equal(q.labelConfidence, 'structural');
        assert.equal(q.required, true);
        assert.deepEqual(q.options, options);
        assert.equal(q.controls.length, 33);
        assert.deepEqual(q.selected, []);
        assert.equal(q.status, 'unresolved_required');
        assert.equal(q.review, 'needs_answer');
        assert.deepEqual(q.provenance, { source: 'none' });
        assert.equal(summary.total, 1);
        assert.equal(summary.needsAnswer, 1);
    });

    it('does not invent a label: a group the page left unlabelled has label null, unresolved confidence, and is never named after an option', () => {
        const { questions, summary } = buildQuestions([
            box(
                'No',
                'name:x',
                { required: true, status: 'unresolved_required' },
                null,
            ),
            box(
                'Yes - Intern',
                'name:x',
                { required: true, status: 'unresolved_required' },
                null,
            ),
        ]);
        const q = questions[0]!;
        assert.equal(q.label, null);
        assert.equal(q.labelConfidence, 'unresolved');
        assert.equal(q.labelSource, 'unresolved');
        assert.deepEqual(q.options, ['No', 'Yes - Intern']);
        assert.match(q.note!, /no question label/);
        assert.equal(
            q.name,
            'cards[abc][field0]',
            'the control name is the only handle',
        );
        assert.equal(summary.unlabelled, 1);
    });

    it('takes the label from whichever member has one, and keeps the structural source', () => {
        const { questions } = buildQuestions([
            box('A', 'k', {}, null),
            box('B', 'k', {}, 'Pick any'),
        ]);
        assert.equal(questions[0]!.label, 'Pick any');
        assert.equal(questions[0]!.labelSource, 'dom_wrapping_label');
    });

    it('a group is required if any member is, or the group marker is', () => {
        assert.equal(
            buildQuestions([box('A', 'k'), box('B', 'k', { required: true })])
                .questions[0]!.required,
            true,
        );
        const marked = box('A', 'k');
        marked.group!.marked = true;
        assert.equal(
            buildQuestions([marked, box('B', 'k')]).questions[0]!.required,
            true,
        );
        assert.equal(
            buildQuestions([box('A', 'k'), box('B', 'k')]).questions[0]!
                .required,
            false,
        );
    });

    it('an optional group is optional_skipped, and not a required question', () => {
        const q = buildQuestions([box('A', 'k'), box('B', 'k')]).questions[0]!;
        assert.equal(q.status, 'unanswered_optional');
        assert.equal(q.review, 'optional_skipped');
    });

    it('reports selected values from the page (never chosen by the worker)', () => {
        const q = buildQuestions([
            box('A', 'k', { checked: true }),
            box('B', 'k'),
            box('C', 'k', { checked: true }),
        ]).questions[0]!;
        assert.deepEqual(q.selected, ['A', 'C']);
    });

    it('a sensitive group stays sensitive and records no expected value', () => {
        const q = buildQuestions([
            box('He/him', 'k', { sensitive: true, expected: 'x' }),
            box('She/her', 'k', { sensitive: true }),
        ]).questions[0]!;
        assert.equal(q.sensitive, true);
        assert.equal(q.expected, undefined);
    });

    it('two groups with different keys stay separate, in the order of their first control', () => {
        const fields = [
            box('a1', 'g1'),
            result({ label: 'Name' }),
            box('b1', 'g2', {}, 'Second'),
            box('a2', 'g1'),
        ];
        const { questions } = buildQuestions(fields);
        assert.deepEqual(
            questions.map((q) => q.label),
            ['Language Skill(s)', 'Name', 'Second'],
        );
        assert.equal(questions[0]!.controls.length, 2);
    });

    it('a standalone checkbox (no group) is its own question and keeps its own label', () => {
        const q = buildQuestions([box('I consent to the privacy policy', null)])
            .questions[0]!;
        assert.equal(q.kind, 'checkbox');
        assert.equal(q.label, 'I consent to the privacy policy');
        assert.equal(q.controls.length, 1);
    });

    it('is complete when a member was somehow filled, and failed when a member errored', () => {
        assert.equal(
            buildQuestions([
                box('A', 'k', { status: 'filled_verified' }),
                box('B', 'k'),
            ]).questions[0]!.status,
            'filled_verified',
        );
        assert.equal(
            buildQuestions([box('A', 'k', { status: 'error' }), box('B', 'k')])
                .questions[0]!.review,
            'failed',
        );
    });
});

describe('buildQuestions: everything else is one question per control, with provenance and review status', () => {
    it('keeps label, required, options, selected, provenance and status for each kind', () => {
        const fields: FieldResult[] = [
            result({
                label: 'Email',
                name: 'email',
                required: true,
                mappedTo: 'email',
                classification: 'known',
                status: 'filled_verified',
                expected: 'a@b.test',
                actual: 'a@b.test',
            }),
            result({
                kind: 'radio',
                label: 'Authorized?',
                required: true,
                options: ['Yes', 'No'],
                selected: ['Yes'],
                mappedTo: 'answer',
                classification: 'known',
                status: 'filled_verified',
                expected: 'Yes',
            }),
            result({
                kind: 'select',
                label: 'How did you hear?',
                options: ['A', 'B'],
                selected: [],
            }),
            result({
                kind: 'file',
                label: 'Resume/CV',
                mappedTo: 'resume',
                classification: 'known',
                status: 'filled_verified',
            }),
            result({
                label: 'Current location',
                required: false,
                mappedTo: 'location',
                classification: 'known',
                status: 'manual_action_required',
                expected: 'Springfield',
            }),
            result({
                label: 'Why us?',
                kind: 'textarea',
                required: true,
                status: 'unresolved_required',
            }),
            result({ label: 'Verify me', status: 'unverified' }),
            result({ label: 'Broken', status: 'error' }),
        ];
        const { questions, summary } = buildQuestions(fields);
        assert.deepEqual(
            questions.map((q) => [
                q.label,
                q.kind,
                q.provenance.source,
                q.review,
            ]),
            [
                ['Email', 'text', 'applicant_fact', 'complete'],
                ['Authorized?', 'radio', 'approved_answer', 'complete'],
                ['How did you hear?', 'select', 'none', 'optional_skipped'],
                ['Resume/CV', 'file', 'chosen_resume', 'complete'],
                ['Current location', 'text', 'applicant_fact', 'needs_person'],
                ['Why us?', 'textarea', 'none', 'needs_answer'],
                ['Verify me', 'text', 'none', 'needs_check'],
                ['Broken', 'text', 'none', 'failed'],
            ],
        );
        assert.deepEqual(questions[0]!.provenance, {
            source: 'applicant_fact',
            key: 'email',
        });
        assert.deepEqual(questions[1]!.options, ['Yes', 'No']);
        assert.deepEqual(questions[1]!.selected, ['Yes']);
        assert.equal(questions[1]!.required, true);
        assert.equal(
            questions[4]!.expected,
            'Springfield',
            'the intended value survives for manual review',
        );
        assert.deepEqual(summary, {
            total: 8,
            complete: 3,
            optionalSkipped: 1,
            needsAnswer: 1,
            needsPerson: 1,
            needsCheck: 1,
            failed: 1,
            unlabelled: 0,
        });
    });

    it('marks an inferred label as inferred, an unresolved one as unresolved', () => {
        const q = buildQuestions([
            result({ label: 'Near', labelSource: 'inferred_proximity' }),
            result({
                label: null,
                labelSource: 'unresolved',
                name: 'cards[x][field0]',
            }),
        ]).questions;
        assert.equal(q[0]!.labelConfidence, 'inferred');
        assert.equal(q[1]!.labelConfidence, 'unresolved');
        assert.equal(q[1]!.name, 'cards[x][field0]');
    });

    it('passes the removed-resume presence through to its question', () => {
        const q = buildQuestions([
            result({
                kind: 'file',
                presence: 'removed_after_upload',
                mappedTo: 'resume',
                status: 'filled_verified',
            }),
        ]).questions[0]!;
        assert.equal(q.presence, 'removed_after_upload');
    });
});
