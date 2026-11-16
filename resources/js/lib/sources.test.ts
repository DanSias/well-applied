import { describe, expect, it } from 'vitest';
import type { SourceAttempt, SourceProvider, SourceRun } from '@/types/sources';
import {
    availabilityChip,
    buildSourcesParams,
    configurationRows,
    count,
    diagnosticRows,
    discoveredJobsHref,
    filteredCandidatesHref,
    formatDuration,
    outcomeChip,
    failureLine,
    systemFailureExplanation,
    providerOutcomeChip,
    runFinishedToast,
    runRequestToast,
    scheduleToast,
    schedulerNote,
    outcomeMark,
    providerStatus,
    runMark,
    attentionNote,
    retrievedLabel,
    runTotals,
    scheduleChip,
    setupNeeded,
} from './sources';

const attempt = (over: Partial<SourceAttempt> = {}): SourceAttempt => ({
    id: 1,
    run_id: 1,
    provider: 'jobicy',
    status: 'succeeded',
    started_at: '2026-11-15T10:00:00Z',
    finished_at: '2026-11-15T10:01:00Z',
    duration_seconds: 60,
    retrieved: 10,
    accepted: 4,
    created: 2,
    updated: 2,
    canonicalized: 0,
    filtered: 6,
    filtered_by_reason: [],
    retrieved_basis: 'feed',
    retrieval_diagnostics: null,
    failure: null,
    ...over,
});

const provider = (over: Partial<SourceProvider> = {}): SourceProvider => ({
    provider: 'jobicy',
    label: 'Jobicy',
    registered: true,
    configured: true,
    unavailable_reason: null,
    default_schedule: 'automatic',
    effective_schedule: 'automatic',
    manual_run_available: true,
    stale: false,
    overdue: false,
    never_run: false,
    run_now: {
        available: true,
        active: null,
        attempt_id: null,
        since: null,
        unavailable_reason: null,
    },
    schedule_override: 'default',
    schedule_control: { label: 'Pause scheduling', override: 'paused' },
    needs_attention: false,
    last_attempt: null,
    last_success: null,
    last_failure: null,
    jobs_added: 0,
    window: { attempts: 0, failed: 0, partial: 0 },
    ...over,
});

describe('provider chips keep availability, schedule and outcome apart', () => {
    it('can be configured but manual only', () => {
        const p = provider({ effective_schedule: 'manual_only' });

        expect(availabilityChip(p).text).toBe('Configured');
        expect(scheduleChip(p).text).toBe('Manual only');
    });

    it('can be unconfigured while still registered', () => {
        const p = provider({ configured: false, registered: true });

        expect(availabilityChip(p)).toEqual({
            text: 'Not configured',
            tone: 'warn',
        });
    });

    it('tells never-run, zero-result success, partial and failed apart', () => {
        expect(outcomeChip(null).text).toBe('Never run');
        expect(outcomeChip(attempt({ retrieved: 0 })).text).toBe(
            'Succeeded · no results',
        );
        expect(outcomeChip(attempt()).text).toBe('Succeeded');
        expect(outcomeChip(attempt({ status: 'partial' })).text).toBe(
            'Partial',
        );
        expect(outcomeChip(attempt({ status: 'failed' }))).toEqual({
            text: 'Failed',
            tone: 'bad',
        });
    });

    it('flags a never-run automatic source as a warning, and leaves a manual one neutral', () => {
        expect(
            providerOutcomeChip(
                provider({ last_attempt: null, never_run: true }),
            ),
        ).toEqual({ text: 'Never run', tone: 'warn' });
        expect(
            providerOutcomeChip(
                provider({ last_attempt: null, never_run: false }),
            ),
        ).toEqual({ text: 'Never run', tone: 'neutral' });
    });

    it('does not call a failure with no counters a zero-result success', () => {
        expect(
            outcomeChip(attempt({ status: 'failed', retrieved: null })).text,
        ).toBe('Failed');
    });
});

describe('counters', () => {
    it('never turns an unrecorded counter into zero', () => {
        expect(count(null)).toBe('—');
        expect(count(0)).toBe('0');
    });

    it('labels Eightfold retrieved as after its own prefilter', () => {
        expect(retrievedLabel({ retrieved_basis: 'after_prefilter' })).toBe(
            'Retrieved (after prefilter)',
        );
        expect(retrievedLabel({ retrieved_basis: 'feed' })).toBe('Retrieved');
    });

    it('formats durations', () => {
        expect(formatDuration(null)).toBeNull();
        expect(formatDuration(42)).toBe('42s');
        expect(formatDuration(125)).toBe('2m 5s');
    });
});

describe('run totals', () => {
    const run = (attempts: SourceAttempt[]): SourceRun => ({
        id: 1,
        status: 'succeeded',
        started_at: null,
        finished_at: null,
        duration_seconds: null,
        needs_attention: false,
        attempts,
    });

    it('adds recorded counters and ignores unrecorded ones', () => {
        expect(
            runTotals(
                run([
                    attempt({ created: 2, updated: 1 }),
                    attempt({ created: null, updated: 3 }),
                ]),
            ),
        ).toEqual({ created: 2, updated: 4 });
    });

    it('stays null when nothing was recorded, and 0 when zero was', () => {
        expect(
            runTotals(run([attempt({ created: null, updated: null })])),
        ).toEqual({ created: null, updated: null });
        expect(runTotals(run([attempt({ created: 0, updated: 0 })]))).toEqual({
            created: 0,
            updated: 0,
        });
    });
});

describe('setup versus operational trouble', () => {
    it('lists only unconfigured providers as needing setup', () => {
        expect(
            setupNeeded([
                provider(),
                provider({
                    provider: 'usajobs',
                    label: 'USAJOBS',
                    configured: false,
                }),
            ]),
        ).toEqual(['USAJOBS']);
    });
});

describe('diagnostics and configuration', () => {
    it('flattens nested diagnostics', () => {
        expect(
            diagnosticRows({
                search_rows: 12,
                prefilter_dropped: { wrong_location: 3 },
            }),
        ).toEqual([
            { label: 'Search rows', value: '12' },
            { label: 'Prefilter dropped: Wrong location', value: '3' },
        ]);
        expect(diagnosticRows(null)).toEqual([]);
    });

    it('shows credentials only as present or missing', () => {
        expect(
            configurationRows({ credentials_present: true, country: 'us' }),
        ).toEqual([
            { label: 'Credentials', value: 'Present' },
            { label: 'Country', value: 'us' },
        ]);
        expect(configurationRows({ credentials_present: false })[0].value).toBe(
            'Missing',
        );
    });

    it('keeps tenants and budget for their own rendering', () => {
        expect(
            configurationRows({ enabled: true, tenants: [], budget: {} }),
        ).toEqual([{ label: 'Enabled', value: 'Yes' }]);
    });
});

describe('urls', () => {
    it('leaves defaults out', () => {
        expect(buildSourcesParams({})).toEqual({});
        expect(buildSourcesParams({ page: 1 })).toEqual({});
        expect(buildSourcesParams({ provider: 'jobicy', page: 3 })).toEqual({
            provider: 'jobicy',
            page: 3,
        });
    });

    it('carries history paging only while a provider is open', () => {
        expect(buildSourcesParams({ history_page: 2 })).toEqual({});
        expect(
            buildSourcesParams({ provider: 'jobicy', history_page: 2 }),
        ).toEqual({ provider: 'jobicy', history_page: 2 });
    });

    it('links to every posting of a source, and to its filtered candidates', () => {
        expect(discoveredJobsHref('himalayas')).toBe(
            '/jobs?source=himalayas&disposition=all',
        );
        expect(filteredCandidatesHref('usajobs')).toBe(
            '/discovery/rejections?provider=usajobs',
        );
    });
});

describe('scheduling', () => {
    it('shows a paused provider in warning tone and the others neutral', () => {
        expect(
            scheduleChip(provider({ effective_schedule: 'paused' })),
        ).toEqual({ text: 'Paused', tone: 'warn' });
        expect(
            scheduleChip(provider({ effective_schedule: 'automatic' })),
        ).toEqual({ text: 'Automatic', tone: 'neutral' });
    });

    it('words the toast from the resulting schedule, and says a running run is not stopped', () => {
        expect(
            scheduleToast({ provider: 'Jobicy', schedule: 'paused' }),
        ).toMatchObject({ title: 'Jobicy scheduling paused' });
        expect(
            scheduleToast({ provider: 'Jobicy', schedule: 'paused' })
                .description,
        ).toContain('not stopped');
        expect(
            scheduleToast({ provider: 'Jobicy', schedule: 'automatic' }).title,
        ).toBe('Jobicy scheduling is on');
    });
});

describe('run now', () => {
    it('acknowledges only the request, and says nothing was started for a duplicate or a refused queue', () => {
        expect(
            runRequestToast({ provider: 'Jobicy', outcome: 'queued' }),
        ).toMatchObject({ kind: 'success', title: 'Jobicy run queued' });
        expect(
            runRequestToast({ provider: 'Jobicy', outcome: 'already_active' }),
        ).toMatchObject({ kind: 'info' });
        expect(
            runRequestToast({ provider: 'Jobicy', outcome: 'not_queued' })
                .description,
        ).toContain('nothing was started');
    });

    it('reports the real outcome of a finished run, and stays quiet while it is still going', () => {
        expect(
            runFinishedToast(
                provider({ last_attempt: attempt({ status: 'running' }) }),
            ),
        ).toBeNull();
        expect(
            runFinishedToast(
                provider({ last_attempt: attempt({ status: 'queued' }) }),
            ),
        ).toBeNull();
        expect(
            runFinishedToast(
                provider({ label: 'Jobicy', last_attempt: attempt() }),
            ),
        ).toMatchObject({ kind: 'success', title: 'Jobicy run finished' });
        expect(
            runFinishedToast(
                provider({
                    label: 'Jobicy',
                    last_attempt: attempt({
                        status: 'failed',
                        failure: {
                            category: 'provider_error',
                            message: 'HTTP 500',
                            exception_class: null,
                        },
                    }),
                }),
            ),
        ).toMatchObject({
            kind: 'error',
            title: 'Jobicy run failed',
            description: 'HTTP 500',
        });
        expect(
            runFinishedToast(
                provider({ last_attempt: attempt({ status: 'partial' }) }),
            )?.kind,
        ).toBe('warning');
    });
});

describe('scheduler note', () => {
    const at = {
        last_observed_at: '2026-11-16T10:00:00Z',
    };

    it('is reassuring when recently seen, and warns only when something is meant to be automatic', () => {
        expect(
            schedulerNote({ state: 'recent', age_seconds: 30, ...at }, 3)?.tone,
        ).toBe('ok');
        expect(
            schedulerNote({ state: 'stale', age_seconds: 1500, ...at }, 3),
        ).toMatchObject({ tone: 'warn' });
        expect(
            schedulerNote({ state: 'stale', age_seconds: 1500, ...at }, 3)
                ?.text,
        ).toContain('25 minutes');
        expect(
            schedulerNote({ state: 'stale', age_seconds: 1500, ...at }, 0),
        ).toBeNull();
        expect(
            schedulerNote(
                { state: 'unknown', age_seconds: null, last_observed_at: null },
                2,
            )?.text,
        ).toContain('not detected');
    });
});

describe('system failure wording', () => {
    const failed = (category: string, message = 'stored message') =>
        attempt({
            status: 'failed',
            failure: { category, message, exception_class: null },
        });

    it('gives each system-recorded failure its own chip, and never calls any of them a success', () => {
        expect(outcomeChip(failed('interrupted'))).toEqual({
            text: 'Interrupted',
            tone: 'bad',
        });
        expect(outcomeChip(failed('lease_lost'))).toEqual({
            text: 'Stopped early',
            tone: 'bad',
        });
        expect(outcomeChip(failed('deadline_exceeded'))).toEqual({
            text: 'Time limit reached',
            tone: 'warn',
        });
        expect(outcomeChip(failed('not_started'))).toEqual({
            text: 'Not started',
            tone: 'warn',
        });
        expect(outcomeChip(failed('provider_unavailable')).text).toBe(
            'Not started',
        );

        for (const category of [
            'interrupted',
            'lease_lost',
            'deadline_exceeded',
            'not_started',
        ]) {
            expect(outcomeChip(failed(category)).tone).not.toBe('ok');
        }
    });

    it("leaves an ordinary provider failure as Failed, with the provider's own message", () => {
        expect(outcomeChip(failed('provider_error'))).toEqual({
            text: 'Failed',
            tone: 'bad',
        });
        expect(failureLine(failed('provider_error', 'HTTP 500'))).toBe(
            'HTTP 500',
        );
        expect(systemFailureExplanation('provider_error')).toBeNull();
        expect(systemFailureExplanation(null)).toBeNull();
    });

    it('explains system failures in plain terms, says what was kept, and exposes no exception detail', () => {
        const line = failureLine(
            failed('lease_lost', 'The provider lease was lost.'),
        );

        expect(line).toContain('no longer held its claim');
        expect(line).toContain('already added was kept');
        expect(line).not.toContain('LeaseLost');
        expect(failureLine(failed('deadline_exceeded'))).toContain(
            'incomplete',
        );
        expect(failureLine(failed('not_started'))).toContain('Nothing was run');
    });
});

describe('calm status presentation', () => {
    const failed = (category: string | null) =>
        attempt({
            status: 'failed',
            failure: { category, message: 'm', exception_class: null },
        });

    it('marks a plain success as a labelled icon, a zero-result success as a distinct icon, and never colour alone', () => {
        expect(outcomeMark(attempt())).toEqual({
            kind: 'icon',
            icon: 'success',
            label: 'Last run succeeded',
            short: 'Succeeded',
        });
        expect(outcomeMark(attempt({ retrieved: 0 }))).toMatchObject({
            kind: 'icon',
            icon: 'success-empty',
            label: 'Last run succeeded with no results',
        });
        expect(outcomeMark(attempt(), 'Run')).toMatchObject({
            label: 'Run succeeded',
        });
    });

    it('keeps every exceptional outcome as words', () => {
        const text = (a: SourceAttempt | null) => {
            const mark = outcomeMark(a);

            return mark.kind === 'badge' ? mark.text : null;
        };

        expect(text(attempt({ status: 'partial' }))).toBe('Partial');
        expect(text(failed('provider_error'))).toBe('Failed');
        expect(text(failed('interrupted'))).toBe('Interrupted');
        expect(text(failed('lease_lost'))).toBe('Stopped early');
        expect(text(failed('deadline_exceeded'))).toBe('Time limit reached');
        expect(text(failed('not_started'))).toBe('Not started');
        expect(text(attempt({ status: 'queued' }))).toBe('Queued');
        expect(text(attempt({ status: 'running' }))).toBe('Running');
        expect(text(null)).toBe('Never run');
    });

    it('warns about a never-run automatic provider only', () => {
        expect(outcomeMark(null, 'Last run', true)).toMatchObject({
            tone: 'warn',
        });
        expect(outcomeMark(null, 'Last run', false)).toMatchObject({
            tone: 'neutral',
        });
    });

    it('describes a healthy configured provider without a setup flag or a configured badge', () => {
        expect(providerStatus(provider())).toMatchObject({
            needsSetup: false,
            schedule: { text: 'Automatic' },
            overdue: false,
        });
    });

    it('says needs setup, with no schedule, for a provider that is not configured or unavailable', () => {
        const status = providerStatus(
            provider({ configured: false, effective_schedule: 'unavailable' }),
        );

        expect(status.needsSetup).toBe(true);
        expect(status.schedule).toBeNull();
    });

    it('keeps the schedule states explicit: automatic, manual only and paused (a warning)', () => {
        expect(
            providerStatus(provider({ effective_schedule: 'manual_only' }))
                .schedule,
        ).toEqual({ text: 'Manual only', tone: 'neutral' });
        expect(
            providerStatus(provider({ effective_schedule: 'paused' })).schedule,
        ).toEqual({ text: 'Paused', tone: 'warn' });
    });

    it('marks a run as one tick when it succeeded, and in words otherwise', () => {
        const base = { id: 1, status: 'succeeded' } as unknown as SourceRun;

        expect(runMark(base)).toMatchObject({
            kind: 'icon',
            label: 'Run succeeded',
        });
        expect(runMark({ ...base, status: 'partial' })).toMatchObject({
            kind: 'badge',
            text: 'Partial',
        });
        expect(runMark({ ...base, status: 'failed' })).toMatchObject({
            kind: 'badge',
            text: 'Failed',
        });
        expect(runMark({ ...base, status: 'queued' })).toMatchObject({
            kind: 'badge',
            text: 'Queued',
        });
        expect(runMark({ ...base, status: 'running' })).toMatchObject({
            kind: 'badge',
            text: 'Running',
        });
    });

    it('is silent about attention when all is well, and names only what is exceptional', () => {
        expect(attentionNote([], [])).toBeUndefined();
        expect(attentionNote(['Jobicy'], [])).toBe(
            'Failing, partial or overdue: Jobicy',
        );
        expect(attentionNote([], ['Eightfold'])).toBe('Not run yet: Eightfold');
        expect(attentionNote(['Jobicy'], ['Eightfold'])).toBe(
            'Failing, partial or overdue: Jobicy. Not run yet: Eightfold',
        );
    });
});
