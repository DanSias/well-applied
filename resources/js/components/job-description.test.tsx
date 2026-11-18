import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { JobDescription } from './job-description';

describe('JobDescription', () => {
    it('renders blank-line-separated bullets as one compact list, never as HTML', () => {
        const html = renderToStaticMarkup(
            <JobDescription
                text={'Requirements:\n\n- A\n\n- B <b>x</b>\n\nClosing.'}
            />,
        );
        expect(html.match(/<ul/g)).toHaveLength(1);
        expect(html.match(/<li>/g)).toHaveLength(2);
        expect(html).toContain('space-y-1.5');
        expect(html).toContain('&lt;b&gt;x&lt;/b&gt;');
        expect(html.match(/<p /g)).toHaveLength(2);
    });
});
