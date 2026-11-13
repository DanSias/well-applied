import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type MenuItem = {
    label: string;
    /** Navigate (an Inertia link) instead of running `onSelect`. */
    href?: string;
    onSelect?: () => void;
};

const ITEM_HEIGHT = 36;

/**
 * A compact "more actions" menu for the less frequent actions on a row. The menu is positioned against the viewport
 * (so a list that clips its overflow cannot cut it off), flips upward near the bottom edge, and closes on Escape, on an
 * outside click, on scroll and after a choice. Opening moves focus into the menu; arrows move between items; closing
 * returns focus to the button. A real button with aria-haspopup, so it works with touch and keyboard alike.
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
    const [position, setPosition] = useState<{
        right: number;
        top?: number;
        bottom?: number;
    } | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    const open = position !== null;

    const close = (returnFocus = true) => {
        setPosition(null);

        if (returnFocus) {
            trigger.current?.focus();
        }
    };

    const toggle = () => {
        if (open) {
            close();

            return;
        }

        const rect = trigger.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        const needed = items.length * ITEM_HEIGHT + 12;
        const right = Math.max(8, window.innerWidth - rect.right);

        setPosition(
            rect.bottom + needed > window.innerHeight && rect.top > needed
                ? { right, bottom: window.innerHeight - rect.top + 4 }
                : { right, top: rect.bottom + 4 },
        );
    };

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
        const dismiss = () => close(false);

        document.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('scroll', dismiss, true);
        window.addEventListener('resize', dismiss);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            window.removeEventListener('scroll', dismiss, true);
            window.removeEventListener('resize', dismiss);
        };
    }, [open]);

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
                onClick={toggle}
                className={cn(
                    'rounded-control text-fg-muted hover:bg-raised hover:text-fg inline-flex h-8 w-8 items-center justify-center transition-colors disabled:pointer-events-none disabled:opacity-45',
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
            {open && (
                <div
                    ref={menu}
                    role="menu"
                    aria-label={label}
                    onKeyDown={onKeyDown}
                    style={{
                        right: position.right,
                        top: position.top,
                        bottom: position.bottom,
                    }}
                    className="border-line-strong bg-raised shadow-card rounded-card fixed z-50 min-w-48 border p-1"
                >
                    {items.map((item) =>
                        item.href !== undefined ? (
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
                </div>
            )}
        </>
    );
}
