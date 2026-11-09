import type { KeyboardEvent, ReactNode } from 'react';
import { useRef } from 'react';
import { cn } from '@/lib/utils';

export type TabItem<T extends string> = {
    id: T;
    label: string;
    /** Small trailing marker such as a count or a dot. */
    badge?: ReactNode;
};

/**
 * Segmented tab control with roving tabindex and arrow-key navigation.
 * The caller renders the panel with `id={`${idPrefix}-panel`}` and
 * `aria-labelledby={`${idPrefix}-${value}`}`.
 */
export function Tabs<T extends string>({
    items,
    value,
    onChange,
    idPrefix,
    label,
}: {
    items: TabItem<T>[];
    value: T;
    onChange: (id: T) => void;
    idPrefix: string;
    label: string;
}) {
    const refs = useRef<Record<string, HTMLButtonElement | null>>({});

    const onKeyDown = (e: KeyboardEvent, index: number) => {
        const step =
            e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;

        if (step === 0) {
            return;
        }

        e.preventDefault();
        const next = items[(index + step + items.length) % items.length];
        onChange(next.id);
        refs.current[next.id]?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label={label}
            className="inline-flex max-w-full gap-0.5 overflow-x-auto rounded-card border border-line bg-sunken p-1"
        >
            {items.map((item, i) => {
                const selected = item.id === value;

                return (
                    <button
                        key={item.id}
                        ref={(el) => {
                            refs.current[item.id] = el;
                        }}
                        id={`${idPrefix}-${item.id}`}
                        role="tab"
                        type="button"
                        aria-selected={selected}
                        aria-controls={`${idPrefix}-panel`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(item.id)}
                        onKeyDown={(e) => onKeyDown(e, i)}
                        className={cn(
                            'inline-flex items-center gap-2 rounded-control px-3.5 py-1.5 text-sm font-medium whitespace-nowrap transition-colors',
                            selected
                                ? 'bg-raised text-fg shadow-card ring-1 ring-line-strong'
                                : 'text-fg-muted hover:text-fg',
                        )}
                    >
                        {item.label}
                        {item.badge}
                    </button>
                );
            })}
        </div>
    );
}
