import { useState } from 'react';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TextInput } from '@/components/ui/field';
import { canUndoDecision, decisionSummaryText } from '@/lib/opportunities-list';
import type {
    DecisionResult,
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

/** What a decision did, with Undo while that is still possible, and why anything was refused. */
export function DecisionResultPanel({
    result,
    busy,
    onUndo,
    onDismiss,
}: {
    result: DecisionResult;
    busy: boolean;
    onUndo: () => void;
    onDismiss: () => void;
}) {
    const refused = new Map<string, number>();

    for (const item of result.failed) {
        refused.set(item.message, (refused.get(item.message) ?? 0) + 1);
    }

    return (
        <Alert
            tone={result.failed.length > 0 ? 'warn' : 'neutral'}
            role="status"
            className="mt-4 flex items-start justify-between gap-3"
        >
            <div className="min-w-0">
                <p className="text-fg font-medium">
                    {decisionSummaryText(result)}
                </p>
                {[...refused].map(([message, count]) => (
                    <p key={message} className="text-fg-subtle mt-1 text-xs">
                        {count > 1 ? `${count} jobs: ` : ''}
                        {message}
                    </p>
                ))}
            </div>
            <div className="flex shrink-0 items-center gap-1">
                {canUndoDecision(result) && (
                    <Button size="sm" disabled={busy} onClick={onUndo}>
                        Undo
                    </Button>
                )}
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Dismiss message"
                    onClick={onDismiss}
                >
                    ×
                </Button>
            </div>
        </Alert>
    );
}
