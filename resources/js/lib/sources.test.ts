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
