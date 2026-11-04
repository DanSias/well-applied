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
    'extraction_version' => 'market-extract-v2',

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
                'excluded_title_keywords' => ['site reliability', 'sre', 'devops', 'machine learning', 'data scientist', 'security engineer', 'embedded', 'firmware', 'ios', 'android', 'mobile', 'infrastructure', 'platform engineering', 'data platform', 'data engineer', 'blockchain', 'backend', 'back-end', 'back end', 'data'],
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
                // A documented capability is revisited only when the market asks for it a lot and the
                // record is thin on projects, ownership or outcomes (see CapabilityCoverage::depth).
                'deepening' => ['min_importance' => 0.35, 'categories' => ['technology', 'architecture', 'responsibility', 'practice']],
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
        'cloud_platforms' => ['label' => 'Cloud platforms (AWS, GCP, Azure)', 'category' => 'technology', 'patterns' => ['/\b(?:AWS|Amazon Web Services|GCP|Google Cloud|Azure)\b/'], 'related' => []],
        'docker' => ['label' => 'Docker and containers', 'category' => 'technology', 'patterns' => ['/\bDocker\b/i'], 'weak_patterns' => ['/\bcontaineri[sz](?:e|es|ed|ing|ation)\b/i'], 'related' => []],
        'kubernetes' => ['label' => 'Kubernetes', 'category' => 'technology', 'patterns' => ['/\b(?:Kubernetes|K8s|Helm|EKS|GKE)\b/'], 'related' => []],
        'ai_llm' => ['label' => 'AI and LLM features', 'category' => 'technology', 'patterns' => ['/\b(?:LLMs?|large language models?|OpenAI|generative AI|GenAI|prompt engineering|RAG)\b/'], 'weak_patterns' => ['/\bAI[- ]powered\b/i'], 'related' => []],

        // ---- Engineering practices ----
        'ci_cd' => ['label' => 'CI/CD and release automation', 'category' => 'practice', 'patterns' => ['/\bCI\s*\/\s*CD\b/i', '/\bcontinuous (?:integration|delivery|deployment)\b/i', '/\b(?:GitHub Actions|Jenkins|CircleCI)\b/'], 'related' => []],
        'infrastructure_as_code' => ['label' => 'Infrastructure as code', 'category' => 'practice', 'patterns' => ['/\b(?:Terraform|CloudFormation|Pulumi)\b/', '/\binfrastructure as code\b/i'], 'related' => ['ci_cd', 'cloud_platforms']],
        'automated_testing' => ['label' => 'Automated testing', 'category' => 'practice', 'patterns' => ['/\b(?:unit|integration|end[- ]to[- ]end|automated) tests?\b/i', '/\b(?:test[- ]driven|TDD|automated testing)\b/i', '/\b(?:Jest|Cypress|Playwright|Pytest|PHPUnit|RSpec)\b/'], 'related' => []],
        'observability' => ['label' => 'Monitoring and observability', 'category' => 'practice', 'patterns' => ['/\b(?:observability|Datadog|Sentry|New Relic|Prometheus|Grafana)\b/i'], 'weak_patterns' => ['/\bmonitoring\b/i'], 'related' => ['incident_response']],
        'code_review' => ['label' => 'Code review and quality', 'category' => 'practice', 'patterns' => ['/\bcode reviews?\b/i', '/\b(?:code quality|engineering standards)\b/i'], 'weak_patterns' => ['/\bbest practices\b/i'], 'related' => []],
        'incident_response' => ['label' => 'Production support and incident response', 'category' => 'practice', 'patterns' => ['/\bon-?call\b/i', '/\bincident (?:response|management)\b/i', '/\b(?:post-?mortems?|production (?:support|issues)|SLAs?|SLOs?)\b/'], 'related' => ['observability']],
        'experimentation' => ['label' => 'Experimentation and feature flags', 'category' => 'practice', 'patterns' => ['/\bA\/B test\w*\b/i', '/\b(?:experimentation|feature flags?|feature toggles?)\b/i'], 'related' => ['conversion_funnels']],

        // ---- Architecture and integration patterns ----
        'rest_api_design' => ['label' => 'REST API design and development', 'category' => 'architecture', 'patterns' => ['/\bRESTful\b/i', '/\bREST\s+APIs?\b/', '/\bAPI design\b/i', '/\b(?:design|build|built|develop|maintain)(?:ed|ing|s)?\s+(?:\w+\s+){0,2}APIs?\b/i'], 'related' => ['api_integration', 'graphql']],
        'api_integration' => ['label' => 'Third-party API integration', 'category' => 'architecture', 'patterns' => ['/\bthird[- ]party (?:APIs?|integrations?|services)\b/i', '/\bintegrat(?:e|ing|ion with)\s+(?:\w+\s+){0,2}(?:APIs?|third[- ]party)\b/i', '/\b(?:API|partner|vendor) integrations?\b/i', '/\bexternal APIs?\b/i'], 'weak_patterns' => ['/\bexternal services\b/i'], 'related' => ['rest_api_design'], 'ask' => 'integrating your product or a client with someone else\'s API (payments, CRM, messaging, data providers, partner systems)'],
        'webhooks' => ['label' => 'Webhook integration', 'category' => 'architecture', 'patterns' => ['/\bweb-?hooks?\b/i'], 'related' => ['api_integration', 'event_driven'], 'ask' => 'working with webhooks: configuring them, consuming them, supporting merchants or customers who use them'],
        'webhook_handlers' => ['label' => 'Webhook handler implementation', 'category' => 'architecture', 'parent' => 'webhooks', 'patterns' => ['/\b(?:build|implement|develop|design|handle|handling|process|processing|consum)\w*\s+(?:\w+\s+){0,3}web-?hooks?\b/i', '/\bweb-?hook (?:handlers?|endpoints?|receivers?|consumers?|processing)\b/i'], 'related' => ['webhooks'], 'ask' => 'building the receiving side of webhooks: endpoints, signature checks, processing events'],
        'retry_idempotency' => ['label' => 'Retry, idempotency and failure handling', 'category' => 'architecture', 'patterns' => ['/\bidempoten\w+\b/i', '/\bretry (?:logic|strateg\w+|mechanisms?|handling|schedules?|policies)\b/i', '/\b(?:exponential backoff|dead[- ]letter|at-least-once|exactly-once)\b/i'], 'related' => ['event_driven'], 'ask' => 'making integrations safe to repeat or fail: retry schedules, idempotency keys, deduplication, recovering from failed events'],
        'event_driven' => ['label' => 'Queues, events and background jobs', 'category' => 'architecture', 'patterns' => ['/\b(?:Kafka|RabbitMQ|SQS|SNS|Sidekiq|Celery)\b/', '/\b(?:message (?:queues?|brokers?)|event[- ]driven|pub\/?sub|background (?:jobs?|processing|workers?)|job queues?|asynchronous processing)\b/i'], 'related' => ['webhooks']],
        'auth_security' => ['label' => 'Authentication and application security', 'category' => 'architecture', 'patterns' => ['/\b(?:OAuth2?|SSO|SAML|OIDC|JWT|OWASP)\b/', '/\b(?:application security|secure coding)\b/i', '/\bencrypted (?:at rest|credentials)\b/i', '/\bcredential (?:handling|storage)\b/i'], 'weak_patterns' => ['/\b(?:authentication|authorization)\b/i'], 'related' => []],
        'system_design' => ['label' => 'System and architecture design', 'category' => 'architecture', 'patterns' => ['/\b(?:system design|software architecture|technical design|design documents?|technical specs?|design reviews?|architecture reviews?)\b/i', '/\barchitectural (?:design|decisions?|reviews?|patterns?)\b/i', '/\bdesign(?:ed|ing)?(?: and evolved)? (?:\w+ ){0,2}architectures?\b/i', '/\bcomponent boundaries\b/i'], 'weak_patterns' => ['/\barchitect(?:ure|ing)\b/i'], 'related' => ['rest_api_design', 'data_modeling', 'distributed_systems'], 'ask' => 'system or architecture design (choosing how components fit together, weighing trade-offs, writing design documents)'],
        'architecture_ownership' => ['label' => 'Owning architecture and technical direction', 'category' => 'responsibility', 'patterns' => ['/\b(?:own|owning|lead|leading|define|defining|drive|driving|set|setting|shape|shaping)\b[^.\n]{0,40}\b(?:architecture|architectural (?:direction|vision|strategy)|technical (?:strategy|direction|vision|roadmap))\b/i'], 'related' => ['system_design'], 'ask' => 'owning architecture or technical direction for a product or team'],
        'distributed_systems' => ['label' => 'Distributed systems and microservices', 'category' => 'architecture', 'patterns' => ['/\bdistributed (?:systems?|architectures?|computing|services)\b/i', '/\bmicroservices?\b/i', '/\bservice[- ]oriented\b/i', '/\bhigh[- ]availability\b/i', '/\bhorizontal(?:ly)? scal\w+\b/i'], 'weak_patterns' => ['/\bhigh[- ]traffic\b/i'], 'related' => []],
        'scalability_performance' => ['label' => 'Scalability and performance work', 'category' => 'practice', 'patterns' => ['/\bperformance (?:optimi[sz]ation|tuning|engineering)\b/i', '/\bscal(?:e|ing) (?:the )?(?:product|platform|system|application)s?\b/i', '/\bscalable (?:systems?|applications?|services?|solutions?|architectures?)\b/i', '/\bperformance work\b/i', '/\b(?:reduc\w+|minimi[sz]\w+) (?:unnecessary )?data transfer\b/i', '/\bquery (?:performance|optimi[sz]ation)\b/i'], 'weak_patterns' => ['/\bscalab\w+\b/i'], 'related' => []],
        'data_modeling' => ['label' => 'Data modeling and migrations', 'category' => 'architecture', 'patterns' => ['/\b(?:data model(?:ing|s)?|schema design|database design|database migrations?)\b/i'], 'related' => ['sql_databases']],
        'data_pipelines' => ['label' => 'Data pipelines and warehousing', 'category' => 'architecture', 'patterns' => ['/\b(?:ETL|ELT|data pipelines?|data warehouse|Snowflake|BigQuery|dbt)\b/'], 'related' => ['sql_databases']],
        'frontend_architecture' => ['label' => 'Front-end architecture and design systems', 'category' => 'architecture', 'patterns' => ['/\b(?:design systems?|component librar(?:y|ies)|state management|Redux|accessibility|WCAG)\b/i', '/\bresponsive (?:design|web)\b/i', '/\bfront-?end architectures?\b/i', '/\breusable (?:UI )?components\b/i'], 'related' => ['react']],
        'payment_gateways' => ['label' => 'Payment gateways and processors', 'category' => 'architecture', 'patterns' => ['/\bpayment[\s-]+(?:gateways?|processors?|platforms?|integrations?|processing)\b/i', '/\b(?:Stripe|Braintree|Adyen|PayPal|Authorize\.net)\b/'], 'related' => ['api_integration', 'webhooks'], 'ask' => 'working with payment gateways or processors, from integration through supporting live merchants'],
        'recurring_billing' => ['label' => 'Recurring billing and subscriptions', 'category' => 'architecture', 'patterns' => ['/\b(?:recurring|subscription)[\s-]+(?:billing|payments?|charges?|lifecycle|management)\b/i', '/\bbilling (?:systems?|setup|platforms?|workflows?)\b/i'], 'related' => ['payment_gateways'], 'ask' => 'recurring or subscription billing: setup, renewal and failure flows, migrations between systems'],
        'financial_aggregation' => ['label' => 'Financial-account aggregation', 'category' => 'architecture', 'patterns' => ['/\b(?:Plaid|Finicity|Yodlee)\b/', '/\baccount aggregation\b/i'], 'related' => ['api_integration']],

        // ---- Responsibilities and ownership expectations ----
        'end_to_end_ownership' => ['label' => 'End-to-end feature ownership', 'category' => 'responsibility', 'patterns' => ['/\bown(?:ership)?\s+(?:of\s+)?(?:\w+\s+){0,3}(?:features?|products?|projects?|systems?|services?)\b/i', '/\b(?:full (?:software )?(?:development )?lifecycle|from concept to|0[- ]to[- ]1|zero[- ]to[- ]one)\b/i', '/\bfrom (?:initial |the )?(?:business )?requirements (?:through|to)\b/i', '/\b(?:designed|built|developed) and implemented\b[^.]{0,80}\bfrom\b[^.]{0,30}\brequirements\b/i'], 'weak_patterns' => ['/\bend[- ]to[- ]end\b/i'], 'related' => [], 'ask' => 'taking something from idea to production yourself: what you owned, decided and shipped'],
        'mentoring_leadership' => ['label' => 'Mentoring and technical leadership', 'category' => 'responsibility', 'patterns' => ['/\bmentor\w*\b/i', '/\b(?:tech(?:nical)? lead\w*|lead (?:a )?(?:team|engineers?|projects?)|set (?:the )?technical direction)\b/i'], 'related' => ['code_review'], 'ask' => 'mentoring other engineers or leading technical work'],
        'customer_facing' => ['label' => 'Customer-facing technical work', 'category' => 'responsibility', 'patterns' => ['/\b(?:customer|client)[- ]facing\b/i', '/\bwork(?:ing)? directly with (?:customers|clients|merchants)\b/i', '/\b(?:solutions? engineer\w*|stakeholder management)\b/i'], 'weak_patterns' => ['/\btechnical support\b/i'], 'related' => []],

        // ---- Collaboration and delivery ----
        'cross_functional' => ['label' => 'Cross-functional collaboration', 'category' => 'collaboration', 'patterns' => ['/\bcross[- ]functional\b/i', '/\bwork(?:ing)? closely with (?:product|design)\b/i', '/\bacross (?:engineering|product|marketing|analytics)[^.]{0,60}\bteams\b/i'], 'weak_patterns' => ['/\b(?:product managers?|designers?)\b/i'], 'related' => ['customer_facing']],
        'agile_delivery' => ['label' => 'Agile delivery', 'category' => 'collaboration', 'patterns' => ['/\b(?:agile|scrum|kanban|sprint planning)\b/i', '/\bship(?:ping)? (?:fast|quickly|frequently|iteratively)\b/i', '/\brapid iteration\b/i'], 'related' => []],
        'product_sense' => ['label' => 'Product thinking', 'category' => 'collaboration', 'patterns' => ['/\bproduct (?:sense|thinking|mindset|intuition|minded|-minded)\b/i', '/\b(?:user[- ]centric|customer[- ]obsessed)\b/i'], 'weak_patterns' => ['/\b(?:user experience|UX)\b/'], 'related' => ['cross_functional', 'conversion_funnels']],
        'conversion_funnels' => ['label' => 'Conversion funnels, checkout and onboarding flows', 'category' => 'collaboration', 'patterns' => ['/\b(?:conversion (?:rate|optimi[sz]ation|funnels?)|funnels?|checkout (?:flows?|experience|conversion))\b/i'], 'weak_patterns' => ['/\b(?:conversion|checkout|onboarding|registration)\b/i'], 'related' => ['experimentation', 'product_sense']],

        // ---- Domain experience ----
        'domain_saas_b2b' => ['label' => 'B2B SaaS', 'category' => 'domain', 'patterns' => ['/\b(?:B2B|SaaS|multi-?tenant|enterprise software)\b/'], 'related' => []],
        'domain_fintech' => ['label' => 'Fintech and payments', 'category' => 'domain', 'patterns' => ['/\b(?:fintech|financial services|banking|lending|insurance)\b/i', '/\bpayments? (?:industry|company|space|platform)\b/i'], 'related' => ['payment_gateways', 'recurring_billing']],
        'domain_ecommerce' => ['label' => 'E-commerce and marketplaces', 'category' => 'domain', 'patterns' => ['/\b(?:e-?commerce|Shopify|storefront)\b/i'], 'weak_patterns' => ['/\b(?:marketplace|retail)\b/i'], 'related' => ['conversion_funnels']],
        'domain_healthcare' => ['label' => 'Healthcare technology', 'category' => 'domain', 'patterns' => ['/\b(?:healthcare|health ?tech|HIPAA|clinical)\b/i'], 'related' => []],
        'domain_devtools' => ['label' => 'Developer tools and platforms', 'category' => 'domain', 'patterns' => ['/\b(?:developer tools?|developer experience|SDKs?|developer platform|API platform)\b/i'], 'related' => ['api_integration']],
    ],

    /*
     | Optional model phrasing of interview questions, done once per build (never on a page
     | load). Ranking stays deterministic. Off by default; enable with RESUME_PROFILE_ENRICH=true
     | or run `php artisan profile:enrich`.
     */
    /*
     | The reference resume's fixed budget (see ReferenceSelection). The user's profile decisions choose the
     | evidence; these only bound how much of it one resume carries.
     */
    'reference' => ['total_bullets' => 15, 'per_role_max' => 6, 'developer_role_floor' => 3, 'per_project_max' => 3, 'outcomes_per_lead' => 2, 'personal_projects_max' => 2, 'summary_facts' => 8,
        // The user's top employment projects are protected, by swapping first and by at most this many extra bullets in all.
        'priority_projects' => 3, 'coverage_extra_bullets' => 2, 'overlap_limit' => 0.6, 'skills' => 20,
    ],

    'enrichment' => ['enabled' => (bool) env('RESUME_PROFILE_ENRICH', false), 'prompt_version' => 'profile-question-v1', 'reasoning_effort' => 'low'],

    /*
     | Interview ranking. score = importance x gap x answerability x usefulness.
     */
    'ranking' => [
        'level_weight' => ['required' => 1.0, 'duty' => 0.9, 'mentioned' => 0.6, 'preferred' => 0.5],
        'gap' => ['no_evidence' => 1.0, 'underdocumented' => 0.85, 'transferable' => 0.55, 'deepening_shallow' => 0.6, 'deepening_moderate' => 0.3],
        'answerability' => ['deepening' => 1.0, 'underdocumented' => 1.0, 'transferable' => 0.9, 'no_evidence_with_adjacent' => 0.7, 'no_evidence' => 0.4],
        'usefulness' => ['responsibility' => 1.0, 'architecture' => 1.0, 'practice' => 0.9, 'domain' => 0.8, 'collaboration' => 0.8, 'technology' => 0.75],
    ],
];
