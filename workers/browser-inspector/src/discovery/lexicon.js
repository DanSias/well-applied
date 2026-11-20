// Words, patterns and thresholds for generic form discovery. Plain data and pure functions: no DOM, no browser.
//
// Everything here is a HEURISTIC about wording, so everything that uses it reports how it knew (basis) and how sure it is
// (confidence). Nothing in this file may look at an answer: a question is classified by its label and context, never by
// what a person has entered or selected.

/** Accessible names that say what the control IS, not what it is FOR: "Attach", "Upload", "Select...". */
const GENERIC_NAMES = /^(attach|attach (a )?file|upload|upload (a )?(file|document)|browse|browse files?|choose( a)? file|choose|select|select\.{2,3}|select…|search|search\.{2,3}|enter manually|add (a )?file|drop files? here|click to (upload|select|attach))$/i;

export function isGenericName(text) {
    return typeof text === 'string' && GENERIC_NAMES.test(text.replace(/\s+/g, ' ').trim());
}

/** The widget's own prompt text, as seen by a proximity search: a sign the search is inside a widget, not beside a question. */
export function isGenericPrompt(text) {
    return typeof text === 'string' && /^select(\.{2,3}|…)?$/i.test(text.trim());
}

/** A trailing required marker: "First Name*", "Email *". Returns the text without it, and whether it was there. */
export function stripRequiredMarker(text) {
    const t = (text ?? '').replace(/\s+/g, ' ').trim();
    const marked = /\s*\*+$/.test(t);

    return { text: marked ? t.replace(/\s*\*+$/, '').trim() : t, marked };
}

// ---- sensitive questions --------------------------------------------------------------------------------------------
// "Sensitive" here means a voluntary demographic or equal-opportunity (EEO) category. These are questions the inspector
// reports by label and flags, and that application preparation must never answer on its own.

/** A heading, legend or section name that announces a self-identification block. */
export const SENSITIVE_SECTION = /\b(self[- ]?identif\w*|demographic\w*|equal (employment )?opportunity|eeoc?\b|voluntary (disclosure|self))/i;

// The QUESTION must be about the category, in question form. A bare keyword is not enough: "Describe a race condition you
// debugged", "How many years have you supported veterans' hiring programs?" and "...software for people with disabilities"
// are ordinary questions.
const LEAD = '(?:what is your |how would you describe your |please (?:select|indicate) your |do you identify as |are you (?:a |an )?)?';
// `(?![\w-])` ends a keyword: "gender" ends the question, "gender-neutral" and "genderless" do not.
const END = '(?![\\w-])';
const SENSITIVE_LABELS = [
    ['gender', new RegExp(`^${LEAD}gender( identity)?${END}`, 'i')],
    ['race_ethnicity', new RegExp(`^${LEAD}(race|racial(\\s*/\\s*ethnic)?( background)?|ethnic(ity| background)|race\\s*/\\s*ethnicity)${END}`, 'i')],
    ['hispanic_latino', new RegExp(`^(are you )?(hispanic|latinx?)${END}`, 'i')],
    ['sexual_orientation', /\bsexual orientation\b/i],
    ['transgender', new RegExp(`^(do you identify as )?(transgender|trans)${END}`, 'i')],
    ['veteran', new RegExp(`^(are you (a |an )?(protected )?veteran|veteran( status)?${END}|protected veteran)`, 'i')],
    ['disability', new RegExp(`^(do you have a disability|disability( status)?${END})|\\bdisability or chronic condition\\b`, 'i')],
    ['religion', /^(what is your )?religio/i],
];

/**
 * @param {{label: string|null, section: string|null, sectionIsGroup: boolean}} q
 * @returns {{value: boolean, basis: string|null, confidence: 'structural'|'inferred'|null, matched: string|null}}
 */
export function classifySensitive({ label, section, sectionIsGroup }) {
    const byLabel = SENSITIVE_LABELS.find(([, re]) => typeof label === 'string' && re.test(label.trim()));
    const bySection = typeof section === 'string' && SENSITIVE_SECTION.test(section);

    if (bySection) {
        // A fieldset legend or labelled group is structure; a heading merely above the control is positional.
        return { value: true, basis: 'section', confidence: sectionIsGroup ? 'structural' : 'inferred', matched: section.trim().slice(0, 80) };
    }
    if (byLabel) return { value: true, basis: 'label_pattern', confidence: 'inferred', matched: byLabel[0] };

    return { value: false, basis: null, confidence: null, matched: null };
}

/** Longest preceding text accepted as a proximity label (same bound as the existing extractor). */
export const PROXIMITY_MAX_LENGTH = 200;
