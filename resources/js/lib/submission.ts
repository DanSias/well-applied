import type { Tone } from '@/components/ui/badge';

/**
 * Presentation and form rules for recording an application the person submitted themselves. A "submission" is a person's
 * record, never something Well Applied did, and never the browser inspection (which is a different record). Pure, so it
 * can be tested. The server (RecordSubmission) remains the authority; these rules only keep an obviously wrong form from
 * being sent and say what is wrong in words.
 */

export type SubmissionIndicator = {
    id: number;
    phase: string;
    label: string;
    active: boolean;
    submitted_at: string;
    /** The calendar date (YYYY-MM-DD) to show: as entered, the same in every timezone. */
    submitted_on: string;
    count: number;
};

export type SubmissionRecord = {
    id: number;
    submitted_at: string;
    submitted_on: string;
    recorded_at: string | null;
    method: string;
    phase: string;
    phase_label: string;
    active: boolean;
    confirmed: boolean;
    application_url: string | null;
    notes: string | null;
    resume: { id: number; label: string | null } | null;
    pdf_kept: boolean;
    inspection_linked: boolean;
};

export type ResumeOption = {
    id: number;
    label: string;
    created_at: string;
    is_final: boolean;
    is_current: boolean;
    pdf_cached: boolean;
};

export type ApplicationSection = {
    records: SubmissionRecord[];
    form: {
        application_url: string | null;
        resumes: ResumeOption[];
        /** The largest PDF the server will take: 10 MB, or less when PHP is set to accept smaller uploads. */
        max_pdf_bytes: number;
    };
};

/** The person's own timezone, named explicitly so the server never has to guess which day "today" was for them. */
export function browserTimezone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

/** Today's calendar date (YYYY-MM-DD) in the given timezone. */
export function todayIn(timezone: string, now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}

/**
 * A calendar date (YYYY-MM-DD) as words ("Nov 13, 2026"), formatted from its own parts and never through a timezone, so
 * the day someone entered reads the same wherever and whenever it is viewed. A date only, never a time: nobody said one.
 */
export function formatCalendarDate(ymd: string, withYear = true): string {
    const [year, month, day] = ymd.split('-').map(Number);

    return new Intl.DateTimeFormat('en-US', {
        // The date was built in UTC and is read back in UTC: no zone ever shifts it.
        timeZone: 'UTC',
        month: 'short',
        day: 'numeric',
        ...(withYear ? { year: 'numeric' } : {}),
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

const PHASE_TONE: Record<string, Tone> = {
    submitted: 'ok',
    confirmed: 'ok',
    interviewing: 'accent',
    offer_received: 'accent',
    offer_accepted: 'ok',
    rejected: 'neutral',
    withdrawn: 'neutral',
    offer_declined: 'neutral',
    recorded_in_error: 'neutral',
};

export function phaseTone(phase: string): Tone {
    return PHASE_TONE[phase] ?? 'neutral';
}

/** The compact list-row indicator: "Not applied", "Applied · Nov 13", or the derived phase ("Confirmed", "Rejected"…). */
export function submissionIndicator(submission: SubmissionIndicator | null): {
    label: string;
    tone: Tone | null;
} {
    if (submission === null) {
        return { label: 'Not applied', tone: null };
    }

    return {
        label:
            submission.phase === 'submitted'
                ? `Applied · ${formatCalendarDate(submission.submitted_on, false)}`
                : submission.label,
        tone: phaseTone(submission.phase),
    };
}

export type ResumeKind = 'final' | 'variant' | 'external' | 'none';

export type SubmissionForm = {
    submittedOn: string;
    resumeKind: ResumeKind;
    resumeVariantId: number | null;
    resumeConfirmed: boolean;
    pdf: { name: string; size: number; type: string } | null;
    applicationUrl: string;
    notes: string;
    /** Recording a second application while one is active: the person must say they really applied again. */
    another: boolean;
    anotherConfirmed: boolean;
};

export const MAX_PDF_BYTES = 10 * 1024 * 1024;

/** "10 MB", "2 MB", "1.5 MB": the limit as a person reads it. */
export function formatMegabytes(bytes: number): string {
    const mb = Math.round((bytes / (1024 * 1024)) * 10) / 10;

    return `${mb} MB`;
}

export function initialForm(
    section: ApplicationSection['form'],
    timezone: string,
    another: boolean,
    now: Date = new Date(),
): SubmissionForm {
    return {
        submittedOn: todayIn(timezone, now),
        // Never a resume chosen for the person: they pick one, and confirm it, or say it was something else.
        resumeKind: 'none',
        resumeVariantId: null,
        resumeConfirmed: false,
        pdf: null,
        applicationUrl: section.application_url ?? '',
        notes: '',
        another,
        anotherConfirmed: false,
    };
}

export type SubmissionErrors = Partial<
    Record<
        | 'submittedOn'
        | 'resume'
        | 'resumeConfirmed'
        | 'pdf'
        | 'applicationUrl'
        | 'notes'
        | 'another',
        string
    >
>;

export function validateSubmission(
    form: SubmissionForm,
    timezone: string,
    now: Date = new Date(),
    maxPdfBytes: number = MAX_PDF_BYTES,
): SubmissionErrors {
    const errors: SubmissionErrors = {};

    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.submittedOn)) {
        errors.submittedOn = 'Choose the date you applied.';
    } else if (form.submittedOn > todayIn(timezone, now)) {
        errors.submittedOn = 'That date is in the future.';
    }

    if (form.resumeKind === 'final' || form.resumeKind === 'variant') {
        if (form.resumeVariantId === null) {
            errors.resume = 'Choose which resume you used.';
        } else if (!form.resumeConfirmed) {
            errors.resumeConfirmed =
                'Confirm that this is the document you actually submitted.';
        }
    }

    if (form.resumeKind === 'external') {
        if (form.pdf === null) {
            errors.pdf = 'Choose the PDF you submitted.';
        } else if (
            form.pdf.type !== 'application/pdf' &&
            !form.pdf.name.toLowerCase().endsWith('.pdf')
        ) {
            errors.pdf = 'That file is not a PDF.';
        } else if (form.pdf.size > maxPdfBytes) {
            errors.pdf = `That PDF is larger than ${formatMegabytes(maxPdfBytes)}.`;
        }
    }

    const url = form.applicationUrl.trim();

    if (url !== '' && !/^https?:\/\/\S+$/i.test(url)) {
        errors.applicationUrl =
            'Use a web address starting with http or https.';
    }

    if (form.notes.length > 5000) {
        errors.notes = 'Notes can be up to 5,000 characters.';
    }

    if (form.another && !form.anotherConfirmed) {
        errors.another = 'Confirm that you really applied again.';
    }

    return errors;
}

/** The request body. `idempotencyKey` is made once per recording and reused for every retry of it. */
export function buildPayload(
    form: SubmissionForm,
    timezone: string,
    idempotencyKey: string,
    file: File | null,
): Record<string, string | number | boolean | File> {
    const body: Record<string, string | number | boolean | File> = {
        idempotency_key: idempotencyKey,
        submitted_on: form.submittedOn,
        timezone,
        resume_choice:
            form.resumeKind === 'external'
                ? 'external'
                : form.resumeKind === 'none'
                  ? 'none'
                  : 'well_applied',
        return_to: 'opportunity',
    };

    if (form.resumeKind === 'final' || form.resumeKind === 'variant') {
        body.resume_variant_id = form.resumeVariantId ?? 0;
        body.resume_confirmed = form.resumeConfirmed;
    }

    if (form.resumeKind === 'external' && file !== null) {
        body.pdf = file;
    }

    if (form.applicationUrl.trim() !== '') {
        body.application_url = form.applicationUrl.trim();
    }

    if (form.notes.trim() !== '') {
        body.notes = form.notes.trim();
    }

    if (form.another && form.anotherConfirmed) {
        body.allow_another = true;
    }

    return body;
}

/** Server errors (by request field) back onto the form's own fields. */
export function mapServerErrors(
    errors: Record<string, string>,
): SubmissionErrors & { general?: string; reason?: string } {
    const out: SubmissionErrors & { general?: string; reason?: string } = {};
    const map: Record<string, keyof SubmissionErrors> = {
        submitted_on: 'submittedOn',
        resume_variant_id: 'resume',
        resume_confirmed: 'resumeConfirmed',
        pdf: 'pdf',
        application_url: 'applicationUrl',
        notes: 'notes',
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

export function newIdempotencyKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `rec-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** One recording at a time: a second press while the first is in flight is ignored, in the same tick as well as later. */
export function createSubmitGuard() {
    let busy = false;

    return {
        tryStart(): boolean {
            if (busy) {
                return false;
            }

            busy = true;

            return true;
        },
        finish(): void {
            busy = false;
        },
    };
}

/** One row of the Applications page (a recorded submission), as PresentSubmissions::forIndex hands it over. */
export type ApplicationListItem = {
    id: number;
    job: {
        id: number;
        title: string;
        company: string;
        pursued: boolean;
        url: string;
    };
    submitted_at: string;
    submitted_on: string;
    method: string;
    phase: string;
    phase_label: string;
    active: boolean;
    in_error: boolean;
    resume: string | null;
    pdf_kept: boolean;
    application_url: string | null;
    /** Which of this job's applications this is (oldest is 1), and how many it has; null for a record marked in error. */
    attempt: number | null;
    attempts: number;
};

/**
 * What a row says, in words: the date (never a time), the resume when known, which application of several it is, and
 * whether the job has left Opportunities. Nothing is invented: no resume means "no resume recorded".
 */
export function applicationRow(item: ApplicationListItem) {
    return {
        title: item.job.title,
        company: item.job.company,
        href: item.job.url,
        status: item.phase_label,
        tone: phaseTone(item.phase),
        date: formatCalendarDate(item.submitted_on),
        resume: item.resume ?? 'No resume recorded',
        series:
            item.attempt !== null && item.attempts > 1
                ? `Application ${item.attempt} of ${item.attempts}`
                : null,
        note: item.job.pursued ? null : 'No longer pursued',
    };
}
