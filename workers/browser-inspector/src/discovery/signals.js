// The shape of what the in-page collector (collect.js) hands to the pure normalizer (normalize.js). Facts only: nothing in
// it has been interpreted, and nothing in it is a value a person entered. JSON-serialisable by construction (it crosses the
// page.evaluate boundary), which is also what lets the normalizer be tested without a browser.
//
// @typedef {object} LabelText   text with aria-hidden descendants removed; `marker` is true when a required marker was shown
//   { text: string, marker: boolean }
//
// @typedef {object} Candidate   one control-like element, visible or not, in document order
//   n            number        document-order index
//   tag, type, role, id, name, testid   string|null
//   css          string        a selector that finds this element (an #id when unique, else a short path)
//   userVisible  boolean       a person could see and operate it: laid out, not visibility:hidden, not opacity:0 (a file input
//                              behind a styled button is the one deliberate exception), not aria-hidden, not in a hidden subtree
//   inHiddenSubtree boolean    display:none or [hidden] on it or an ancestor (the markup has it; nobody sees it yet)
//   ariaHidden   boolean       aria-hidden="true" on it or an ancestor
//   tabindex     string|null
//   isNative     boolean       a real input/select/textarea (has the `required` property) as opposed to a custom ARIA widget
//   required     boolean       the native `required` property
//   ariaRequired 'true'|'false'|null
//   multiple     boolean       a native select (or file input) that takes several values
//   clipped      boolean       laid out but visually hidden (1px, clipped, opacity 0): a file input behind a styled button
//   accept       string|null
//   ariaHaspopup, ariaControls   string|null
//   labels       { forLabels: LabelText[], wrapping: LabelText|null, ariaLabel: string|null,
//                  labelledby: Array<LabelText & { inside: boolean }>,   inside = the referenced element is within the control
//                  title: string|null, placeholder: string|null }
//   group        { kind: 'fieldset'|'role_group'|'radiogroup', css: string, label: string|null,
//                  labelSource: 'legend'|'labelledby'|'aria_label'|null } | null   nearest labelled group
//   options      Array<{ text, value, disabled }> | null   a native select's options
//   popup        { hidden: boolean, options: string[] } | null   the listbox this control opens, when it is in the markup
//   ancestors    number[]      ids of up to 6 ancestors, nearest first; the same element has the same id for every candidate
//   proximity    Array<{ text: string, ref: number }>   up to 3 preceding sibling texts, nearest first (bounded length); `ref`
//                identifies the sibling so one text is never claimed as two controls' labels
//   heading      string|null   nearest preceding heading in the same section
//   section      string|null   the closest fieldset's legend, else the nearest preceding heading   (the existing rule)
//   sectionIsGroup boolean     `section` came from a fieldset legend
//   labelText    string|null   the visible text of a wrapping/for label that names a CHECKBOX or RADIO option
//
// @typedef {object} Signals
//   url, title            string
//   candidates            Candidate[]
//   orphanLabels          Array<{ for: string, text: string }>   label[for] whose target is not in the page
//   steps                 { current: number|null, total: number|null, source: string } | null
//   counts                { buttons: number, hiddenInputs: number }
export {};
