import { Link } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

export type MenuItem = {
    label: string;
    /** Navigate (an Inertia link) instead of running `onSelect`. */
    href?: string;
    /** `href` leaves the app: a plain link that opens in a new tab, not an Inertia visit. */
    external?: boolean;
    onSelect?: () => void;
};

const GAP = 4;
const EDGE = 8;

/**
 * A compact "more actions" menu for the less frequent actions on a row.
 *
 * The menu is rendered in a portal on <body> and positioned against the viewport. It used to render inside the row's
 * action cluster, which is `relative z-10` and therefore its own stacking context: the menu's own z-index only ranked
 * inside that cluster, so the next row's cluster (same z-index, later in the DOM) painted over it. A portal takes it out
 * of every row and list container, so nothing can stack over or clip it and no oversized z-index is needed.
 *
 * It measures itself to flip above the trigger near the bottom edge and to stay inside the viewport horizontally, follows
 * the trigger while an ancestor scrolls (closing if the trigger scrolls out of view), and closes on Escape, on an outside
 * click and after a choice. Opening moves focus into the menu; arrows, Home and End move between items; closing returns
 * focus to the button.
 */
export function OverflowMenu({
    label,
    items,
    disabled = false,
}: {
    label: string;
    items: MenuItem[];
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    const close = useCallback((returnFocus = true) => {
        setOpen(false);

        if (returnFocus) {
            trigger.current?.focus();
        }
    }, []);

    const place = useCallback(() => {
        const button = trigger.current?.getBoundingClientRect();
        const box = menu.current;

        if (!button || !box) {
            return;
        }

        if (button.bottom < 0 || button.top > window.innerHeight) {
            close(false);

            return;
        }

        const { width, height } = box.getBoundingClientRect();
        const below = window.innerHeight - button.bottom - GAP - EDGE;
        const above = button.top - GAP - EDGE;
        const top =
            height <= below || below >= above
                ? button.bottom + GAP
                : button.top - GAP - height;
        const left = Math.min(
            Math.max(EDGE, button.right - width),
            window.innerWidth - width - EDGE,
        );

        box.style.top = `${Math.max(EDGE, top)}px`;
        box.style.left = `${left}px`;
        box.style.maxHeight = `${Math.max(120, window.innerHeight - 2 * EDGE)}px`;
        box.style.visibility = 'visible';
    }, [close]);

    useLayoutEffect(() => {
        if (open) {
            place();
        }
    }, [open, place]);

    useEffect(() => {
        if (!open) {
            return;
        }

        menu.current?.querySelector<HTMLElement>('[role=menuitem]')?.focus();

        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as Node;

            if (
                !menu.current?.contains(target) &&
                !trigger.current?.contains(target)
            ) {
                close(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open, close, place]);

    const onKeyDown = (event: React.KeyboardEvent) => {
        const entries = [
            ...(menu.current?.querySelectorAll<HTMLElement>(
                '[role=menuitem]',
            ) ?? []),
        ];
        const index = entries.indexOf(document.activeElement as HTMLElement);

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            entries[(index + 1) % entries.length]?.focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            entries[(index - 1 + entries.length) % entries.length]?.focus();
        } else if (event.key === 'Home') {
            event.preventDefault();
            entries[0]?.focus();
        } else if (event.key === 'End') {
            event.preventDefault();
            entries[entries.length - 1]?.focus();
        } else if (event.key === 'Tab') {
            close(false);
        }
    };

    const itemClass =
        'flex h-9 w-full items-center rounded-control px-3 text-left text-sm whitespace-nowrap text-fg hover:bg-hover focus-visible:bg-hover focus-visible:outline-none';

    return (
        <>
            <button
                ref={trigger}
                type="button"
                disabled={disabled}
                aria-label={label}
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={() => (open ? close() : setOpen(true))}
                className={cn(
                    'rounded-control text-fg-muted hover:bg-raised hover:text-fg focus-visible:ring-focus inline-flex h-8 w-8 items-center justify-center transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-45',
                    open && 'bg-raised text-fg',
                )}
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 16 16"
                    className="h-4 w-4"
                    fill="currentColor"
                >
                    <circle cx="3" cy="8" r="1.4" />
                    <circle cx="8" cy="8" r="1.4" />
                    <circle cx="13" cy="8" r="1.4" />
                </svg>
            </button>
            {open &&
                createPortal(
                    <div
                        ref={menu}
                        role="menu"
                        aria-label={label}
                        onKeyDown={onKeyDown}
                        style={{ visibility: 'hidden' }}
                        className="border-line-strong bg-raised shadow-card rounded-card fixed top-0 left-0 z-50 min-w-48 overflow-y-auto border p-1"
                    >
                        {items.map((item) =>
                            item.href !== undefined && item.external ? (
                                <a
                                    key={item.label}
                                    role="menuitem"
                                    href={item.href}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={itemClass}
                                    onClick={() => close(false)}
                                >
                                    {item.label}
                                </a>
                            ) : item.href !== undefined ? (
                                <Link
                                    key={item.label}
                                    role="menuitem"
                                    href={item.href}
                                    className={itemClass}
                                    onClick={() => close(false)}
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <button
                                    key={item.label}
                                    type="button"
                                    role="menuitem"
                                    className={itemClass}
                                    onClick={() => {
                                        close(false);
                                        item.onSelect?.();
                                    }}
                                >
                                    {item.label}
                                </button>
                            ),
                        )}
                    </div>,
                    document.body,
                )}
        </>
    );
}
