<?php

return [

    /*
    | CONFIDENTIAL terms: identifiers that must never appear in resume
    | text that cites a `Visibility::Restricted` CareerFact (client or
    | vendor names, internal field names). Matched case-insensitively on
    | word boundaries by App\Support\ResumeVariant\RestrictedEvidenceGuard.
    | A match is a BLOCK finding: the text is generalized or omitted
    | deterministically (see RecoverWording), never shipped. Scoped to
    | Restricted-cited locations only: the same word is fine where only
    | Public evidence is cited.
    |
    | This list is a visibility-policy decision and is reviewable here in
    | one place. Nothing in the canonical career data is changed by
    | editing it.
    */
    'restricted_terms' => [
        'UDF',
        'UDF1',
        'Brute Strength',
        'Tech Guys Who Get Marketing',
    ],

    /*
    | GENERIC industry terms. Ordinary vocabulary that happens to appear
    | in Restricted evidence ("merchant", "donor") is not confidential on
    | its own, so it does not block a bullet. When such a term is used
    | where Restricted evidence is cited, the bullet is kept and flagged
    | for human REVIEW in the variant's review report. Specific figures,
    | named parties and hostnames stay blocked regardless.
    */
    'generic_terms' => [
        'merchant',
        'merchants',
        'donor',
        'donors',
    ],

    /*
    | Curated generalizations: confidential term => generic phrase that
    | is accurate for every use of that term. Applied only to a bullet
    | that was BLOCKED for that term; the result is re-checked and
    | flagged for review. Empty by default: a replacement is a factual
    | claim about the term, so each entry must be added deliberately,
    | e.g. 'Example Client Inc' => 'a client'. Without an entry the
    | blocked bullet is omitted instead.
    */
    'generalizations' => [],

];
