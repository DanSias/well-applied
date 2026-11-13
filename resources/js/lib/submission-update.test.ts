import { describe, expect, it } from 'vitest';
import type { SubmissionRecord, UpdateOption } from './submission';
import {
    buildCorrectionPayload,
    buildUpdatePayload,
    dateLabel,
    historyEntries,
    initialUpdateForm,
    mapUpdateErrors,
    recordActions,
    splitRecords,
    statusNote,
    validateCorrection,
    validateUpdate,
} from './submission-update';

const NY = 'America/New_York';
// 02:30 UTC on Nov 14 is still Nov 13 in New York.
const lateNight = new Date('2026-11-14T02:30:00Z');
const options: UpdateOption[] = [
    { value: 'confirmation_received', label: 'Confirmation received' },
    { value: 'follow_up_sent', label: 'Follow-up sent' },
    { value: 'interview', label: 'Interview' },
    { value: 'rejected', label: 'Rejected' },
];

describe('update form defaults', () => {
    it("selects the first offered update and defaults the date to today in the person's timezone", () => {
        const form = initialUpdateForm(options, NY, lateNight);

        expect(form).toEqual({
            type: 'confirmation_received',
            occurredOn: '2026-11-13',
            note: '',
            url: '',
        });
        expect(
            initialUpdateForm(options, 'Asia/Tokyo', lateNight).occurredOn,
        ).toBe('2026-11-14');
        expect(initialUpdateForm([], NY, lateNight).type).toBe('');
    });

    it('says an interview date may be upcoming, and that no time is recorded', () => {
        expect(dateLabel('interview')).toMatchObject({
            label: 'Interview date',
        });
        expect(dateLabel('interview').hint).toMatch(/upcoming/);
        expect(dateLabel('interview').hint).toMatch(/No time/);
        expect(dateLabel('follow_up_sent').label).toBe('Date it happened');
    });
});

describe('update validation', () => {
    const form = (over = {}) => ({
        ...initialUpdateForm(options, NY, lateNight),
        ...over,
    });
    const check = (over = {}) =>
        validateUpdate(form(over), options, '2026-11-01', NY, lateNight);

    it('accepts the defaults', () => {
        expect(check()).toEqual({});
    });

    it('needs an offered type', () => {
        expect(check({ type: '' }).type).toBeDefined();
        expect(check({ type: 'offer_accepted' }).type).toBeDefined();
    });

    it('refuses a missing, future, or pre-application date', () => {
        expect(check({ occurredOn: '' }).occurredOn).toBeDefined();
        expect(check({ occurredOn: '2026-11-14' }).occurredOn).toMatch(
            /future/,
        );
        expect(check({ occurredOn: '2026-10-31' }).occurredOn).toMatch(
            /before you applied/,
        );
        expect(check({ occurredOn: '2026-11-01' })).toEqual({});
    });

    it('lets only an interview be upcoming', () => {
        expect(check({ type: 'interview', occurredOn: '2026-11-20' })).toEqual(
            {},
        );
        expect(
            check({ type: 'follow_up_sent', occurredOn: '2026-11-20' })
                .occurredOn,
        ).toMatch(/future/);
    });

    it('accepts a blank link and refuses one that is not a web address', () => {
        expect(check({ url: '' })).toEqual({});
        expect(check({ url: 'https://example.com/thanks' })).toEqual({});
        expect(check({ url: 'javascript:alert(1)' }).url).toBeDefined();
    });
});

describe('update payload', () => {
    it('carries the type, the date as entered, the timezone and the stable key', () => {
        const body = buildUpdatePayload(
            {
                type: 'interview',
                occurredOn: '2026-11-20',
                note: ' Phone screen ',
                url: '',
            },
            NY,
            'key-1',
        );

        expect(body).toEqual({
            idempotency_key: 'key-1',
            type: 'interview',
            occurred_on: '2026-11-20',
            timezone: NY,
            note: 'Phone screen',
            return_to: 'opportunity',
        });
        expect(
            buildUpdatePayload(
                initialUpdateForm(options, NY, lateNight),
                NY,
                'key-1',
            ).idempotency_key,
        ).toBe('key-1');
    });

    it('sends a link only when there is one', () => {
        expect(
            buildUpdatePayload(
                {
                    type: 'follow_up_sent',
                    occurredOn: '2026-11-12',
                    note: '',
                    url: ' https://x.example/y ',
                },
                NY,
                'k',
            ),
        ).toMatchObject({ url: 'https://x.example/y' });
        expect(
            buildUpdatePayload(
                {
                    type: 'follow_up_sent',
                    occurredOn: '2026-11-12',
                    note: '',
                    url: '',
                },
                NY,
                'k',
            ),
        ).not.toHaveProperty('url');
    });
});

describe('server errors', () => {
    it('land on the right fields and keep the reason', () => {
        expect(
            mapUpdateErrors({
                occurred_on: 'In the future.',
                reason: 'already_ended',
                submission: 'It ended.',
            }),
        ).toEqual({
            occurredOn: 'In the future.',
            reason: 'already_ended',
            general: 'It ended.',
        });
    });
});

describe('correction', () => {
    it('needs an explicit confirmation, and a reason is optional', () => {
        expect(
            validateCorrection({ confirmed: false, reason: '' }).confirmed,
        ).toBeDefined();
        expect(validateCorrection({ confirmed: true, reason: '' })).toEqual({});
        expect(
            validateCorrection({ confirmed: true, reason: 'x'.repeat(5001) })
                .reason,
        ).toBeDefined();
    });

    it('sends the confirmation and the reason only when given, with the stable key', () => {
        expect(
            buildCorrectionPayload({ confirmed: true, reason: '' }, 'k'),
        ).toEqual({
            idempotency_key: 'k',
            confirmed: true,
            return_to: 'opportunity',
        });
        expect(
            buildCorrectionPayload(
                { confirmed: true, reason: ' Only a draft ' },
                'k',
            ),
        ).toMatchObject({ reason: 'Only a draft' });
    });
});

describe('history and status', () => {
    const record = (
        over: Partial<SubmissionRecord> = {},
    ): SubmissionRecord => ({
        id: 1,
        submitted_at: '2026-11-01T05:00:00Z',
        submitted_on: '2026-11-01',
        recorded_at: null,
        method: 'manual',
        phase: 'interviewing',
        phase_label: 'Interviewing',
        active: true,
        confirmed: true,
        application_url: null,
        notes: null,
        resume: null,
        pdf_kept: false,
        inspection_linked: false,
        in_error: false,
        ended: false,
        interviews: 1,
        follow_ups: 0,
        allowed_updates: options,
        events: [
            {
                id: 3,
                type: 'interview',
                label: 'Interview',
                occurred_on: '2026-11-09',
                note: 'Phone screen',
                url: null,
                recorded_at: '',
                source: 'user',
            },
            {
                id: 2,
                type: 'confirmation_received',
                label: 'Confirmation received',
                occurred_on: '2026-11-02',
                note: null,
                url: 'https://example.com/ok',
                recorded_at: '',
                source: 'user',
            },
        ],
        ...over,
    });

    it('lists the updates newest first and ends with the submission, dated as entered', () => {
        const entries = historyEntries(record());

        expect(entries.map((e) => e.label)).toEqual([
            'Interview',
            'Confirmation received',
            'Application submitted',
        ]);
        expect(entries.map((e) => e.date)).toEqual([
            'Nov 9, 2026',
            'Nov 2, 2026',
            'Nov 1, 2026',
        ]);
        expect(entries[0].note).toBe('Phone screen');
        expect(entries[1].url).toBe('https://example.com/ok');
    });

    it('shows just the submission when there are no updates', () => {
        expect(
            historyEntries(record({ events: [] })).map((e) => e.label),
        ).toEqual(['Application submitted']);
    });

    it('never shows a raw event value as a label', () => {
        for (const entry of historyEntries(record())) {
            expect(entry.label).not.toMatch(/_/);
        }
    });

    it('offers updates while in flight, and neither updates nor a restart once ended', () => {
        expect(recordActions(record())).toEqual({
            canUpdate: true,
            canCorrect: true,
        });
        expect(
            recordActions(
                record({
                    allowed_updates: [],
                    ended: true,
                    active: false,
                    phase: 'rejected',
                    phase_label: 'Rejected',
                }),
            ),
        ).toEqual({ canUpdate: false, canCorrect: true });
    });

    it('allows no actions on a record marked in error', () => {
        expect(
            recordActions(record({ allowed_updates: [], in_error: true })),
        ).toEqual({ canUpdate: false, canCorrect: false });
    });

    it('describes an ended or mistaken record, and is silent for one in flight', () => {
        expect(statusNote(record())).toBeNull();
        expect(
            statusNote(record({ ended: true, phase_label: 'Rejected' })),
        ).toMatch(/ended \(Rejected\)/);
        expect(
            statusNote(record({ ended: true, phase_label: 'Rejected' })),
        ).toMatch(/Record another application/);
        expect(statusNote(record({ in_error: true }))).toMatch(
            /recorded in error/,
        );
    });

    it('splits the ones that count from the ones marked in error', () => {
        const { counted, inError } = splitRecords([
            record({ id: 1 }),
            record({ id: 2, in_error: true }),
            record({ id: 3 }),
        ]);

        expect(counted.map((r) => r.id)).toEqual([1, 3]);
        expect(inError.map((r) => r.id)).toEqual([2]);
    });
});
