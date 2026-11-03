<?php

/*
|--------------------------------------------------------------------------
| Targeted clarification policy
|--------------------------------------------------------------------------
|
| Everything that decides WHICH questions are worth asking, and how an
| answer may be worded, lives here as data so it can be reviewed and tuned
| without touching code. Ranking is deterministic and explained by a
| per-question score breakdown; there is no model call anywhere in it.
|
*/

return [

    /*
     | A question is shown only when its score clears `min_score`. Standard
     | mode asks at most `max_questions`; deep mode relaxes both limits.
     */
    'standard' => ['max_questions' => 3, 'min_score' => 0.30],
    'deep' => ['max_questions' => 8, 'min_score' => 0.02],

    /*
     | How long an explicitly skipped question stays quiet for the same
     | topic on other jobs. "Generate Now" never counts as a skip.
     */
    'skip_cooldown_days' => 30,

    /*
     | score = strength x emphasis x leverage x gap x answerability.
     |
     |  strength      how binding the requirement is
     |  emphasis      how strongly the posting stresses it
     |  leverage      how likely an answer is to become a bullet, skill or
     |                summary line (duties and required qualifications can;
     |                a technology-stack mention mostly cannot)
     |  gap           how much the current evidence leaves unsaid
     |  answerability whether adjacent career evidence suggests the user
     |                knows more than is written down. A requirement with
     |                nothing adjacent gets a low floor, so a long list of
     |                unrelated "preferred" tools does not become a quiz.
     |
     | Keyword frequency is deliberately not an input.
     */
    'weights' => [
        'strength' => ['required' => 1.0, 'preferred' => 0.55, 'responsibility_default' => 0.85, 'default' => 0.6],
        'emphasis' => ['high' => 1.0, 'normal' => 0.65, 'low' => 0.3],
        'leverage' => [
            'responsibility' => 1.0,
            'required_qualification' => 1.0,
            'preferred_qualification' => 0.8,
            'capability' => 0.8,
            'technology' => 0.65,
            'domain_knowledge' => 0.55,
        ],
        'gap' => ['partial' => 0.8, 'no_evidence' => 1.0],
        'answerability' => [
            'no_evidence' => 0.15,
            'partial_floor' => 0.55,
            'partial_span' => 0.45,
            'relationship' => ['direct' => 1.0, 'transferable' => 0.6, 'contextual' => 0.35],
            'adjacency_cap' => 3.0,
        ],
    ],

    /*
     | A finding is eligible to drive a question only in these categories
     | (culture, compensation, application logistics and similar can never
     | be improved by career evidence).
     */
    'eligible_categories' => [
        'responsibility', 'required_qualification', 'preferred_qualification', 'capability', 'technology', 'domain_knowledge',
    ],

    /*
     | Topic catalog. A finding is assigned to the FIRST topic whose pattern
     | matches its label or statement, so one finding yields one question
     | and the order below resolves overlaps (webhooks before payment
     | gateways, for example). A finding that matches no topic falls back to
     | a topic derived from its own label, which still de-duplicates within
     | a job but not across differently-worded jobs.
     |
     | `terms` are the exact words a CareerLimitation on this topic forbids
     | the resume from claiming; they are optional and only used when the
     | user records a limitation.
     */
    'topics' => [
        'webhooks' => [
            'label' => 'Webhooks and event-driven integrations',
            'patterns' => ['/\bweb-?hooks?\b/i'],
            'terms' => ['webhook', 'webhooks'],
        ],
        'recurring_billing' => [
            'label' => 'Recurring billing and subscriptions',
            'patterns' => [
                '/\b(recurring|subscription)[\s-]+(billing|payments?|charges?|lifecycle|management)\b/i',
                '/\bpayments?\s*\/\s*billing\b/i',
                '/\bbilling\s+(systems?|setup|platforms?|migration|workflows?)\b/i',
            ],
            'terms' => ['recurring billing', 'recurring payments', 'subscription billing'],
        ],
        'ruby_on_rails' => [
            'label' => 'Ruby on Rails',
            'patterns' => ['/\b(ruby on rails|rails)\b/i', '/\bruby\b/i'],
            'terms' => ['Ruby on Rails', 'Rails', 'Ruby'],
            'named' => true,
        ],
        'financial_aggregation' => [
            'label' => 'Financial-account aggregation',
            'patterns' => ['/\b(plaid|finicity|yodlee)\b/i', '/\baccount aggregation\b/i'],
            'terms' => ['Plaid', 'Finicity', 'Yodlee'],
            'named' => true,
        ],
        'payment_gateways' => [
            'label' => 'Payment gateways and processors',
            'patterns' => [
                '/\bpayment (gateways?|processors?|platforms?|integrations?)\b/i',
                '/\b(stripe|braintree|adyen|paypal|authorize\.net)\b/i',
            ],
            'terms' => [],
        ],
        'conversion_funnels' => [
            'label' => 'Conversion funnels, checkout and onboarding flows',
            'patterns' => ['/\b(conversion|funnel|checkout|onboarding|registration)\b/i'],
            'terms' => [],
        ],
    ],

    /*
     | Scope guard. A statement may use a verb in `ownership_claims` only
     | when the user selected the scope that supports it. This is what stops
     | "configured webhooks" from becoming "built webhook handlers".
     */
    'scope_guard' => [
        'implementation_verbs' => [
            'built', 'build', 'implemented', 'developed', 'engineered', 'architected', 'designed',
            'created', 'wrote', 'coded', 'authored',
        ],
        'ownership_verbs' => ['owned', 'led', 'ran', 'managed', 'drove', 'spearheaded'],
        'implementation_phrases' => ['webhook handler', 'webhook handlers', 'handler for webhooks', 'gateway service'],
    ],

    /*
     | Claim scope (generation time; see ClaimScopeChecker). A topic marked
     | `named` is a specific technology: naming it without cited evidence blocks.
     |
     | Claim scope (generation time). After Wording, each sentence is read for
     | "<action> ... <topic>" claims, e.g. "troubleshooting ... webhook". The
     | nearest classified action before a topic mention governs it. `verbs`
     | are verb forms only (nouns such as "integration" are not claims).
     |
     | A class is supported by a fact when the fact's scopes include it, or a
     | broader scope implies it (`implies`). Configuration, support and
     | advising never imply implementation or ownership.
     */
    'claim_scope' => [
        'verbs' => [
            'implementation' => ['built', 'build', 'building', 'implement(?:ed|ing|s)?', 'develop(?:ed|ing|s)?', 'engineer(?:ed|ing)', 'architect(?:ed|ing)', 'design(?:ed|ing)', 'created', 'creating', 'wrote', 'writing', 'coded', 'coding', 'authored'],
            'ownership' => ['owned', 'owning', 'led', 'leading', 'ran', 'running', 'managed', 'managing', 'drove', 'driving', 'spearhead(?:ed|ing)', 'headed', 'oversaw', 'overseeing'],
            'integration' => ['integrat(?:ed|ing|es?)'],
            'configuration' => ['configur(?:ed|ing|es?)', 'set up', 'setting up', 'provision(?:ed|ing)'],
            'troubleshooting' => ['troubleshoot(?:ing|s)?', 'troubleshot', 'debugg(?:ed|ing)', 'debug', 'diagnos(?:ed|ing|es?)', 'resolv(?:ed|ing|es?)', 'investigat(?:ed|ing)'],
            'advising' => ['advis(?:ed|ing|es?)', 'consult(?:ed|ing)', 'guid(?:ed|ing|es?)', 'recommend(?:ed|ing)', 'coach(?:ed|ing)', 'mentor(?:ed|ing)'],
            'support' => ['support(?:ed|ing|s)?', 'assist(?:ed|ing|s)?', 'help(?:ed|ing|s)?', 'enabl(?:ed|ing|es?)'],
        ],
        'scope_classes' => [
            'implemented' => 'implementation',
            'integrated' => 'integration',
            'configured' => 'configuration',
            'troubleshot' => 'troubleshooting',
            'advised' => 'advising',
            'supported' => 'support',
        ],
        'implies' => [
            'implementation' => ['integration', 'configuration'],
            'integration' => ['configuration'],
        ],
        'strong' => ['implementation', 'ownership'],
        'labels' => [
            'implementation' => 'building or implementing',
            'ownership' => 'owning or leading',
            'integration' => 'integrating',
            'configuration' => 'configuring',
            'troubleshooting' => 'troubleshooting',
            'advising' => 'advising on',
            'support' => 'supporting',
        ],
    ],

    /*
     | Attribution suggestions weight a related fact by its match
     | relationship when picking the most plausible role.
     */
    'attribution_weights' => ['direct' => 1.0, 'transferable' => 0.6, 'contextual' => 0.35],
];
