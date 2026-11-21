import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import {
    jobicyNoApplyLinkState,
    stateOf,
    runOf,
} from '@/lib/inspection-test-data';
import type { ApplicationShowProps } from '@/types/application-inspection';
import ApplicationShow from '@/pages/applications/show';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: vi.fn() },
    useForm: () => ({ processing: false, post: vi.fn() }),
}));
vi.mock('@/layouts/app-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
}));
vi.mock('@/hooks/use-application-inspection-polling', () => ({
    useApplicationInspectionPolling: (_id: number, initial: unknown) => initial,
}));
vi.mock('@/hooks/use-worker-status-polling', () => ({
    useWorkerStatusPolling: () => ({ state: 'online', last_seen_seconds: 1 }),
}));

const props = (
    inspection: ApplicationShowProps['inspection'],
): ApplicationShowProps => ({
    application: {
        id: 9,
        job_posting: {
            id: 348,
            company: 'PlusAI',
            title: 'Software Integration Engineer',
            source_url:
                'https://jobicy.com/jobs/162959-software-integration-engineer',
            home_url: '/opportunities/348',
        },
    },
    inspection,
});

const render = (
    inspection: ApplicationShowProps['inspection'],
    freshness?: ApplicationShowProps['application']['job_posting']['freshness'],
) => {
    const p = props(inspection);
    if (freshness !== undefined) {
        p.application.job_posting.freshness = freshness;
    }

    return renderToStaticMarkup(<ApplicationShow {...p} />);
};

describe('the full application page for a listing whose application form was not found (run 11)', () => {
    const html = render(jobicyNoApplyLinkState());
    const main = html.split('Technical details')[0];

    it('uses the same status label and explanation as the drawer, not the raw code', () => {
        expect(main).toContain('Needs application URL');
        expect(main).toContain('the listing on jobicy.com');
        expect(main).not.toContain('application_not_found');
        expect(main).not.toContain('Last inspection succeeded');
    });

    it('shows the same URL facts and stop reason', () => {
        expect(main).toContain('Started from');
        expect(main).toContain('Application URL');
        expect(main).toContain('None yet');
        expect(main).toContain('Why it stopped');
        expect(main).toContain('uses an Apply button rather than a link');
    });

    it('lists the repeated warning once with its count, only in the technical details', () => {
        expect(html.split('blocked_non_get').length - 1).toBe(1);
        expect(html).toContain('blocked_non_get ×18');
        expect(main).not.toContain('blocked_non_get');
    });

    it('offers the existing Enter application URL action, prominently', () => {
        expect(main).toMatch(
            /<section aria-labelledby="enter-application-url"/,
        );
        expect(main).toContain('Save and inspect');
    });

    it('keeps a finished run distinct from the missing application', () => {
        expect(html.split('Technical details')[1]).toContain(
            'Worker run finished',
        );
        expect(html).not.toContain('Inspection failed');
    });

    it('still lets a person inspect again', () => {
        expect(html).toContain('Inspect again');
    });
});

describe('the full page for other states', () => {
    it('shows nothing about a result before anything has run, and no URL form', () => {
        const html = render(stateOf(null));

        expect(html).toContain('Inspect application form');
        expect(html).not.toContain('Enter application URL');
        expect(html).not.toContain('Technical details');
    });

    it('does not present a running inspection as a result', () => {
        const html = render(stateOf(runOf('running')));

        expect(html).toContain('Inspecting…');
        expect(html).not.toContain('Needs application URL');
    });
});

describe('job freshness on the application page', () => {
    const base = {
        reason: 'x',
        confirmed_at: null,
        checked: true,
    };
    const expired = {
        ...base,
        state: 'expired' as const,
        label: 'Expired',
        actionable: false,
        tone: 'warn' as const,
        detail: 'The provider gave this job an expiry date, and it has passed.',
        new_work_block:
            'This job has expired, so new pursuit work cannot be started on it.',
    };
    const unchecked = {
        ...base,
        state: 'unchecked' as const,
        label: 'Not live-checked',
        actionable: true,
        tone: 'neutral' as const,
        detail: 'No live check with the provider has been made, so this job is open only as far as our records go.',
        new_work_block: null,
        checked: false,
    };
    const DISABLED = / disabled=""/;
    const startButton = (html: string) =>
        html.match(
            /<button[^>]*>[^<]*Inspect application form[^<]*<\/button>/,
        )?.[0] ?? '';

    it('shows the freshness of an expired job once, keeps the page readable, and blocks a new inspection', () => {
        const html = render(stateOf(null), expired);

        expect(html).toContain('Expired');
        expect(html.split('it has passed').length - 1).toBe(1);
        expect(html).toContain(
            'This job has expired, so new pursuit work cannot be started',
        );
        expect(startButton(html)).toMatch(DISABLED);
        expect(html).toContain('Back to job');
    });

    it('keeps an unchecked job quiet (a muted line, no badge) and its inspection startable', () => {
        const html = render(stateOf(null), unchecked);

        expect(html).toContain('Not live-checked: No live check');
        expect(html).not.toContain('new pursuit work');
        expect(startButton(html)).not.toMatch(DISABLED);
    });

    it('shows nothing about freshness with the feature off, and the page is unchanged', () => {
        const html = render(stateOf(null));

        expect(html).not.toContain('Not live-checked');
        expect(html).not.toContain('Expired');
        expect(startButton(html)).not.toMatch(DISABLED);
    });
});
