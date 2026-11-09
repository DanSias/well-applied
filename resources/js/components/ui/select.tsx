import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';
import { fieldClass } from './field';

/** Native select with the shared input styling. */
export function Select({ className, children, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(fieldClass, 'w-auto max-w-full cursor-pointer py-1.5 pr-8', className)}
            {...props}
        >
            {children}
        </select>
    );
}
