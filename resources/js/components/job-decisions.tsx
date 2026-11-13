import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { OverflowMenu, type MenuItem } from '@/components/overflow-menu';
import { TextInput } from '@/components/ui/field';
import type {
    DismissalReason,
    JobDisposition,
    JobDispositionSummary,
} from '@/types/job-posting';

export const DISMISSAL_REASONS: { value: DismissalReason; label: string }[] = [
    { value: 'seniority', label: 'Seniority' },
    { value: 'technology_stack', label: 'Technology stack' },
    { value: 'location', label: 'Location' },
    { value: 'compensation', label: 'Compensation' },
    { value: 'company', label: 'Company' },
    { value: 'duplicate', label: 'Duplicate' },
    { value: 'other', label: 'Other' },
];

/** Decide on one or more jobs; a dismissal also carries its reason and an optional note. */
export type DecideFn = (
    to: JobDisposition,
    options?: { reason?: DismissalReason; note?: string },
) => void;

/** Where a job stands, shown for everything except an undecided one (the Inbox needs no label). */
export function DispositionBadge({
    disposition,
}: {
    disposition: JobDispositionSummary;
}) {
    switch (disposition.value) {
        case 'shortlisted':
            return <Badge tone="accent">Shortlisted</Badge>;
        case 'dismissed':
            return (
                <Badge>
                    Dismissed
                    {disposition.reason_label
                        ? ` · ${disposition.reason_label}`
                        : ''}
                </Badge>
            );
        case 'pursued':
            return <Badge tone="ok">Pursued</Badge>;
        default:
            return null;
    }
}

/**
 * The few decisions that make sense for a job where it stands. Dismissing opens the reason strip instead of deciding
 * at once; everything else is one click.
 */
export function DecisionActions({
    disposition,
    jobLabel,
    disabled,
    onDecide,
    onStartDismiss,
}: {
    disposition: JobDisposition;
    jobLabel: string;
    disabled: boolean;
    onDecide: DecideFn;
    onStartDismiss: () => void;
}) {
    const dismiss = (
        <Button
            variant="ghost"
            size="sm"
            disabled={disabled}
            aria-label={`Dismiss ${jobLabel}`}
            onClick={onStartDismiss}
        >
            Dismiss
        </Button>
    );
    const restore = (
        <Button
            variant="ghost"
            size="sm"
            disabled={disabled}
            aria-label={`Restore ${jobLabel} to Inbox`}
            onClick={() => onDecide('new')}
        >
            Restore to Inbox
        </Button>
    );

    switch (disposition) {
        case 'new':
            return (
                <>
                    <Button
                        size="sm"
                        disabled={disabled}
                        aria-label={`Shortlist ${jobLabel}`}
                        onClick={() => onDecide('shortlisted')}
                    >
                        Shortlist
                    </Button>
                    <Button
                        size="sm"
                        disabled={disabled}
                        aria-label={`Pursue ${jobLabel}`}
                        onClick={() => onDecide('pursued')}
                    >
                        Pursue
                    </Button>
                    {dismiss}
                </>
            );
        case 'shortlisted':
            return (
                <>
                    <Button
                        size="sm"
                        disabled={disabled}
                        aria-label={`Pursue ${jobLabel}`}
                        onClick={() => onDecide('pursued')}
                    >
                        Pursue
                    </Button>
                    {dismiss}
                    {restore}
                </>
            );
        case 'dismissed':
            return restore;
        default:
            return null;
    }
}

/**
 * Pick why a job (or a selection) is being dismissed: one click on a reason decides, with an optional note typed first.
 * Inline rather than a modal, so the common case stays fast and nothing covers the list.
 */
export function DismissStrip({
    heading,
    disabled,
    onPick,
    onCancel,
}: {
    heading: string;
    disabled: boolean;
    onPick: (reason: DismissalReason, note: string) => void;
    onCancel: () => void;
}) {
    const [note, setNote] = useState('');

    return (
        <div
            role="group"
            aria-label={heading}
            onKeyDown={(e) => {
                if (e.key === 'Escape') {
                    onCancel();
                }
            }}
            className="flex flex-col gap-2"
        >
            <p className="text-fg-muted text-xs font-medium">{heading}</p>
            <div className="flex flex-wrap gap-1.5">
                {DISMISSAL_REASONS.map((reason) => (
                    <Button
                        key={reason.value}
                        size="sm"
                        disabled={disabled}
                        onClick={() => onPick(reason.value, note)}
                    >
                        {reason.label}
                    </Button>
                ))}
            </div>
            <div className="flex items-center gap-2">
                <TextInput
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    maxLength={500}
                    placeholder="Add a note (optional)"
                    aria-label="Dismissal note"
                    className="h-8 max-w-sm py-1 text-xs"
                />
                <Button variant="ghost" size="sm" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}

/**
 * The actions on a compact list row. The quick ones stay visible as real buttons (Shortlist and Dismiss on an Inbox
 * row, so many jobs can be triaged fast, with Pursue as the next step on a shortlisted one); the rarer ones sit in the
 * overflow menu, with any extras the row supplies (such as inspecting the application form). Nothing is hover-only.
 */
export function CompactRowActions({
    disposition,
    jobLabel,
    disabled,
    onDecide,
    onStartDismiss,
    extraItems,
}: {
    disposition: JobDisposition;
    jobLabel: string;
    disabled: boolean;
    onDecide: DecideFn;
    onStartDismiss: () => void;
    extraItems: MenuItem[];
}) {
    const dismiss = (
        <Button
            variant="ghost"
            size="sm"
            disabled={disabled}
            aria-label={`Dismiss ${jobLabel}`}
            onClick={onStartDismiss}
        >
            Dismiss
        </Button>
    );
    const menu = (items: MenuItem[]) =>
        items.length > 0 ? (
            <OverflowMenu
                label={`More actions for ${jobLabel}`}
                items={items}
                disabled={disabled}
            />
        ) : null;

    switch (disposition) {
        case 'new':
            return (
                <>
                    <Button
                        size="sm"
                        disabled={disabled}
                        aria-label={`Shortlist ${jobLabel}`}
                        onClick={() => onDecide('shortlisted')}
                    >
                        Shortlist
                    </Button>
                    {dismiss}
                    {menu([
                        {
                            label: 'Pursue',
                            onSelect: () => onDecide('pursued'),
                        },
                        ...extraItems,
                    ])}
                </>
            );
        case 'shortlisted':
            return (
                <>
                    <Button
                        size="sm"
                        disabled={disabled}
                        aria-label={`Pursue ${jobLabel}`}
                        onClick={() => onDecide('pursued')}
                    >
                        Pursue
                    </Button>
                    {dismiss}
                    {menu([
                        {
                            label: 'Restore to Inbox',
                            onSelect: () => onDecide('new'),
                        },
                        ...extraItems,
                    ])}
                </>
            );
        case 'dismissed':
            return (
                <>
                    <Button
                        size="sm"
                        disabled={disabled}
                        aria-label={`Restore ${jobLabel} to Inbox`}
                        onClick={() => onDecide('new')}
                    >
                        Restore to Inbox
                    </Button>
                    {menu(extraItems)}
                </>
            );
        default:
            return menu(extraItems);
    }
}
