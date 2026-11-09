import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

const field =
    'block w-full rounded-control border border-line-strong bg-sunken px-3 py-2 text-sm leading-relaxed text-fg placeholder:text-fg-subtle transition-colors hover:border-fg-subtle focus:border-focus focus-visible:ring-2 focus-visible:ring-focus/40 focus-visible:outline-none aria-[invalid=true]:border-bad';

export function TextArea({ className, ...props }: ComponentProps<'textarea'>) {
    return <textarea className={cn(field, 'resize-y', className)} {...props} />;
}

export function TextInput({ className, ...props }: ComponentProps<'input'>) {
    return <input className={cn(field, className)} {...props} />;
}

export function FieldError({ children }: { children?: ReactNode }) {
    if (!children) {
        return null;
    }

    return (
        <p role="alert" className="mt-1.5 text-sm text-bad">
            {children}
        </p>
    );
}
