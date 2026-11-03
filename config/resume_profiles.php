<?php

/*
|--------------------------------------------------------------------------
| Market-informed Resume Profiles
|--------------------------------------------------------------------------
|
| Policy as data. A profile names a role family, how to sample the market for
| it, and how its discovery interview is paced. The capability taxonomy below
| is what market postings are read against: deterministic patterns, no model
| call. A capability is NOT a skill the user has; it is something the market
| asks for, later compared with the user's approved evidence.
|
*/

return [

    /*
     | Bump when the taxonomy or the required/preferred rules change. Cached
     | extractions are keyed by (content hash, this version), so a bump
     | re-reads every sampled posting exactly once and nothing else.
     */
    'extraction_version' => 'market-extract-v1',

    'profiles' => [
        'full-stack-product' => [
            'name' => 'Full-Stack / Product Engineering',
            'role_family' => 'full_stack_product',
            'purpose' => 'The professional narrative for senior full-stack and product engineering roles: what the market asks for, which of it your career already proves, and what is still undocumented.',

            // Provider-neutral search description (a SearchProfile). Sampling
            // reuses FilterDiscoveredCandidates with criteria derived from it.
            'search' => [
                'label' => 'Full-Stack / Product Engineering',
                'include_terms' => [
                    'full stack', 'full-stack', 'fullstack', 'product engineer',
                    'software engineer', 'application engineer', 'web engineer',
                    'web developer', 'software developer',
                ],
                'exclude_terms' => ['intern', 'internship', 'entry level', 'entry-level', 'junior', 'jr.', 'new grad', 'new graduate'],
                'excluded_title_keywords' => ['site reliability', 'sre', 'devops', 'machine learning', 'data scientist', 'security engineer', 'embedded', 'firmware', 'ios', 'android', 'mobile', 'infrastructure', 'platform engineering', 'data platform', 'data engineer', 'blockchain'],
                'preferred_seniority' => 'Senior',
                'remote_preference' => 'remote_us',
                'allowed_locations' => ['United States', 'US', 'USA', 'Remote'],
                'employment_type' => null,
            ],

            /*
             | A generic "software engineer" title counts only when the posting
             | itself asks for both halves of the stack. An explicit full-stack
             | or product-engineer title always counts.
             */
            'relevance' => [
                'title_terms' => ['full stack', 'full-stack', 'fullstack', 'product engineer'],
                'frontend_terms' => ['react', 'vue', 'angular', 'svelte', 'typescript', 'javascript', 'frontend', 'front-end', 'front end'],
                'backend_terms' => ['api', 'apis', 'backend', 'back-end', 'back end', 'database', 'sql', 'node', 'python', 'php', 'ruby', 'java', 'laravel', 'django', 'rails', 'golang'],
            ],

            'sample' => [
                'pilot_target' => 20,
                'target' => 75,
                'min_description_chars' => 600,
                'max_age_days' => 75,
                // Diversity caps, as shares of the target (at least 2 each).
                'max_per_company' => 2,
                'max_provider_share' => 0.6,
                'max_stack_share' => 0.4,
                'near_duplicate_similarity' => 0.82,
            ],

            'interview' => [
                'max_topics' => 16,
                'group_size' => 4,
                'min_score' => 0.04,
                'min_postings' => 2,
                'min_share' => 0.08,
                'skip_cooldown_days' => 30,
            ],
        ],
    ],

    /*
     | Providers eligible for market sampling. Each is asked once per
     | collection (their feeds return one page), and a provider fetched
     | within `refetch_hours` is not asked again unless forced. Adzuna is not
     | listed: it only ever returns a description preview, and a preview
     | would be excluded as incomplete anyway.
     */
    'providers' => ['himalayas', 'jobicy'],
    'refetch_hours' => 12,

    /*
     | Primary application stack of a posting, for diversity capping only.
     | First stack with the most mentions wins.
     */
    'stacks' => [
        'php_laravel' => '/\b(PHP|Laravel|Symfony|WordPress)\b/',
        'ruby_rails' => '/\b(Ruby on Rails|Rails|Ruby)\b/',
        'python' => '/\b(Python|Django|Flask|FastAPI)\b/',
        'jvm' => '/\b(Java|Kotlin|Spring Boot|Scala)\b/',
        'dotnet' => '/(?:\.NET|\bC#)/',
        'node_ts' => '/\b(Node(?:\.js|js)?|TypeScript|Next\.js|NestJS|Express)\b/',
        'go_rust' => '/\b(Golang|Rust)\b/',
    ],

    'seniority' => [
        'staff_plus' => '/\b(staff|principal|distinguished|architect)\b/i',
        'senior' => '/\b(senior|sr\.?|lead)\b/i',
        'junior' => '/\b(junior|jr\.?|entry[- ]level|associate|intern)\b/i',
    ],

    /*
     | How a mention is read. A line is classified by the nearest cue on the
     | line, then by the section heading above it. Order: preferred cues win
     | over required cues on the same line ("React; GraphQL a plus").
     */
    'levels' => [
        'preferred_line' => '/\b(nice to have|nice-to-have|preferred|bonus|a plus|is a plus|are a plus|ideally|desirable|would be (?:great|nice)|extra credit|familiarity with)\b/i',
        'required_line' => '/\b(required|requirements?|must have|must|you have|you(?:\'| a)?re|proficien\w+|strong|solid|\d+\+?\s*years?|experience (?:with|in|building|developing)|expertise)\b/i',
        'section_preferred' => '/^(?:#+\s*)?(?:\W*)(nice to haves?|nice-to-haves?|bonus(?: points)?|preferred(?: qualifications| skills)?|good to have|a plus|extra credit|what would impress us)\b/i',
        'section_required' => '/^(?:#+\s*)?(?:\W*)(requirements?|qualifications?|what you(?:\'| wi)?ll need|what we(?:\'| a)?re looking for|who you are|you have|must haves?|minimum qualifications|about you|skills|your background)\b/i',
        'section_duty' => '/^(?:#+\s*)?(?:\W*)(responsibilities|what you(?:\'| wi)?ll do|the role|in this role|your impact|what you will do|day[- ]to[- ]day|you will|key responsibilities|the job)\b/i',
        'section_ignored' => '/^(?:#+\s*)?(?:\W*)(benefits|perks|compensation|salary|about us|about the company|our (?:story|mission|values|culture)|equal opportunity|how to apply|why join|what we offer|privacy)\b/i',
    ],

    /*
     | The capability taxonomy. Keys of the six clarification topics
     | (webhooks, recurring_billing, ruby_on_rails, financial_aggregation,
     | payment_gateways, conversion_funnels) are reused on purpose, so limits,
     | preferences, skips and approved evidence from job-specific
     | clarification carry over.
     |
     |   category  technology | practice | architecture | responsibility | domain | collaboration
     |   parent    evidence for THIS capability also directly supports the parent
     |             (a webhook handler is webhook integration)
     |   related   evidence for these only counts as transferable
     |   ask       the specific thing to ask; default is a generic prompt
     |
     | Close neighbours stay separate on purpose: general third-party API
     | integration, webhook integration, webhook handler implementation, and
     | retry/idempotency handling are four capabilities.
     */
    'capabilities' => [
        // ---- Technologies and frameworks ----
        'react' => ['label' => 'React', 'category' => 'technology', 'patterns' => ['/\bReact(?:\.js|JS)?\b/'], 'related' => ['vue_angular', 'typescript']],
        'vue_angular' => ['label' => 'Vue, Angular or Svelte', 'category' => 'technology', 'patterns' => ['/\b(?:Vue(?:\.js)?|Angular|Svelte)\b/'], 'related' => ['react']],
        'typescript' => ['label' => 'TypeScript', 'category' => 'technology', 'patterns' => ['/\bTypeScript\b/i'], 'related' => ['javascript_core']],
        'javascript_core' => ['label' => 'JavaScript', 'category' => 'technology', 'patterns' => ['/\bJavaScript\b/i', '/\bES6\b/'], 'related' => ['typescript', 'react']],
        'nodejs' => ['label' => 'Node.js', 'category' => 'technology', 'patterns' => ['/\bNode(?:\.js|js)?\b/'], 'related' => ['javascript_core', 'typescript']],
        'php_laravel' => ['label' => 'PHP / Laravel', 'category' => 'technology', 'patterns' => ['/\b(?:PHP|Laravel|Symfony)\b/'], 'related' => []],
        'python_backend' => ['label' => 'Python backend', 'category' => 'technology', 'patterns' => ['/\b(?:Python|Django|Flask|FastAPI)\b/'], 'related' => ['php_laravel', 'nodejs']],
        'ruby_on_rails' => ['label' => 'Ruby on Rails', 'category' => 'technology', 'patterns' => ['/\b(?:Ruby on Rails|Rails|Ruby)\b/'], 'related' => ['php_laravel', 'python_backend']],
        'jvm_backend' => ['label' => 'Java / Kotlin / Scala', 'category' => 'technology', 'patterns' => ['/\b(?:Java|Kotlin|Spring Boot|Scala)\b/'], 'related' => []],
        'go_rust' => ['label' => 'Go or Rust', 'category' => 'technology', 'patterns' => ['/\b(?:Golang|Rust)\b/', '/\bin Go\b/', '/\bGo\s*(?:,|\/)\s*(?:Python|Java|Node|Rust|TypeScript)\b/', '/\b(?:Python|Java|Node|Rust|TypeScript)\s*(?:,|\/)\s*Go\b/'], 'related' => []],
        'dotnet' => ['label' => '.NET / C#', 'category' => 'technology', 'patterns' => ['/(?:\.NET|\bC#)/'], 'related' => []],
        'sql_databases' => ['label' => 'SQL and relational databases', 'category' => 'technology', 'patterns' => ['/\b(?:SQL|PostgreSQL|Postgres|MySQL|relational databases?)\b/i'], 'related' => []],
        'nosql_cache' => ['label' => 'NoSQL and caching', 'category' => 'technology', 'patterns' => ['/\b(?:MongoDB|DynamoDB|Redis|Cassandra|Elasticsearch|NoSQL|Memcached)\b/i'], 'related' => ['sql_databases']],
        'graphql' => ['label' => 'GraphQL', 'category' => 'technology', 'patterns' => ['/\bGraphQL\b/i'], 'related' => ['rest_api_design']],
        'cloud_platforms' => ['label' => 'Cloud platforms (AWS, GCP, Azure)', 'category' => 'technology', 'patterns' => ['/\b(?:AWS|Amazon Web Services|GCP|Google Cloud|Azure)\b/'], 'related' => ['containers']],
        'containers' => ['label' => 'Docker and Kubernetes', 'category' => 'technology', 'patterns' => ['/\b(?:Docker|Kubernetes|K8s)\b/i', '/\bcontaineri[sz]ation\b/i'], 'related' => ['cloud_platforms']],
        'ai_llm' => ['label' => 'AI and LLM features', 'category' => 'technology', 'patterns' => ['/\b(?:LLMs?|large language models?|OpenAI|generative AI|GenAI|prompt engineering|RAG)\b/', '/\bAI[- ]powered\b/i'], 'related' => []],

        // ---- Engineering practices ----
        'ci_cd' => ['label' => 'CI/CD and release automation', 'category' => 'practice', 'patterns' => ['/\bCI\s*\/\s*CD\b/i', '/\bcontinuous (?:integration|delivery|deployment)\b/i', '/\b(?:GitHub Actions|Jenkins|CircleCI)\b/'], 'related' => ['containers']],
        'infrastructure_as_code' => ['label' => 'Infrastructure as code', 'category' => 'practice', 'patterns' => ['/\b(?:Terraform|CloudFormation|Pulumi)\b/', '/\binfrastructure as code\b/i'], 'related' => ['ci_cd', 'cloud_platforms']],
        'automated_testing' => ['label' => 'Automated testing', 'category' => 'practice', 'patterns' => ['/\b(?:unit|integration|end[- ]to[- ]end|automated) tests?\b/i', '/\b(?:test[- ]driven|TDD|automated testing)\b/i', '/\b(?:Jest|Cypress|Playwright|Pytest|PHPUnit|RSpec)\b/'], 'related' => []],
        'observability' => ['label' => 'Monitoring and observability', 'category' => 'practice', 'patterns' => ['/\b(?:observability|monitoring|Datadog|Sentry|New Relic|Prometheus|Grafana)\b/i'], 'related' => ['incident_response']],
        'code_review' => ['label' => 'Code review and quality', 'category' => 'practice', 'patterns' => ['/\bcode reviews?\b/i', '/\b(?:code quality|engineering standards|best practices)\b/i'], 'related' => []],
        'incident_response' => ['label' => 'Production support and incident response', 'category' => 'practice', 'patterns' => ['/\bon-?call\b/i', '/\bincident (?:response|management)\b/i', '/\b(?:post-?mortems?|production (?:support|issues)|SLAs?|SLOs?)\b/'], 'related' => ['observability']],
        'experimentation' => ['label' => 'Experimentation and feature flags', 'category' => 'practice', 'patterns' => ['/\bA\/B test\w*\b/i', '/\b(?:experimentation|feature flags?|feature toggles?)\b/i'], 'related' => ['conversion_funnels']],

        // ---- Architecture and integration patterns ----
        'rest_api_design' => ['label' => 'REST API design and development', 'category' => 'architecture', 'patterns' => ['/\bRESTful\b/i', '/\bREST\s+APIs?\b/', '/\bAPI design\b/i', '/\b(?:design|build|develop|maintain)(?:ing)?\s+(?:\w+\s+){0,2}APIs?\b/i'], 'related' => ['api_integration', 'graphql']],
        'api_integration' => ['label' => 'Third-party API integration', 'category' => 'architecture', 'patterns' => ['/\bthird[- ]party (?:APIs?|integrations?|services)\b/i', '/\bintegrat(?:e|ing|ion with)\s+(?:\w+\s+){0,2}(?:APIs?|third[- ]party)\b/i', '/\b(?:API|partner|vendor) integrations?\b/i', '/\bexternal (?:APIs?|services)\b/i'], 'related' => ['rest_api_design'], 'ask' => 'integrating your product or a client with someone else\'s API (payments, CRM, messaging, data providers, partner systems)'],
        'webhooks' => ['label' => 'Webhook integration', 'category' => 'architecture', 'patterns' => ['/\bweb-?hooks?\b/i'], 'related' => ['api_integration', 'event_driven'], 'ask' => 'working with webhooks: configuring them, consuming them, supporting merchants or customers who use them'],
        'webhook_handlers' => ['label' => 'Webhook handler implementation', 'category' => 'architecture', 'parent' => 'webhooks', 'patterns' => ['/\b(?:build|implement|develop|design|handle|handling|process|processing|consum)\w*\s+(?:\w+\s+){0,3}web-?hooks?\b/i', '/\bweb-?hook (?:handlers?|endpoints?|receivers?|consumers?|processing)\b/i'], 'related' => ['webhooks', 'api_integration', 'event_driven'], 'ask' => 'building the receiving side of webhooks: endpoints, signature checks, processing events'],
        'retry_idempotency' => ['label' => 'Retry, idempotency and failure handling', 'category' => 'architecture', 'patterns' => ['/\bidempoten\w+\b/i', '/\bretry (?:logic|strateg\w+|mechanisms?|handling|schedules?|policies)\b/i', '/\b(?:exponential backoff|dead[- ]letter|at-least-once|exactly-once)\b/i'], 'related' => ['webhooks', 'event_driven'], 'ask' => 'making integrations safe to repeat or fail: retry schedules, idempotency keys, deduplication, recovering from failed events'],
        'event_driven' => ['label' => 'Queues, events and background jobs', 'category' => 'architecture', 'patterns' => ['/\b(?:Kafka|RabbitMQ|SQS|SNS|Sidekiq|Celery)\b/', '/\b(?:message (?:queues?|brokers?)|event[- ]driven|pub\/?sub|background (?:jobs?|processing|workers?)|job queues?|asynchronous processing)\b/i'], 'related' => ['webhooks']],
        'auth_security' => ['label' => 'Authentication and application security', 'category' => 'architecture', 'patterns' => ['/\b(?:OAuth2?|SSO|SAML|OIDC|JWT|OWASP)\b/', '/\b(?:authentication|authorization|application security|secure coding)\b/i'], 'related' => []],
        'system_design' => ['label' => 'System and architecture design', 'category' => 'architecture', 'patterns' => ['/\b(?:system design|software architecture|architectural|technical design|design documents?|technical specs?)\b/i'], 'related' => ['distributed_scale']],
        'distributed_scale' => ['label' => 'Scalability and distributed systems', 'category' => 'architecture', 'patterns' => ['/\b(?:microservices?|distributed systems?|high[- ]traffic|high availability|scalab\w+)\b/i', '/\bperformance (?:optimi[sz]ation|tuning)\b/i'], 'related' => ['system_design']],
        'data_modeling' => ['label' => 'Data modeling and migrations', 'category' => 'architecture', 'patterns' => ['/\b(?:data model(?:ing|s)?|schema design|database design|database migrations?)\b/i'], 'related' => ['sql_databases']],
        'data_pipelines' => ['label' => 'Data pipelines and warehousing', 'category' => 'architecture', 'patterns' => ['/\b(?:ETL|ELT|data pipelines?|data warehouse|Snowflake|BigQuery|dbt)\b/'], 'related' => ['sql_databases']],
        'frontend_architecture' => ['label' => 'Front-end architecture and design systems', 'category' => 'architecture', 'patterns' => ['/\b(?:design systems?|component librar(?:y|ies)|state management|Redux|accessibility|WCAG)\b/i', '/\bresponsive (?:design|web)\b/i'], 'related' => ['react']],
        'payment_gateways' => ['label' => 'Payment gateways and processors', 'category' => 'architecture', 'patterns' => ['/\bpayment (?:gateways?|processors?|platforms?|integrations?|processing)\b/i', '/\b(?:Stripe|Braintree|Adyen|PayPal|Authorize\.net)\b/'], 'related' => ['api_integration', 'webhooks'], 'ask' => 'working with payment gateways or processors, from integration through supporting live merchants'],
        'recurring_billing' => ['label' => 'Recurring billing and subscriptions', 'category' => 'architecture', 'patterns' => ['/\b(?:recurring|subscription)[\s-]+(?:billing|payments?|charges?|lifecycle|management)\b/i', '/\bbilling (?:systems?|setup|platforms?|workflows?)\b/i'], 'related' => ['payment_gateways'], 'ask' => 'recurring or subscription billing: setup, renewal and failure flows, migrations between systems'],
        'financial_aggregation' => ['label' => 'Financial-account aggregation', 'category' => 'architecture', 'patterns' => ['/\b(?:Plaid|Finicity|Yodlee)\b/', '/\baccount aggregation\b/i'], 'related' => ['api_integration']],

        // ---- Responsibilities and ownership expectations ----
        'end_to_end_ownership' => ['label' => 'End-to-end feature ownership', 'category' => 'responsibility', 'patterns' => ['/\bend[- ]to[- ]end\b/i', '/\bown(?:ership)?\s+(?:of\s+)?(?:\w+\s+){0,3}(?:features?|products?|projects?|systems?|services?)\b/i', '/\b(?:full (?:software )?(?:development )?lifecycle|from concept to|0[- ]to[- ]1|zero[- ]to[- ]one)\b/i'], 'related' => [], 'ask' => 'taking something from idea to production yourself: what you owned, decided and shipped'],
        'mentoring_leadership' => ['label' => 'Mentoring and technical leadership', 'category' => 'responsibility', 'patterns' => ['/\bmentor\w*\b/i', '/\b(?:tech(?:nical)? lead\w*|lead (?:a )?(?:team|engineers?|projects?)|set (?:the )?technical direction)\b/i'], 'related' => ['code_review']],
        'customer_facing' => ['label' => 'Customer-facing technical work', 'category' => 'responsibility', 'patterns' => ['/\b(?:customer|client)[- ]facing\b/i', '/\bwork(?:ing)? directly with (?:customers|clients|merchants)\b/i', '/\b(?:technical support|solutions? engineer\w*|stakeholder management)\b/i'], 'related' => []],

        // ---- Collaboration and delivery ----
        'cross_functional' => ['label' => 'Cross-functional collaboration', 'category' => 'collaboration', 'patterns' => ['/\bcross[- ]functional\b/i', '/\b(?:product managers?|designers?)\b/i', '/\bwork(?:ing)? closely with (?:product|design)\b/i'], 'related' => ['customer_facing']],
        'agile_delivery' => ['label' => 'Agile delivery', 'category' => 'collaboration', 'patterns' => ['/\b(?:agile|scrum|kanban|sprint planning)\b/i', '/\bship(?:ping)? (?:fast|quickly|frequently|iteratively)\b/i', '/\brapid iteration\b/i'], 'related' => []],
        'product_sense' => ['label' => 'Product thinking', 'category' => 'collaboration', 'patterns' => ['/\bproduct (?:sense|thinking|mindset|intuition|minded|-minded)\b/i', '/\b(?:user[- ]centric|customer[- ]obsessed|user experience|\bUX\b)\b/i'], 'related' => ['cross_functional', 'conversion_funnels']],
        'conversion_funnels' => ['label' => 'Conversion funnels, checkout and onboarding flows', 'category' => 'collaboration', 'patterns' => ['/\b(?:conversion|funnels?|checkout|onboarding|registration)\b/i'], 'related' => ['experimentation', 'product_sense']],

        // ---- Domain experience ----
        'domain_saas_b2b' => ['label' => 'B2B SaaS', 'category' => 'domain', 'patterns' => ['/\b(?:B2B|SaaS|multi-?tenant|enterprise software)\b/'], 'related' => []],
        'domain_fintech' => ['label' => 'Fintech and payments', 'category' => 'domain', 'patterns' => ['/\b(?:fintech|financial services|banking|lending|insurance)\b/i', '/\bpayments? (?:industry|company|space|platform)\b/i'], 'related' => ['payment_gateways', 'recurring_billing']],
        'domain_ecommerce' => ['label' => 'E-commerce and marketplaces', 'category' => 'domain', 'patterns' => ['/\b(?:e-?commerce|marketplace|retail|Shopify|storefront)\b/i'], 'related' => ['conversion_funnels']],
        'domain_healthcare' => ['label' => 'Healthcare technology', 'category' => 'domain', 'patterns' => ['/\b(?:healthcare|health ?tech|HIPAA|clinical)\b/i'], 'related' => []],
        'domain_devtools' => ['label' => 'Developer tools and platforms', 'category' => 'domain', 'patterns' => ['/\b(?:developer tools?|developer experience|SDKs?|developer platform|API platform)\b/i'], 'related' => ['api_integration']],
    ],

    /*
     | Interview ranking. score = importance x gap x answerability x usefulness.
     */
    'ranking' => [
        'level_weight' => ['required' => 1.0, 'duty' => 0.9, 'mentioned' => 0.6, 'preferred' => 0.5],
        'gap' => ['no_evidence' => 1.0, 'underdocumented' => 0.85, 'transferable' => 0.55],
        'answerability' => ['underdocumented' => 1.0, 'transferable' => 0.9, 'no_evidence_with_adjacent' => 0.7, 'no_evidence' => 0.4],
        'usefulness' => ['responsibility' => 1.0, 'architecture' => 1.0, 'practice' => 0.9, 'domain' => 0.8, 'collaboration' => 0.8, 'technology' => 0.75],
    ],
];
