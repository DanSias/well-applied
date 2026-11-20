import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { presentInspection } from '@/lib/inspection-status';
import {
    diagnosticsOf,
    jobicyNoApplyLinkState,
    resultOf,
    runOf,
    stateOf,
} from '@/lib/inspection-test-data';
import {
    ApplicationUrlForm,
    InspectionFindings,
    submitApplicationUrl,
    TechnicalDetails,
} from './inspection-findings';

describe('submitApplicationUrl: the existing application-URL override, as the form uses it', () => {
    it('refuses an empty or blank field without asking the server', () => {
        const onSubmit = vi.fn();
        const setProblem = vi.fn();

        submitApplicationUrl('   ', onSubmit, setProblem);

        expect(onSubmit).not.toHaveBeenCalled();
        expect(setProblem).toHaveBeenLastCalledWith(
            'Enter the application URL first.',
        );
    });

    it("hands over the trimmed URL, and lets the server's refusal appear beside the field", () => {
        const onSubmit = vi.fn();
        const setProblem = vi.fn();

        submitApplicationUrl(
            '  https://boards.greenhouse.io/plusai/jobs/1  ',
            onSubmit,
            setProblem,
        );

        expect(onSubmit).toHaveBeenCalledWith(
            'https://boards.greenhouse.io/plusai/jobs/1',
            setProblem,
        );
        expect(setProblem).toHaveBeenCalledWith(null);
    });
});

describe('ApplicationUrlForm', () => {
    const render = (
        over: Partial<React.ComponentProps<typeof ApplicationUrlForm>> = {},
    ) =>
        renderToStaticMarkup(
            <ApplicationUrlForm
                saved={null}
                prominent={false}
                disabled={false}
                onSubmit={() => {}}
                {...over}
            />,
        );

    it('is open and prominent when the application form was not found', () => {
        const html = render({ prominent: true });

        expect(html).toContain(
            '<section aria-labelledby="enter-application-url"',
        );
        expect(html).not.toContain('<details');
    });

    it('is one quiet click away otherwise, and says "different" when you already entered one', () => {
        expect(render()).toMatch(
            /<details[^>]*>\s*<summary[^>]*>\s*Enter application URL/,
        );
        expect(
            render({
                saved: {
                    url: 'https://x.example.com/a',
                    origin: 'user',
                    set_at: null,
                },
            }),
        ).toContain('Use a different application URL');
    });

    it('cannot be submitted while an inspection is running', () => {
        expect(render({ prominent: true, disabled: true })).toMatch(
            /<button[^>]*disabled/,
        );
    });
});

describe('InspectionFindings and TechnicalDetails', () => {
    const state = jobicyNoApplyLinkState();
    const view = presentInspection(state);

    it('keeps the user-facing findings free of raw codes, and the details complete', () => {
        const findings = renderToStaticMarkup(
            <InspectionFindings
                view={view}
                result={state.latest_result}
                attempt={null}
                link={null}
            />,
        );
        const details = renderToStaticMarkup(
            <TechnicalDetails
                diagnostics={state.latest_result!.diagnostics}
                view={view}
                run={state.workflow_run}
                result={state.latest_result}
            />,
        );

        expect(findings).not.toMatch(
            /application_not_found|no_apply_link|blocked_non_get|networkidle/,
        );
        expect(details).toContain('application_not_found / no_apply_link');
        expect(details).toContain('HTTP status');
        expect(details).toContain('blocked_non_get ×18');
    });

    it('does not claim a resolved URL it was never given, and does not invent a start for an older record', () => {
        const older = stateOf(
            runOf('succeeded'),
            resultOf('unsupported', {
                diagnostics: diagnosticsOf({
                    recorded: false,
                    requested_url: null,
                    final_url: null,
                    start_origin: null,
                }),
            }),
        );
        const html = renderToStaticMarkup(
            <InspectionFindings
                view={presentInspection(older)}
                result={older.latest_result}
                attempt={null}
                link={null}
            />,
        );

        expect(html).not.toContain('Started from');
        expect(html).not.toContain('Why it stopped');
        expect(html).toContain('None yet');
    });

    it('shows a URL this very run resolved, as found by this inspection', () => {
        const resolved = stateOf(
            runOf('succeeded'),
            resultOf('complete', {
                diagnostics: diagnosticsOf({
                    resolved_url: 'https://boards.greenhouse.io/acme/jobs/9',
                }),
            }),
        );
        const html = renderToStaticMarkup(
            <InspectionFindings
                view={presentInspection(resolved)}
                result={resolved.latest_result}
                attempt={null}
                link={null}
            />,
        );

        expect(html).toContain('https://boards.greenhouse.io/acme/jobs/9');
        expect(html).toContain('(found by this inspection)');
    });
});
