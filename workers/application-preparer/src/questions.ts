// Report-layer grouping: controls -> the questions a person would recognise.
//
// A checkbox group (shared name, or one fieldset / role=group) is ONE question with its
// options, however many controls render it. Radios and selects already arrive as one
// field each. Everything else is one question per control. Nothing is invented: a group
// whose page gave no question label has `label: null` and says so; it is never named
// after one of its options.
//
// This only reorganises what the engine already decided per control. It changes no
// status and fills nothing; groups of checkboxes are still never selected automatically.
import type {
    FieldResult,
    FieldStatus,
    LabelConfidence,
    LabelSource,
    QuestionKind,
    QuestionResult,
    QuestionReview,
    QuestionSummary,
} from './contracts.ts';

const REVIEW: Record<FieldStatus, QuestionReview> = {
    filled_verified: 'complete',
    unanswered_optional: 'optional_skipped',
    unresolved_required: 'needs_answer',
    unresolved_uncertain: 'needs_answer',
    manual_action_required: 'needs_person',
    unverified: 'needs_check',
    error: 'failed',
};

function confidence(
    source: LabelSource,
    label: string | null,
): LabelConfidence {
    if (label === null || source === 'unresolved') return 'unresolved';
    return source === 'inferred_proximity' ? 'inferred' : 'structural';
}

function provenance(f: FieldResult): QuestionResult['provenance'] {
    if (f.mappedTo === 'resume') return { source: 'chosen_resume' };
    if (f.mappedTo === 'answer') return { source: 'approved_answer' };
    if (f.mappedTo !== null)
        return { source: 'applicant_fact', key: f.mappedTo };
    return { source: 'none' };
}

/** A group is done if any member is; otherwise the worst member decides; otherwise it follows `required`. */
function groupStatus(
    members: readonly FieldResult[],
    required: boolean,
): FieldStatus {
    if (members.some((m) => m.status === 'filled_verified'))
        return 'filled_verified';
    if (members.some((m) => m.status === 'error')) return 'error';
    return required ? 'unresolved_required' : 'unanswered_optional';
}

/**
 * What is selected now: for a radio or select the worker filled, the verified read-back; otherwise
 * whatever the page already had selected. Sensitive controls never carry an actual value.
 */
function selectedOf(f: FieldResult): string[] {
    if (f.kind === 'checkbox') return f.checked ? [f.label ?? ''] : [];
    if (
        (f.kind === 'radio' || f.kind === 'select') &&
        f.status === 'filled_verified' &&
        f.actual
    )
        return [f.actual];
    return f.selected ?? [];
}

export function buildQuestions(fields: readonly FieldResult[]): {
    questions: QuestionResult[];
    summary: QuestionSummary;
} {
    const questions: QuestionResult[] = [];
    const groups = new Map<string, FieldResult[]>();
    const order: Array<{ group: string } | { single: FieldResult }> = [];

    for (const f of fields) {
        if (f.kind === 'checkbox' && f.group !== null) {
            const members = groups.get(f.group.key);
            if (members) members.push(f);
            else {
                groups.set(f.group.key, [f]);
                order.push({ group: f.group.key });
            }
        } else order.push({ single: f });
    }

    for (const entry of order) {
        if ('single' in entry) {
            const f = entry.single;
            const kind: QuestionKind = f.kind;
            questions.push({
                id: f.id,
                label: f.label,
                labelSource: f.labelSource,
                labelConfidence: confidence(f.labelSource, f.label),
                name: f.name,
                kind,
                required: f.required,
                options: f.options,
                selected: selectedOf(f),
                controls: [f.id],
                provenance: provenance(f),
                status: f.status,
                review: REVIEW[f.status],
                sensitive: f.sensitive,
                ...(f.expected !== undefined && !f.sensitive
                    ? { expected: f.expected }
                    : {}),
                ...(f.note ? { note: f.note } : {}),
                ...(f.presence ? { presence: f.presence } : {}),
            });
            continue;
        }

        const members = groups.get(entry.group)!;
        const first = members[0]!;
        // The group's question label: the first member whose group has one. Never an option's text.
        const labelled = members.find((m) => m.group!.label !== null);
        const label = labelled?.group!.label ?? null;
        const labelSource: LabelSource = labelled
            ? labelled.group!.labelSource
            : 'unresolved';
        const required = members.some(
            (m) => m.required === true || m.group!.marked,
        );
        const status = groupStatus(members, required);
        const sensitive = members.some((m) => m.sensitive);
        questions.push({
            id: first.id,
            label,
            labelSource,
            labelConfidence: confidence(labelSource, label),
            name: first.name,
            kind: 'checkbox_group',
            required,
            options: members.map((m) => m.label ?? ''),
            selected: members
                .filter((m) => m.checked)
                .map((m) => m.label ?? ''),
            controls: members.map((m) => m.id),
            provenance: { source: 'none' },
            status,
            review: REVIEW[status],
            sensitive,
            note:
                label === null
                    ? 'the page provides no question label for this group of checkboxes; the worker does not select checkbox groups'
                    : 'the worker does not select checkbox groups',
        });
    }

    const summary: QuestionSummary = {
        total: questions.length,
        complete: questions.filter((q) => q.review === 'complete').length,
        optionalSkipped: questions.filter(
            (q) => q.review === 'optional_skipped',
        ).length,
        needsAnswer: questions.filter((q) => q.review === 'needs_answer')
            .length,
        needsPerson: questions.filter((q) => q.review === 'needs_person')
            .length,
        needsCheck: questions.filter((q) => q.review === 'needs_check').length,
        failed: questions.filter((q) => q.review === 'failed').length,
        unlabelled: questions.filter((q) => q.label === null).length,
    };
    return { questions, summary };
}
