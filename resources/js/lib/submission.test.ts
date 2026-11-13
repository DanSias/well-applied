import { afterEach, describe, expect, it } from 'vitest';
import {
    applicationRow,
    buildPayload,
    createSubmitGuard,
    formatMegabytes,
    formatCalendarDate,
    initialForm,
    mapServerErrors,
    submissionIndicator,
    todayIn,
    validateSubmission,
    type ApplicationListItem,
    type ApplicationSection,
    type SubmissionForm,
    type SubmissionIndicator,
} from './submission';

const NY = 'America/New_York';
const section: ApplicationSection['form'] = {
    application_url: 'https://example.com/apply',
    resumes: [],
    max_pdf_bytes: 10 * 1024 * 1024,
};
// 02:30 UTC on Nov 14 is still Nov 13 in New York, and already Nov 14 in Tokyo.
const lateNight = new Date('2026-11-14T02:30:00Z');

const form = (over: Partial<SubmissionForm> = {}): SubmissionForm => ({
    ...initialForm(section, NY, false, lateNight),
    ...over,
});

describe('dates', () => {
    it("defaults to today in the person's own timezone, not the server's or UTC", () => {
        expect(todayIn(NY, lateNight)).toBe('2026-11-13');
        expect(todayIn('Asia/Tokyo', lateNight)).toBe('2026-11-14');
        expect(initialForm(section, NY, false, lateNight).submittedOn).toBe(
            '2026-11-13',
        );
    });

    it('shows a date only, never a time', () => {
        expect(formatCalendarDate('2026-11-10')).toBe('Nov 10, 2026');
        expect(formatCalendarDate('2026-11-10', false)).toBe('Nov 10');
        expect(formatCalendarDate('2026-11-10')).not.toMatch(/\d:\d/);
    });
});

describe('a calendar date entered by a person', () => {
    const originalTz = process.env.TZ;

    afterEach(() => {
        process.env.TZ = originalTz;
    });

    it('reads the same in every timezone it is viewed from', () => {
        const seen = new Set<string>();

        for (const tz of [
            'Pacific/Honolulu',
            'America/New_York',
            'UTC',
            'Europe/Berlin',
            'Asia/Tokyo',
            'Pacific/Kiritimati',
        ]) {
            process.env.TZ = tz;
            seen.add(formatCalendarDate('2026-11-13'));
            seen.add(formatCalendarDate('2026-11-13', false));
        }

        expect([...seen].sort()).toEqual(['Nov 13', 'Nov 13, 2026']);
    });

    it('is what goes wrong when the same day is stored as an instant and viewed elsewhere', () => {
        // Recorded as the start of Nov 13 in New York, an instant of 05:00 UTC.
        const instant = new Date('2026-11-13T05:00:00Z');
        const shown = (timeZone: string) =>
            new Intl.DateTimeFormat('en-US', {
                timeZone,
                month: 'short',
                day: 'numeric',
            }).format(instant);

        expect(shown('America/New_York')).toBe('Nov 13');
        expect(shown('Pacific/Honolulu')).toBe('Nov 12');
        expect(formatCalendarDate('2026-11-13', false)).toBe('Nov 13');
    });

    it('handles month and year edges without drifting', () => {
        expect(formatCalendarDate('2026-01-01')).toBe('Jan 1, 2026');
        expect(formatCalendarDate('2025-12-31')).toBe('Dec 31, 2025');
        expect(formatCalendarDate('2028-02-29')).toBe('Feb 29, 2028');
    });
});

describe('initial form', () => {
    it('prefills the link and never picks a resume for the person', () => {
        const f = initialForm(section, NY, false);

        expect(f.applicationUrl).toBe('https://example.com/apply');
        expect(f.resumeKind).toBe('none');
        expect(f.resumeVariantId).toBeNull();
        expect(f.resumeConfirmed).toBe(false);
        expect(
            initialForm(
                {
                    application_url: null,
                    resumes: [],
                    max_pdf_bytes: 10 * 1024 * 1024,
                },
                NY,
                false,
            ).applicationUrl,
        ).toBe('');
    });
});

describe('validation', () => {
    it('accepts a date-only application with nothing else', () => {
        expect(validateSubmission(form(), NY, lateNight)).toEqual({});
    });

    it('allows an earlier date and refuses a future one', () => {
        expect(
            validateSubmission(
                form({ submittedOn: '2026-01-02' }),
                NY,
                lateNight,
            ),
        ).toEqual({});
        expect(
            validateSubmission(
                form({ submittedOn: '2026-11-14' }),
                NY,
                lateNight,
            ).submittedOn,
        ).toMatch(/future/);
        expect(
            validateSubmission(form({ submittedOn: '' }), NY, lateNight)
                .submittedOn,
        ).toBeDefined();
    });

    it('needs a chosen and explicitly confirmed Well Applied resume', () => {
        expect(
            validateSubmission(form({ resumeKind: 'final' }), NY, lateNight)
                .resume,
        ).toBeDefined();
        expect(
            validateSubmission(
                form({ resumeKind: 'final', resumeVariantId: 3 }),
                NY,
                lateNight,
            ).resumeConfirmed,
        ).toBeDefined();
        expect(
            validateSubmission(
                form({
                    resumeKind: 'variant',
                    resumeVariantId: 3,
                    resumeConfirmed: true,
                }),
                NY,
                lateNight,
            ),
        ).toEqual({});
    });

    it('needs a real PDF of a sensible size for an upload', () => {
        const pdf = (over = {}) => ({
            name: 'cv.pdf',
            size: 1000,
            type: 'application/pdf',
            ...over,
        });

        expect(
            validateSubmission(form({ resumeKind: 'external' }), NY, lateNight)
                .pdf,
        ).toBeDefined();
        expect(
            validateSubmission(
                form({ resumeKind: 'external', pdf: pdf() }),
                NY,
                lateNight,
            ),
        ).toEqual({});
        expect(
            validateSubmission(
                form({
                    resumeKind: 'external',
                    pdf: pdf({ name: 'a.txt', type: 'text/plain' }),
                }),
                NY,
                lateNight,
            ).pdf,
        ).toMatch(/not a PDF/);
        expect(
            validateSubmission(
                form({
                    resumeKind: 'external',
                    pdf: pdf({ size: 11 * 1024 * 1024 }),
                }),
                NY,
                lateNight,
            ).pdf,
        ).toMatch(/10 MB/);
    });

    it('allows no resume at all', () => {
        expect(
            validateSubmission(form({ resumeKind: 'none' }), NY, lateNight),
        ).toEqual({});
    });

    it('allows a blank link and refuses one that is not a web address', () => {
        expect(
            validateSubmission(form({ applicationUrl: '' }), NY, lateNight),
        ).toEqual({});
        expect(
            validateSubmission(
                form({ applicationUrl: 'javascript:alert(1)' }),
                NY,
                lateNight,
            ).applicationUrl,
        ).toBeDefined();
        expect(
            validateSubmission(
                form({ applicationUrl: 'example.com' }),
                NY,
                lateNight,
            ).applicationUrl,
        ).toBeDefined();
    });

    it('makes the person confirm before recording another application', () => {
        expect(
            validateSubmission(form({ another: true }), NY, lateNight).another,
        ).toBeDefined();
        expect(
            validateSubmission(
                form({ another: true, anotherConfirmed: true }),
                NY,
                lateNight,
            ),
        ).toEqual({});
    });
});

describe('the PDF size limit', () => {
    const big = {
        name: 'cv.pdf',
        size: 3 * 1024 * 1024,
        type: 'application/pdf',
    };

    it('uses the limit the server reports, which can be below 10 MB', () => {
        expect(
            validateSubmission(
                form({ resumeKind: 'external', pdf: big }),
                NY,
                lateNight,
                2 * 1024 * 1024,
            ).pdf,
        ).toBe('That PDF is larger than 2 MB.');
        expect(
            validateSubmission(
                form({ resumeKind: 'external', pdf: big }),
                NY,
                lateNight,
            ),
        ).toEqual({});
    });

    it('names a limit the way a person reads it', () => {
        expect(formatMegabytes(10 * 1024 * 1024)).toBe('10 MB');
        expect(formatMegabytes(2 * 1024 * 1024)).toBe('2 MB');
        expect(formatMegabytes(1.5 * 1024 * 1024)).toBe('1.5 MB');
    });
});

describe('request body', () => {
    it('carries the timezone and the stable idempotency key, and only what applies', () => {
        const body = buildPayload(
            form({ notes: ' Via a referral ' }),
            NY,
            'key-1',
            null,
        );

        expect(body).toMatchObject({
            idempotency_key: 'key-1',
            submitted_on: '2026-11-13',
            timezone: NY,
            resume_choice: 'none',
            application_url: 'https://example.com/apply',
            notes: 'Via a referral',
            return_to: 'opportunity',
        });
        expect(body).not.toHaveProperty('resume_variant_id');
        expect(body).not.toHaveProperty('allow_another');
        expect(buildPayload(form(), NY, 'key-1', null).idempotency_key).toBe(
            'key-1',
        );
    });

    it('sends the resume only with its confirmation, and another only after confirming', () => {
        const body = buildPayload(
            form({
                resumeKind: 'final',
                resumeVariantId: 7,
                resumeConfirmed: true,
                another: true,
                anotherConfirmed: true,
            }),
            NY,
            'k',
            null,
        );

        expect(body).toMatchObject({
            resume_choice: 'well_applied',
            resume_variant_id: 7,
            resume_confirmed: true,
            allow_another: true,
        });
        expect(
            buildPayload(
                form({ another: true, anotherConfirmed: false }),
                NY,
                'k',
                null,
            ),
        ).not.toHaveProperty('allow_another');
    });

    it('attaches the file only for an upload', () => {
        const file = new File(['%PDF-1.7'], 'cv.pdf', {
            type: 'application/pdf',
        });

        expect(
            buildPayload(
                form({
                    resumeKind: 'external',
                    pdf: { name: 'cv.pdf', size: 8, type: 'application/pdf' },
                }),
                NY,
                'k',
                file,
            ),
        ).toMatchObject({ resume_choice: 'external', pdf: file });
        expect(
            buildPayload(form({ resumeKind: 'none' }), NY, 'k', file),
        ).not.toHaveProperty('pdf');
    });
});

describe('server errors', () => {
    it('lands on the right fields and keeps the reason for a duplicate', () => {
        expect(
            mapServerErrors({
                submitted_on: 'In the future.',
                pdf: 'Not a PDF.',
                reason: 'active_submission_exists',
                submission: 'Already tracked.',
            }),
        ).toEqual({
            submittedOn: 'In the future.',
            pdf: 'Not a PDF.',
            reason: 'active_submission_exists',
            general: 'Already tracked.',
        });
    });
});

describe('list indicator', () => {
    const sub = (
        over: Partial<SubmissionIndicator> = {},
    ): SubmissionIndicator => ({
        id: 1,
        phase: 'submitted',
        label: 'Applied',
        active: true,
        submitted_at: '2026-11-13T05:00:00Z',
        submitted_on: '2026-11-13',
        count: 1,
        ...over,
    });

    it('says Not applied when nothing was recorded, and does not count an inspection', () => {
        expect(submissionIndicator(null)).toEqual({
            label: 'Not applied',
            tone: null,
        });
    });

    it('shows Applied with the date, then the derived phase', () => {
        expect(submissionIndicator(sub()).label).toBe('Applied · Nov 13');
        expect(
            submissionIndicator(sub({ phase: 'confirmed', label: 'Confirmed' }))
                .label,
        ).toBe('Confirmed');
        expect(
            submissionIndicator(
                sub({ phase: 'interviewing', label: 'Interviewing' }),
            ).label,
        ).toBe('Interviewing');
        expect(
            submissionIndicator(
                sub({ phase: 'rejected', label: 'Rejected', active: false }),
            ),
        ).toEqual({ label: 'Rejected', tone: 'neutral' });
    });
});

describe('submit guard', () => {
    it('lets only the first of several presses through, even in the same tick', () => {
        const guard = createSubmitGuard();

        expect([guard.tryStart(), guard.tryStart(), guard.tryStart()]).toEqual([
            true,
            false,
            false,
        ]);
    });

    it('allows another attempt once the first has finished', () => {
        const guard = createSubmitGuard();
        guard.tryStart();
        guard.finish();

        expect(guard.tryStart()).toBe(true);
    });
});

describe('applicationRow', () => {
    const item = (
        over: Partial<ApplicationListItem> = {},
    ): ApplicationListItem => ({
        id: 1,
        job: {
            id: 7,
            title: 'Staff Engineer',
            company: 'Acme',
            pursued: true,
            url: '/opportunities/7',
        },
        submitted_at: '2026-11-10T05:00:00Z',
        submitted_on: '2026-11-10',
        method: 'manual',
        phase: 'submitted',
        phase_label: 'Applied',
        active: true,
        in_error: false,
        resume: 'Generated resume 2',
        pdf_kept: true,
        application_url: null,
        attempt: 1,
        attempts: 1,
        ...over,
    });

    it('presents company, title, date, status and resume', () => {
        expect(applicationRow(item())).toMatchObject({
            title: 'Staff Engineer',
            company: 'Acme',
            href: '/opportunities/7',
            status: 'Applied',
            date: 'Nov 10, 2026',
            resume: 'Generated resume 2',
            series: null,
            note: null,
        });
    });

    it('never invents a resume, and says when the job is no longer pursued', () => {
        const row = applicationRow(
            item({
                resume: null,
                job: {
                    id: 7,
                    title: 'x',
                    company: 'y',
                    pursued: false,
                    url: '/jobs/7',
                },
            }),
        );

        expect(row.resume).toBe('No resume recorded');
        expect(row.note).toBe('No longer pursued');
        expect(row.href).toBe('/jobs/7');
    });

    it('numbers several applications to the same job', () => {
        expect(applicationRow(item({ attempt: 2, attempts: 3 })).series).toBe(
            'Application 2 of 3',
        );
        expect(
            applicationRow(item({ attempt: 1, attempts: 1 })).series,
        ).toBeNull();
    });

    it('uses the derived status label and tone', () => {
        expect(
            applicationRow(
                item({ phase: 'rejected', phase_label: 'Rejected' }),
            ),
        ).toMatchObject({ status: 'Rejected', tone: 'neutral' });
        expect(
            applicationRow(
                item({ phase: 'interviewing', phase_label: 'Interviewing' }),
            ).tone,
        ).toBe('accent');
    });
});
