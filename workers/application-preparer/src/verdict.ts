// Pure judgement of "does a person see the value we meant to enter?".
// The rule that matters: the user-visible control is the evidence. A hidden
// input holding the right value is never, on its own, verification of what
// is displayed.

export interface ControlState {
    /** Strictly visible: rendered, not visibility:hidden, not opacity:0, non-zero size, not off-screen. */
    visible: boolean;
    /** What the visible control displays (its value). */
    visibleValue: string;
    /** Generic autocomplete/combobox traits (ARIA, list=, widget-ish class names) and related hidden inputs. */
    composite: boolean;
    signals: string[];
    /** Values of hidden inputs that belong to this control (related by name), not unrelated hidden fields. */
    hiddenValues: string[];
}

export type TextVerdict =
    | { status: 'filled_verified'; note?: undefined }
    | { status: 'unverified'; note: string }
    | { status: 'error'; note: string };

export function judgeText(expected: string, c: ControlState): TextVerdict {
    if (!c.visible) {
        return {
            status: 'unverified',
            note: 'the control is not visible, so what a person would see cannot be confirmed (a hidden value is not evidence)',
        };
    }
    const hiddenMatches = c.hiddenValues.some((v) => v === expected);

    if (c.visibleValue !== expected) {
        return {
            status: 'error',
            note: hiddenMatches
                ? 'the displayed value differs from what was entered, even though a hidden input holds it; displayed value wins'
                : 'the displayed value differs from what was entered',
        };
    }

    if (c.composite && !hiddenMatches) {
        return {
            status: 'unverified',
            note:
                c.hiddenValues.length > 0
                    ? 'composite/autocomplete control: the text is displayed but its underlying value does not match, so the choice may not be committed'
                    : 'composite/autocomplete control: the text is displayed but no committed value could be confirmed (a suggestion may need to be chosen)',
        };
    }
    return { status: 'filled_verified' };
}

/** The worse of two verdicts for the same field (error > unverified > verified). */
export function worse(a: TextVerdict, b: TextVerdict): TextVerdict {
    const rank = { filled_verified: 0, unverified: 1, error: 2 } as const;
    return rank[b.status] > rank[a.status] ? b : a;
}
