export type Box = { width: number; height: number };
export type Rect = { top: number; bottom: number; left: number; right: number };

export const POPOVER_GAP = 4;
export const POPOVER_EDGE = 8;

/**
 * Where a fixed-position popover goes relative to its trigger: below it when it fits (or when there is more room below
 * than above), otherwise flipped above; right-aligned to the trigger and kept inside the viewport on both sides.
 */
export function computePopoverPosition(
    anchor: Rect,
    popover: Box,
    viewport: Box,
): { top: number; left: number } {
    const below = viewport.height - anchor.bottom - POPOVER_GAP - POPOVER_EDGE;
    const above = anchor.top - POPOVER_GAP - POPOVER_EDGE;
    const top =
        popover.height <= below || below >= above
            ? anchor.bottom + POPOVER_GAP
            : anchor.top - POPOVER_GAP - popover.height;
    const left = Math.min(
        Math.max(POPOVER_EDGE, anchor.right - popover.width),
        viewport.width - popover.width - POPOVER_EDGE,
    );

    return { top: Math.max(POPOVER_EDGE, top), left: Math.max(POPOVER_EDGE, left) };
}

/** A dismissal needs a reason; the note is optional and trimmed. */
export function dismissalPayload(
    reason: string | null,
    note: string,
): { reason: string; note: string } | null {
    return reason === null || reason === ''
        ? null
        : { reason, note: note.trim() };
}
