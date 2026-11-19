import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { ResumeStepper } from './resume-stepper';
import type { ResumeStep } from '@/lib/resume-stepper';

const steps = (...states: ResumeStep['state'][]): ResumeStep[] =>
    states.map((state, i) => ({
        key: (['analyze', 'compose', 'validate'] as const)[i],
        label: ['Analyze requirements', 'Compose resume', 'Validate PDF'][i],
        state,
        note: null,
    }));

describe('ResumeStepper', () => {
    it('renders nothing without steps', () => {
        expect(renderToStaticMarkup(<ResumeStepper steps={[]} />)).toBe('');
    });

    it('renders three labelled steps with a checkmark per completed one and the elapsed time', () => {
        const html = renderToStaticMarkup(
            <ResumeStepper
                steps={steps('done', 'active', 'pending')}
                elapsed="1:05 elapsed"
            />,
        );

        expect(html.match(/<li/g)).toHaveLength(3);
        expect(html).toContain('Analyze requirements');
        expect(html).toContain('Compose resume');
        expect(html).toContain('Validate PDF');
        expect(html.match(/M3\.5 8\.5l3 3 6-7/g)).toHaveLength(1);
        expect(html).toContain('1:05 elapsed');
    });

    it('shows short labels and a title row, but still announces each step by its full name', () => {
        const html = renderToStaticMarkup(
            <ResumeStepper
                steps={steps('done', 'active', 'pending')}
                elapsed="0:42 elapsed"
            />,
        );

        expect(html).toContain('Resume generation');
        expect(html).toContain('<span aria-hidden="true">Analyze</span>');
        expect(html).toContain('Analyze requirements, completed');
        expect(html).toContain('@container');
    });

    it('marks only the active step as the current one, and announces every state', () => {
        const html = renderToStaticMarkup(
            <ResumeStepper steps={steps('done', 'active', 'pending')} />,
        );

        expect(html.match(/aria-current="step"/g)).toHaveLength(1);
        expect(html).toContain(', completed');
        expect(html).toContain(', in progress');
        expect(html).toContain(', not started');
    });

    it('shows failed and interrupted steps distinctly, without a checkmark', () => {
        const failed = renderToStaticMarkup(
            <ResumeStepper steps={steps('done', 'failed', 'pending')} />,
        );
        const stopped = renderToStaticMarkup(
            <ResumeStepper steps={steps('done', 'interrupted', 'pending')} />,
        );

        expect(failed).toContain(', failed');
        expect(failed).toContain('✕');
        expect(stopped).toContain(', interrupted');
        expect(stopped).not.toContain('✕');
    });
});
