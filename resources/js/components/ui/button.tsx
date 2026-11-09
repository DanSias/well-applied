import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Button hierarchy: one `primary` per view (the obvious next action),
 * `secondary` for other real actions, `ghost` for low-emphasis controls
 * (row actions, dismiss), `danger` for destructive ones.
 */
export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md';

const base =
    'inline-flex items-center justify-center gap-1.5 rounded-control font-medium whitespace-nowrap transition-colors disabled:pointer-events-none disabled:opacity-45';

const variants: Record<ButtonVariant, string> = {
    primary: 'bg-accent text-accent-fg shadow-card hover:bg-accent-hover',
    secondary:
        'bg-raised text-fg ring-1 ring-inset ring-line-strong hover:bg-hover',
    ghost: 'text-fg-muted hover:bg-raised hover:text-fg',
    danger: 'text-bad ring-1 ring-inset ring-bad-line hover:bg-bad-soft',
};

const sizes: Record<ButtonSize, string> = {
    sm: 'h-8 px-2.5 text-xs',
    md: 'h-9 px-3.5 text-sm',
};

export function buttonClass(
    variant: ButtonVariant = 'secondary',
    size: ButtonSize = 'md',
    className?: string,
) {
    return cn(base, variants[variant], sizes[size], className);
}

type Shared = { variant?: ButtonVariant; size?: ButtonSize };

export function Button({
    variant,
    size,
    className,
    type = 'button',
    ...props
}: Shared & ComponentProps<'button'>) {
    return (
        <button
            type={type}
            className={buttonClass(variant, size, className)}
            {...props}
        />
    );
}

/** A plain download/navigation anchor that looks like a button. */
export function ButtonAnchor({
    variant,
    size,
    className,
    ...props
}: Shared & ComponentProps<'a'>) {
    return <a className={buttonClass(variant, size, className)} {...props} />;
}

/** An Inertia link that looks like a button. */
export function ButtonLink({
    variant,
    size,
    className,
    href,
    children,
}: Shared & { href: string; className?: string; children: ReactNode }) {
    return (
        <Link href={href} className={buttonClass(variant, size, className)}>
            {children}
        </Link>
    );
}
