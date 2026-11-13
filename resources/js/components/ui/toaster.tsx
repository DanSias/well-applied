import { Toaster as Sonner } from 'sonner';

/**
 * The single, app-wide toast outlet (mounted once, above every page, in app.tsx). Toasts float over the interface at
 * the bottom right, and Sonner centers them across the bottom on narrow screens. They pause while hovered or focused,
 * stay out of the document flow, and honor reduced motion. Colors come from the design tokens.
 */
export function Toaster({
    id,
    inset,
}: {
    /** Names a second toaster (inside a modal surface); toasts sent to that id show there instead. */
    id?: string;
    /** Keep clear of a sticky footer: extra distance from the bottom edge, in pixels. */
    inset?: number;
} = {}) {
    return (
        <Sonner
            id={id}
            theme="dark"
            position="bottom-right"
            richColors
            closeButton
            visibleToasts={3}
            offset={inset === undefined ? 16 : { right: 16, bottom: inset }}
            mobileOffset={inset === undefined ? 12 : { left: 12, right: 12, bottom: inset }}
            style={
                {
                    '--normal-bg': 'var(--color-raised)',
                    '--normal-border': 'var(--color-line-strong)',
                    '--normal-text': 'var(--color-fg)',
                    '--success-bg': 'var(--color-ok-soft)',
                    '--success-border': 'var(--color-ok-line)',
                    '--success-text': 'var(--color-fg)',
                    '--info-bg': 'var(--color-accent-soft)',
                    '--info-border': 'var(--color-accent)',
                    '--info-text': 'var(--color-fg)',
                    '--warning-bg': 'var(--color-warn-soft)',
                    '--warning-border': 'var(--color-warn-line)',
                    '--warning-text': 'var(--color-fg)',
                    '--error-bg': 'var(--color-bad-soft)',
                    '--error-border': 'var(--color-bad-line)',
                    '--error-text': 'var(--color-fg)',
                    '--border-radius': 'var(--radius-control, 8px)',
                } as React.CSSProperties
            }
            toastOptions={{ classNames: { description: 'toast-description' } }}
        />
    );
}
