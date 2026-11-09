import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Native checkbox (keeps keyboard and screen-reader behavior) in the accent color. */
export function Checkbox({ className, ...props }: ComponentProps<'input'>) {
    return (
        <input
            type="checkbox"
            className={cn(
                'h-4 w-4 shrink-0 cursor-pointer rounded-[4px] accent-accent disabled:cursor-not-allowed disabled:opacity-45',
                className,
            )}
            {...props}
        />
    );
}
