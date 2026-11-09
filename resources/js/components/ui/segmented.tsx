import { cn } from '@/lib/utils';

export type SegmentedItem<T extends string> = { value: T; label: string };

/**
 * A single-choice filter row (not tabs: it changes what a list shows, not
 * which panel is visible). Buttons expose their state with aria-pressed.
 */
export function SegmentedControl<T extends string>({
    items,
    value,
    onChange,
    label,
    className,
}: {
    items: SegmentedItem<T>[];
    value: T;
    onChange: (value: T) => void;
    label: string;
    className?: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn(
                'inline-flex max-w-full gap-0.5 overflow-x-auto rounded-control border border-line bg-sunken p-0.5',
                className,
            )}
        >
            {items.map((item) => {
                const selected = item.value === value;

                return (
                    <button
                        key={item.value}
                        type="button"
                        aria-pressed={selected}
                        onClick={() => onChange(item.value)}
                        className={cn(
                            'rounded-[6px] px-2.5 py-1 text-sm font-medium whitespace-nowrap transition-colors',
                            selected
                                ? 'bg-raised text-fg shadow-card ring-1 ring-line-strong'
                                : 'text-fg-muted hover:text-fg',
                        )}
                    >
                        {item.label}
                    </button>
                );
            })}
        </div>
    );
}
