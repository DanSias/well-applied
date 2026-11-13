import {
    browserTimezone,
    formatCalendarDate,
    todayIn,
    type HistoryEvent,
    type SubmissionRecord,
    type UpdateOption,
} from '@/lib/submission';

/**
 * Updates to a recorded application: what happened afterwards, as the person tells it, and the correction for a record
 * made by mistake. Pure, so the defaults, rules and wording can be tested. The server (RecordSubmissionEvent) is the
 * authority on every rule; these only keep an obviously wrong form from being sent and describe the state in words. The
 * status itself is derived on the server from the events: nothing here works out or stores one.
 */

export { browserTimezone };

export type UpdateForm = {
    type: string;
    occurredOn: string;
    note: string;
    url: string;
};

export type UpdateErrors = Partial<
    Record<'type' | 'occurredOn' | 'note' | 'url', string>
>;

/** The first offered update is selected, today is the date, and nothing else is guessed. */
export function initialUpdateForm(
    options: UpdateOption[],
    timezone: string,
    now: Date = new Date(),
): UpdateForm {
    return {
        type: options[0]?.value ?? '',
        occurredOn: todayIn(timezone, now),
        note: '',
        url: '',
    };
}

/** What the date means for each kind of update. An interview may be upcoming; nothing else can be. */
export function dateLabel(type: string): { label: string; hint: string } {
    return type === 'interview'
        ? {
              label: 'Interview date',
              hint: 'The day of the interview. It can be an upcoming day. No time is recorded.',
          }
        : {
              label: 'Date it happened',
              hint: 'Just the day, in your timezone. You can enter an earlier day.',
          };
}

export function validateUpdate(
    form: UpdateForm,
    options: UpdateOption[],
    submittedOn: string,
    timezone: string,
    now: Date = new Date(),
): UpdateErrors {
    const errors: UpdateErrors = {};

    if (!options.some((option) => option.value === form.type)) {
        errors.type = 'Choose what happened.';
    }

    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.occurredOn)) {
        errors.occurredOn = 'Choose the date.';
    } else if (form.occurredOn < submittedOn) {
        errors.occurredOn = `That is before you applied (${formatCalendarDate(submittedOn)}).`;
    } else if (
        form.type !== 'interview' &&
        form.occurredOn > todayIn(timezone, now)
    ) {
        errors.occurredOn = 'That date is in the future.';
    }

    const url = form.url.trim();

    if (url !== '' && !/^https?:\/\/\S+$/i.test(url)) {
        errors.url = 'Use a web address starting with http or https.';
    }

    if (form.note.length > 5000) {
        errors.note = 'Notes can be up to 5,000 characters.';
    }

    return errors;
}

/** The request body. `idempotencyKey` is made once per form and reused for every retry of it. */
export function buildUpdatePayload(
    form: UpdateForm,
    timezone: string,
    idempotencyKey: string,
): Record<string, string> {
    const body: Record<string, string> = {
        idempotency_key: idempotencyKey,
        type: form.type,
        occurred_on: form.occurredOn,
        timezone,
        return_to: 'opportunity',
    };

    if (form.note.trim() !== '') {
        body.note = form.note.trim();
    }

    if (form.url.trim() !== '') {
        body.url = form.url.trim();
    }

    return body;
}

export function mapUpdateErrors(
    errors: Record<string, string>,
): UpdateErrors & { general?: string; reason?: string } {
    const out: UpdateErrors & { general?: string; reason?: string } = {};
    const map: Record<string, keyof UpdateErrors> = {
        type: 'type',
        occurred_on: 'occurredOn',
        note: 'note',
        url: 'url',
    };

    for (const [key, message] of Object.entries(errors)) {
        if (key === 'reason') {
            out.reason = message;
        } else if (map[key] !== undefined) {
            out[map[key]] = message;
        } else {
            out.general = out.general ?? message;
        }
    }

    return out;
}

export type CorrectionForm = { confirmed: boolean; reason: string };

export function validateCorrection(form: CorrectionForm): {
    confirmed?: string;
    reason?: string;
} {
    const errors: { confirmed?: string; reason?: string } = {};

    if (!form.confirmed) {
        errors.confirmed =
            'Confirm that this application should not have been recorded.';
    }

    if (form.reason.length > 5000) {
        errors.reason = 'The reason can be up to 5,000 characters.';
    }

    return errors;
}

export function buildCorrectionPayload(
    form: CorrectionForm,
    idempotencyKey: string,
): Record<string, string | boolean> {
    const body: Record<string, string | boolean> = {
        idempotency_key: idempotencyKey,
        confirmed: form.confirmed,
        return_to: 'opportunity',
    };

    if (form.reason.trim() !== '') {
        body.reason = form.reason.trim();
    }

    return body;
}

export type HistoryEntry = {
    key: string;
    label: string;
    date: string;
    note: string | null;
    url: string | null;
};

/**
 * The compact history of one application, newest first: each update as entered, then "Application submitted". Only this
 * application's own events (the server never mixes another's in), and every entry is the person's own record.
 */
export function historyEntries(
    record: Pick<SubmissionRecord, 'events' | 'submitted_on'>,
): HistoryEntry[] {
    return [
        ...record.events.map((event: HistoryEvent): HistoryEntry => ({
            key: `event-${event.id}`,
            label: event.label,
            date: formatCalendarDate(event.occurred_on),
            note: event.note,
            url: event.url,
        })),
        {
            key: 'submitted',
            label: 'Application submitted',
            date: formatCalendarDate(record.submitted_on),
            note: null,
            url: null,
        },
    ];
}

/**
 * The line under an application's status that says what you can and cannot do with it now. An ended application keeps
 * its final status and offers no ordinary updates; one marked as an error is only a historical record.
 */
export function statusNote(
    record: Pick<SubmissionRecord, 'ended' | 'in_error' | 'phase_label'>,
): string | null {
    if (record.in_error) {
        return 'Marked as recorded in error. Kept for reference; it no longer counts as an application.';
    }

    if (record.ended) {
        return `This application has ended (${record.phase_label}). The history is kept; to record a new application to this job, use Record another application.`;
    }

    return null;
}

/** What may be done with a record: updates only while it is in flight, a correction until it is already one. */
export function recordActions(
    record: Pick<SubmissionRecord, 'allowed_updates' | 'in_error'>,
): { canUpdate: boolean; canCorrect: boolean } {
    return {
        canUpdate: record.allowed_updates.length > 0,
        canCorrect: !record.in_error,
    };
}

/** Splits an application list into the ones that count and the ones marked as an error. */
export function splitRecords<T extends { in_error: boolean }>(
    records: T[],
): { counted: T[]; inError: T[] } {
    return {
        counted: records.filter((r) => !r.in_error),
        inError: records.filter((r) => r.in_error),
    };
}
