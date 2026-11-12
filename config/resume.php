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
        /*
        | Alternative groupings, chosen per kind of resume rather than per job (see GenerateResumeDocument). A layout only
        | regroups: the skill still has to be in 'skills' above (which supplies its display label) and still has to be
        | selected from approved evidence, and a skill the layout does not place is not shown. Canonical skill categories
        | are never touched. 'tailored' is the compact, architecture-oriented grouping used for resumes written for a job.
        */
        'layouts' => [
            'tailored' => [
                'groups' => ['Languages & Frameworks', 'Architecture & Integration', 'AI & Data', 'Cloud & Delivery'],
                'categories' => [
                    'TypeScript' => 'Languages & Frameworks', 'JavaScript' => 'Languages & Frameworks', 'PHP' => 'Languages & Frameworks', 'SQL' => 'Languages & Frameworks',
                    'React' => 'Languages & Frameworks', 'Vue.js' => 'Languages & Frameworks', 'Next.js' => 'Languages & Frameworks', 'Tailwind CSS' => 'Languages & Frameworks',
                    'Node.js' => 'Languages & Frameworks', 'Express.js' => 'Languages & Frameworks', 'Laravel' => 'Languages & Frameworks',
                    'API integration & design' => 'Architecture & Integration', 'REST APIs' => 'Architecture & Integration',
                    'Jira' => 'Architecture & Integration', 'Salesforce' => 'Architecture & Integration',
                    'OpenAI' => 'AI & Data', 'Anthropic' => 'AI & Data', 'PostgreSQL' => 'AI & Data', 'MySQL' => 'AI & Data', 'SQLite' => 'AI & Data', 'BigQuery' => 'AI & Data',
                    'AWS' => 'Cloud & Delivery', 'Google Cloud (general)' => 'Cloud & Delivery', 'Google Cloud Functions' => 'Cloud & Delivery', 'Microsoft Azure' => 'Cloud & Delivery',
                    'Docker' => 'Cloud & Delivery', 'Git' => 'Cloud & Delivery', 'GitLab' => 'Cloud & Delivery',
                ],
            ],
            /*
            | Job-aware layouts (chosen per job by ProfileJobAdapter and stored with the variant, so a saved resume always
            | renders as it was made). Like every layout they only regroup: a skill must still be in the shared map or in this
            | layout's `labels`, and must still be selected from approved evidence. `order` => 'selection' keeps a group's
            | skills in the job-scored order they were selected in. `labels` give a skill its name in this layout only (the
            | reference resume is untouched), and are the only way a canonical skill the reference map leaves off can be shown.
            */
            'fullstack' => [
                'order' => 'selection', 'max_per_group' => 6,
                'groups' => ['Languages & Frameworks', 'APIs & Integration', 'Data & Databases', 'Cloud & Workflow'],
                'labels' => ['Python' => 'Python', 'Webhooks' => 'Webhooks', 'Prisma' => 'Prisma', 'Inertia.js' => 'Inertia.js', 'CI/CD' => 'CI/CD'],
                'categories' => [
                    'TypeScript' => 'Languages & Frameworks', 'JavaScript' => 'Languages & Frameworks', 'PHP' => 'Languages & Frameworks', 'Python' => 'Languages & Frameworks',
                    'React' => 'Languages & Frameworks', 'Vue.js' => 'Languages & Frameworks', 'Next.js' => 'Languages & Frameworks', 'Inertia.js' => 'Languages & Frameworks', 'Tailwind CSS' => 'Languages & Frameworks',
                    'Node.js' => 'Languages & Frameworks', 'Express.js' => 'Languages & Frameworks', 'Laravel' => 'Languages & Frameworks',
                    'API integration & design' => 'APIs & Integration', 'REST APIs' => 'APIs & Integration', 'Webhooks' => 'APIs & Integration', 'Jira' => 'APIs & Integration', 'Salesforce' => 'APIs & Integration',
                    'OpenAI' => 'APIs & Integration', 'Anthropic' => 'APIs & Integration',
                    'SQL' => 'Data & Databases', 'PostgreSQL' => 'Data & Databases', 'MySQL' => 'Data & Databases', 'SQLite' => 'Data & Databases', 'BigQuery' => 'Data & Databases', 'Prisma' => 'Data & Databases',
                    'AWS' => 'Cloud & Workflow', 'Google Cloud (general)' => 'Cloud & Workflow', 'Google Cloud Functions' => 'Cloud & Workflow', 'Microsoft Azure' => 'Cloud & Workflow',
                    'Docker' => 'Cloud & Workflow', 'Git' => 'Cloud & Workflow', 'GitLab' => 'Cloud & Workflow', 'CI/CD' => 'Cloud & Workflow',
                ],
            ],
            'ai' => [
                'order' => 'selection', 'max_per_group' => 6,
                'groups' => ['AI & Retrieval', 'Languages & Frameworks', 'Integration & Architecture', 'Data & Cloud'],
                // The record states deterministic retrieval with an LLM limited to synthesis over cited passages; this names it.
                'labels' => ['Deterministic AI boundaries' => 'Grounded retrieval', 'Python' => 'Python', 'Webhooks' => 'Webhooks', 'Prisma' => 'Prisma', 'CI/CD' => 'CI/CD'],
                'categories' => [
                    'OpenAI' => 'AI & Retrieval', 'Anthropic' => 'AI & Retrieval', 'Deterministic AI boundaries' => 'AI & Retrieval',
                    'TypeScript' => 'Languages & Frameworks', 'JavaScript' => 'Languages & Frameworks', 'PHP' => 'Languages & Frameworks', 'Python' => 'Languages & Frameworks',
                    'React' => 'Languages & Frameworks', 'Vue.js' => 'Languages & Frameworks', 'Next.js' => 'Languages & Frameworks', 'Tailwind CSS' => 'Languages & Frameworks',
                    'Node.js' => 'Languages & Frameworks', 'Express.js' => 'Languages & Frameworks', 'Laravel' => 'Languages & Frameworks',
                    'API integration & design' => 'Integration & Architecture', 'REST APIs' => 'Integration & Architecture', 'Webhooks' => 'Integration & Architecture',
                    'Jira' => 'Integration & Architecture', 'Salesforce' => 'Integration & Architecture', 'GitLab' => 'Integration & Architecture',
                    'SQL' => 'Data & Cloud', 'PostgreSQL' => 'Data & Cloud', 'MySQL' => 'Data & Cloud', 'SQLite' => 'Data & Cloud', 'BigQuery' => 'Data & Cloud', 'Prisma' => 'Data & Cloud',
                    'AWS' => 'Data & Cloud', 'Google Cloud (general)' => 'Data & Cloud', 'Google Cloud Functions' => 'Data & Cloud', 'Microsoft Azure' => 'Data & Cloud',
                    'Docker' => 'Data & Cloud', 'Git' => 'Data & Cloud', 'CI/CD' => 'Data & Cloud',
                ],
            ],
        ],
    ],

    /*
    | Editorial targets for a resume written for a job. These are soft, reviewable preferences, not quotas: nothing
    | here pads a resume with weak evidence to reach a number, and nothing here changes how a PDF is rendered.
    |
    |   summary      Preferred length of the professional summary, in words. A strong shorter summary is not padded.
    |                `collapsed_below` is the length under which a summary that validation cut down is treated as
    |                collapsed: it is corrected once, and if it stays that short it ships flagged for review.
    |   bullets      The planning range for a two-page senior resume. `target` is what allocation plans for;
    |                `per_role_max` bounds any one role. Page-aware tuning is a separate concern (LayoutQuality).
    |   allocation   How bullets are shared between roles for a job. A role's share follows how strong and how relevant
    |                its evidence is, weighted by tenure. `continuity_floor` keeps a substantial earlier engineering role
    |                from shrinking to a token bullet, but only when it holds at least that many facts the job speaks to
    |                (`relevant_at`), `filler_relevance` stops a role being filled with evidence the job does not ask for, and
    |                `generic_per_role` bounds the general ownership and leadership claims a role may carry, and
    |                `story_preference` is the weight a complete story of what was built gets over a general claim, and
    |                `bare_outcome` discounts a quantified result that has no work attached.
    |   story        The most facts one bullet may combine (what was built, how, where it was used, what it achieved):
    |                `lead_max_facts` for the bullet that tells a project's story, `max_facts` for any other.
    */
    'composition' => [
        'summary' => ['min_words' => 45, 'max_words' => 65, 'collapsed_below' => 30],
        // A bullet that leaves out a fact it cites is rewritten on its own (one small call each) instead of regenerating the resume.
        'retention' => ['max_bullet_corrections' => 2],
        'bullets' => ['min' => 14, 'target' => 17, 'max' => 19, 'per_role_max' => 7],
        'allocation' => [
            'relevant_at' => 0.4, 'filler_relevance' => 0.3, 'continuity_floor' => 2, 'continuity_min_years' => 3,
            'tenure_full_years' => 6, 'recent_years' => 3, 'facts_per_bullet_unit' => 1.5, 'generic_per_role' => 1, 'story_preference' => 1.3, 'bare_outcome' => 0.85,
        ],
        // Small multipliers on a fact's relevance to a job, applied once after the keyword and capability scoring (see
        // JobRelevance::specificity). That scoring cannot tell a general claim from the work that proves it: a sentence about
        // "owning" or "leading" shares words with nearly every requirement. A factor below 1 is a general or incidental
        // statement, above 1 is demonstrated work. Each applied factor is recorded per fact, so a ranking can be explained.
        'relevance' => [
            'generic_leadership' => 0.7, 'generic_ownership' => 0.8, 'platform_tag' => 0.8, 'marketing_only' => 0.6,
            'concrete_build' => 1.12, 'integration' => 1.05, 'deployed' => 1.06, 'project_outcome' => 1.05,
            'min' => 0.5, 'max' => 1.2,
        ],
        'story' => ['max_facts' => 3, 'lead_max_facts' => 4],
        // A job gets the AI skills layout when it asks strongly for LLM work (capability weight, or the title says so).
        'skills' => ['ai_capability' => 0.6, 'isolated_tag' => 0.5],
        'duplicates' => ['restated_below_new_share' => 0.35, 'drop_confidence' => 'high'],
    ],

    /*
    | Qualifiers a resume bullet must not add to the work (see ClaimFidelityChecker). Each is an intensifier or a kind of system
    | that asserts something the evidence may not: calling a frontend "custom", a pipeline "robust", a platform "enterprise-grade".
    | One is flagged only when the cited evidence does not use it (or an equivalent, listed together); ordinary descriptive words
    | are never listed, and a qualifier the evidence itself uses is always allowed. Matching is on whole words, hyphen or space.
    | Each entry is a group of equivalent words: evidence using any of them supports the others.
    */
    'claim_qualifiers' => [
        ['custom', 'bespoke', 'proprietary', 'in-house'],
        ['robust', 'resilient'],
        ['scalable', 'highly scalable'],
        ['enterprise-grade', 'enterprise-level', 'enterprise scale'],
        ['high-performance', 'high performance'],
        ['high-availability', 'highly available'],
        ['mission-critical'],
        ['real-time', 'real time'],
        ['seamless', 'seamlessly'],
        ['cutting-edge', 'state-of-the-art', 'best-in-class', 'world-class'],
        ['sophisticated'],
        ['large-scale'],
        ['production-grade', 'production-ready'],
    ],

    /*
    | The one bounded corrective pass after the first PDF is rendered and measured (see App\Support\ResumeRevision\FinishResumeLayout).
    | It runs only when the last page is UNDERFILLED (LayoutQuality) and relevant approved Public evidence is left off the
    | resume, adds at most `max_additions` accomplishments in one narrow model call, and keeps the result only if it validates,
    | stays on the same number of pages, reads better, and repeats nothing. Typography and margins are never changed.
    |
    |   min_relevance   the job relevance (after specificity adjustments) a fact needs to be offered at all
    |   max_additions   accomplishments added, however much room LayoutQuality estimates
    |   max_per_role    of those, per role
    |   max_per_project bullets a project may have on the resume after the addition
    |   overlap_limit   word overlap with an existing bullet at which a fact counts as already on the resume
    |   min_fill_gain   the last page must be this much fuller than before for the result to be worth keeping
    |   max_fill        a last page fuller than this is too close to a third page to keep
    */
    'finishing' => [
        'enabled' => true,
        'min_relevance' => 0.4, 'max_additions' => 4, 'max_per_role' => 2, 'max_per_project' => 2, 'overlap_limit' => 0.6,
        'min_fill_gain' => 0.08, 'max_fill' => 0.95,
    ],

    /*
    | When a measured resume layout counts as a presentation defect (see App\Support\ResumeDocument\LayoutQuality). The
    | fill is the share of the last page's usable height that holds content. A page that is mostly empty is not a
    | failure of the PDF, it is a signal about the content: either relevant approved evidence was left out, or there is
    | no more to say and the honest length is shorter. `bullet_points` is the height a typical bullet occupies, used only
    | to say roughly how many bullets a gap is worth; nothing here changes how a resume is rendered or selected.
    */
    'layout_quality' => [
        'underfilled_below' => 0.40,
        'light_below' => 0.55,
        'bullet_points' => 40.0,
        // About this many of the weakest bullets or fewer, and a two-page resume would be a (legitimate) one-pager.
        'one_page_within_bullets' => 3,
        // A revision that takes the last page down by this much, to below `light_below`, is called out as the cause.
        'revision_drop' => 0.15,
        // Usable height of a continuation page, in points (matches MeasureResumePdf).
        'later_page_points' => 720.0,
    ],

];
