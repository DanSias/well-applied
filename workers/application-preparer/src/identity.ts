// Tracking one logical field across DOM changes. A framework may remove a
// control after an action (a file widget replacing its input), re-render it
// as a new node, insert siblings or reorder. Identity is therefore never
// positional: the element id is tried first, then the logical key, and any
// ambiguity is reported instead of guessed.
import type { DiscoveredField } from './contracts.ts';

export type Tracking =
    /** The same DOM element is still there, with the same id. */
    | 'same_element'
    /** The element was replaced; exactly one new field has the original's logical key. */
    | 'rematched_by_key'
    /** Several candidates (or the original had look-alikes): not attributed to any. */
    | 'ambiguous'
    /** Nothing current corresponds to it. */
    | 'removed';

export interface Located {
    tracking: Tracking;
    field: DiscoveredField | null;
    note: string;
}

type Identity = Pick<DiscoveredField, 'id' | 'key' | 'kind' | 'ambiguous'>;

/**
 * @param original  the field as first discovered
 * @param current   the fields discovered now
 * @param previousIds ids that already existed at the earlier discovery. A current
 *   field with one of these is a different, pre-existing element and is never a
 *   re-render of the original.
 */
export function locateField(
    original: Identity,
    current: readonly DiscoveredField[],
    previousIds: ReadonlySet<string>,
): Located {
    const same = current.find((f) => f.id === original.id);
    if (same && same.kind === original.kind) {
        return {
            tracking: 'same_element',
            field: same,
            note: 'the same element is still present',
        };
    }

    if (original.ambiguous) {
        return {
            tracking: 'ambiguous',
            field: null,
            note: 'the field shared its identifying properties with another field, so a replacement cannot be told apart',
        };
    }

    // Only elements that did not exist before can be a re-render of the original.
    const candidates = current.filter(
        (f) => f.key === original.key && !previousIds.has(f.id),
    );
    if (candidates.length === 1 && !candidates[0]!.ambiguous) {
        return {
            tracking: 'rematched_by_key',
            field: candidates[0]!,
            note: 'the element was replaced by one with the same identifying properties',
        };
    }
    if (candidates.length >= 1) {
        return {
            tracking: 'ambiguous',
            field: null,
            note: `${candidates.length} new fields match the original's identifying properties`,
        };
    }
    return {
        tracking: 'removed',
        field: null,
        note: 'the element is no longer on the page',
    };
}
