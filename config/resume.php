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

    /*
    | How a reference resume presents Technical Skills (see SkillPresentation). Keyed by the canonical Skill name:
    | [category, display label]. A skill that is not listed is not shown, which keeps internal taxonomy tags
    | ("Deterministic AI boundaries", "Data pipelines") and obscure tools off the page; a listed skill still has to be
    | selected from approved evidence. `line_chars` is the width, label included, a line is packed to.
    */
    'skill_presentation' => [
        'groups' => ['Languages', 'Frontend', 'Backend', 'Data & Cloud', 'Tools & Workflow', 'AI & Integrations'],
        'line_chars' => 88,
        'max_per_group' => 5,
        // The technologies a Full-Stack Developer resume should lead with; a skill gets this lift only if approved evidence carries it.
        'core' => ['TypeScript', 'JavaScript', 'SQL', 'React', 'Vue.js', 'Node.js', 'Laravel', 'REST APIs', 'PostgreSQL', 'MySQL', 'AWS', 'Google Cloud (general)'],
        'skills' => [
            'TypeScript' => ['Languages', 'TypeScript'], 'JavaScript' => ['Languages', 'JavaScript'], 'PHP' => ['Languages', 'PHP'], 'SQL' => ['Languages', 'SQL'],
            'React' => ['Frontend', 'React'], 'Vue.js' => ['Frontend', 'Vue.js'], 'Next.js' => ['Frontend', 'Next.js'], 'Tailwind CSS' => ['Frontend', 'Tailwind CSS'],
            'Node.js' => ['Backend', 'Node.js'], 'Express.js' => ['Backend', 'Express'], 'Laravel' => ['Backend', 'Laravel'], 'REST APIs' => ['Backend', 'REST APIs'],
            'PostgreSQL' => ['Data & Cloud', 'PostgreSQL'], 'MySQL' => ['Data & Cloud', 'MySQL'], 'SQLite' => ['Data & Cloud', 'SQLite'], 'BigQuery' => ['Data & Cloud', 'BigQuery'],
            'Google Cloud (general)' => ['Data & Cloud', 'Google Cloud'], 'Google Cloud Functions' => ['Data & Cloud', 'Cloud Functions'], 'AWS' => ['Data & Cloud', 'AWS'],
            'Microsoft Azure' => ['Data & Cloud', 'Azure'], 'Docker' => ['Tools & Workflow', 'Docker'], 'Git' => ['Tools & Workflow', 'Git'],
            // A model provider is a detail; the evidence (deterministic retrieval with an LLM used for synthesis) is LLM integration.
            'OpenAI' => ['AI & Integrations', 'LLM integration'], 'Anthropic' => ['AI & Integrations', 'LLM integration'],
            'Jira' => ['Tools & Workflow', 'Jira'], 'GitLab' => ['Tools & Workflow', 'GitLab'],
            'Salesforce' => ['AI & Integrations', 'Salesforce'], 'API integration & design' => ['Backend', 'API design & integration'],
        ],
    ],

];
