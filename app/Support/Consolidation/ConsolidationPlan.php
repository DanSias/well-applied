<?php

namespace App\Support\Consolidation;

/**
 * The reviewed set of corrections prepared after the Career Evidence Quality Audit. It is data, not
 * a generator: every statement is the user's own words from the cited answer, rearranged so one
 * claim sits in one place, and nothing in it is applied until the user approves that item.
 *
 * Facts are named by their stable `key`, places by name, so the plan survives a re-import.
 * A place is ['project'|'role', name] or ['profile'] (the whole career: not tied to one employer).
 */
final class ConsolidationPlan
{
    public const FRONTEND_GROUP = 'c4394b9e-2a61-4847-aa42-17cf761566cf';

    private const PLACES_FOR_TOOLING = [['role', 'Developer Support Engineer'], ['role', 'Data & Analytics Lead Developer / Data Analyst'], ['role', 'Founder & Full Stack Developer / Marketing Consultant'], ['profile']];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function items(): array
    {
        $wi = ['project', 'Workflow Intelligence'];
        $nexus = ['project', 'Nexus: Analytics Command Center'];
        $toolkit = ['project', 'Transaction Toolkit'];
        $rocketgate = ['role', 'Developer Support Engineer'];
        $pearson = ['role', 'Data & Analytics Lead Developer / Data Analyst'];

        return [
            // --- Workflow Intelligence -------------------------------------------------------------
            [
                'code' => 'WI-1', 'theme' => 'Workflow Intelligence', 'action' => 'revise', 'fact' => 'rocketgate-end-to-end-ownership-user-confirmed',
                'title' => 'Workflow Intelligence: end-to-end ownership',
                'summary' => 'The approved answer mixes Workflow Intelligence with Pearson and sits on the RocketGate role. This keeps the Workflow Intelligence half, on the project, and adds only what facts 14 and 3 do not already say: from requirements through testing and delivery.',
                'statement' => 'Independently owned Workflow Intelligence from initial business requirements through architecture, implementation, testing and delivery, working from business requirements, existing workflow documentation, Jira tickets and API access.',
                'place' => $wi, 'links' => ['rocketgate-workflow-intelligence-ownership-breadth', 'rocketgate-independently-implemented-from-team-requirements', 'rocketgate-workflow-intelligence-independently-implemented', 'rocketgate-workflow-intelligence-jira-gitlab-unification'],
                'notes' => ['covered_elsewhere' => 'The Pearson half (Nexus about 20 hours per week, Forecasting Platform 10 to 15 hours per month) is already recorded as facts pearson-nexus-hours-saved-per-week and pearson-marketing-budget-forecast-hours-saved-per-month, so it is not repeated. The scope list (architecture, backend, frontend, analytics, AI) is already fact 14, so it is not restated.'],
            ],
            [
                'code' => 'WI-2', 'theme' => 'Workflow Intelligence', 'action' => 'revise', 'fact' => 'rocketgate-rest-api-design-user-confirmed',
                'title' => 'Workflow Intelligence: backend and API implementation',
                'summary' => 'The approved answer covers three employers on the RocketGate role. Its Workflow Intelligence sentence becomes a project fact. The general REST claim moves to its own profile-level fact (REST-1).',
                'statement' => 'Built Workflow Intelligence\'s application backend and integrated the Jira and GitLab APIs to retrieve and process engineering workflow data for the frontend and analytics features.',
                'place' => $wi, 'skills' => ['Jira', 'GitLab', 'API integration & design'], 'links' => ['rocketgate-workflow-intelligence-jira-gitlab-unification'],
                'notes' => ['covered_elsewhere' => 'The payment-gateway API sentence is already facts rocketgate-merchant-integration-technical-contact and rocketgate-merchant-integration-testing-troubleshooting. The Salesforce and BigQuery sentence is already fact pearson-nexus-what-it-is. Neither is repeated.'],
            ],
            [
                'code' => 'REST-1', 'theme' => 'Workflow Intelligence', 'action' => 'create', 'source' => 'rocketgate-rest-api-design-user-confirmed', 'topic' => 'rest_api_design', 'topic_label' => 'REST API design and development',
                'title' => 'REST APIs: development and integration (general)',
                'summary' => 'Your general claim, kept as one fact for the whole career because it is not tied to one employer. It is what lets REST API development register directly.',
                'statement' => 'Worked extensively with REST APIs in full-stack applications, both developing backend application endpoints and integrating external services.',
                'place' => ['profile'], 'scopes' => ['implemented', 'integrated'], 'visibility' => 'restricted', 'skills' => ['REST APIs'],
                'notes' => ['why' => 'Stays separate from WI-2 because it states a general capability, not a Workflow Intelligence accomplishment.'],
            ],
            // --- Frontend ----------------------------------------------------------------------------
            [
                'code' => 'FE-1', 'theme' => 'Frontend architecture', 'action' => 'revise', 'fact' => 'rocketgate-frontend-architecture-user-confirmed',
                'title' => 'Frontend architecture and development (general)',
                'summary' => 'The approved answer names Pearson, RocketGate and Liquid Gravity but sits on the RocketGate role. It becomes one whole-career fact. The "owned the frontend of three applications" claim is split out per project below.',
                'statement' => 'Designed and developed frontend architectures for full-stack applications across Pearson, RocketGate and Liquid Gravity: structured interfaces into reusable components, managed frontend data flows, integrated backend APIs, and designed interactive dashboards and workflows around complex business requirements, making UI/UX decisions and iterating on responsive layouts based on how people used the tools.',
                'place' => ['profile'], 'links' => ['pearson-nexus-what-it-is'],
            ],
            self::frontendOwnership('FE-2', $wi),
            self::frontendOwnership('FE-3', $nexus),
            self::frontendOwnership('FE-4', $toolkit),
            // --- Cross-functional technical leadership -----------------------------------------------
            [
                'code' => 'XF-1', 'theme' => 'Technical leadership', 'action' => 'revise', 'fact' => 'rocketgate-mentoring-leadership-user-confirmed',
                'title' => 'Technical leadership at RocketGate',
                'summary' => 'Shortened to what you said, keeping your own limit: technical guidance and decisions, not people management. It will match as technical leadership, not as mentoring.',
                'statement' => 'Provided technical leadership by owning implementations, making architecture and technology decisions, and giving technical guidance to merchants and developers on API integrations, SDKs and implementation challenges, rather than through formal people management.',
                'place' => $rocketgate, 'links' => ['rocketgate-developer-documentation-onboarding', 'rocketgate-merchant-integration-technical-contact'],
                'notes' => ['covered_elsewhere' => 'Developer documentation and knowledge resources are already fact rocketgate-developer-documentation-onboarding, so they are not repeated.', 'caution' => 'Do not present this as mentoring or people management.'],
            ],
            [
                'code' => 'XF-2', 'theme' => 'Technical leadership', 'action' => 'revise', 'fact' => 'rocketgate-cross-functional-user-confirmed',
                'title' => 'Cross-functional work (whole career)',
                'summary' => 'The approved answer describes three employers on the RocketGate role. Pearson is already covered by pearson-data-analytics-lead-stakeholder-partnership and Liquid Gravity by its client-facing facts, so only the cross-career claim is kept, on the whole career.',
                'statement' => 'Worked across engineering, product, marketing, analytics and client-facing teams, translating business problems into practical technical solutions and owning their implementation.',
                'place' => ['profile'], 'links' => ['pearson-data-analytics-lead-stakeholder-partnership', 'liquid-gravity-client-facing-performance-walkthroughs'],
            ],
            // --- Duplicates and claim identity -------------------------------------------------------
            [
                'code' => 'DUP-TS', 'theme' => 'Duplicates and claim identity', 'action' => 'revise', 'fact' => 'knowledge-exporter-typescript-user-confirmed',
                'title' => 'TypeScript on Knowledge Exporter: join the shared claim',
                'summary' => 'Fact 80 is the old raw wording ("These projects were all built…") and sits outside the shared TypeScript claim. This gives it the same wording and joins the group, so TypeScript counts once, and Knowledge Exporter can still be shown as one of its places.',
                'statement' => '@statement-of:workflow-intelligence-typescript-user-confirmed', 'place' => ['project', 'Knowledge Exporter'], 'group' => '@group-of:workflow-intelligence-typescript-user-confirmed', 'skills' => ['TypeScript'],
                'notes' => ['visibility' => 'This fact is Public; the three siblings are Restricted. Visibility stays per place and is yours to choose.'],
            ],
            [
                'code' => 'DUP-ARCH', 'theme' => 'Duplicates and claim identity', 'action' => 'revise', 'fact' => 'pearson-online-learning-services-system-design-user-confirmed',
                'title' => 'Architecture on the Pearson role: join the Nexus claim',
                'summary' => 'Fact 82 ("Yes, these projects were built from scratch…") repeats fact 83 at role level. With the same wording and group it is one claim: shown under Nexus when a project is relevant, under the Pearson role otherwise.',
                'statement' => '@statement-of:nexus-analytics-platform-system-design-user-confirmed', 'place' => $pearson, 'group' => '@group-of:nexus-analytics-platform-system-design-user-confirmed',
            ],
            [
                'code' => 'DUP-CLOUD', 'theme' => 'Duplicates and claim identity', 'action' => 'revise', 'fact' => 'pearson-online-learning-services-cloud-platforms-user-confirmed',
                'title' => 'Cloud platforms: keep only the AWS statement',
                'summary' => 'GCP and Azure are already project facts (88, 89, 87), so this role-level fact repeats them. It is reduced to the one thing found nowhere else, AWS. Your answer says "all projects", which this does not settle, so the place is yours to choose.',
                'statement' => 'Worked on application projects hosted on AWS.', 'place' => $pearson, 'skills' => ['AWS'],
                'decision' => ['required' => false, 'question' => 'Which projects were hosted on AWS: Pearson, RocketGate, Liquid Gravity, or all of them?', 'candidates' => self::PLACES_FOR_TOOLING],
                'notes' => ['caution' => 'The wording is deliberately minimal until you say what you did on AWS (see the AWS question).'],
            ],
            self::advice('ADV-25', 25, 'Pending proposal #25 (architecture on Transaction Remediation)', 'Adds only "built from scratch, integrating self-built components with third-party libraries". Transaction Remediation already has architecture-level facts 24 to 29, so this adds little.'),
            self::advice('ADV-26', 26, 'Pending proposal #26 (architecture on Workflow Intelligence)', 'Workflow Intelligence already has facts 14, 16 and 17, which are more specific than this generic wording.'),
            self::advice('ADV-29', 29, 'Pending proposal #29 (GCP on the Pearson role)', 'Duplicates the approved GCP claim on Salesforce Migration and Executive Insights (88, 89), and adds an elaboration beyond your words.'),
            // --- Attribution -------------------------------------------------------------------------
            [
                'code' => 'ATT-DOCKER', 'theme' => 'Attribution', 'action' => 'revise', 'fact' => 'rocketgate-infrastructure-as-code-user-confirmed',
                'title' => 'Docker and Compose: which work was this?',
                'summary' => 'Your answer names no employer or project, and the question was asked about Pearson work. The current RocketGate attribution is not supported by the answer, so the place must be chosen. The wording keeps your own limit: this is not Terraform or CloudFormation experience.',
                'statement' => 'Configured Docker and Docker Compose environments for multi-service applications, including container services, networking, persistent volumes, environment variables, reverse proxies and application dependencies; strongest in reproducible application deployment and service configuration rather than cloud infrastructure provisioning with tools such as Terraform or CloudFormation.',
                'place' => null, 'skills' => ['Docker', 'Docker Compose'],
                'decision' => ['required' => true, 'question' => 'Where did you do this: RocketGate, Pearson, Liquid Gravity, or personal projects (whole career)?', 'candidates' => self::PLACES_FOR_TOOLING],
            ],
            [
                'code' => 'ATT-OBS-PRO', 'theme' => 'Attribution', 'action' => 'revise', 'fact' => 'rocketgate-observability-user-confirmed',
                'title' => 'Troubleshooting from logs: which work was this?',
                'summary' => 'Splits your answer in two. This half ("in my development work… investigated API failures…") names no employer. The self-hosted environment half is a separate fact below and is not attached to any employer.',
                'statement' => 'Investigated API failures, application errors and integration issues by examining logs, responses and system behavior to identify root causes.',
                'place' => null,
                'decision' => ['required' => true, 'question' => 'Which work did this happen in: RocketGate, Pearson, Liquid Gravity, or generally across your work (whole career)?', 'candidates' => self::PLACES_FOR_TOOLING],
                'links' => ['rocketgate-merchant-integration-testing-troubleshooting'],
            ],
            [
                'code' => 'ATT-OBS-HOME', 'theme' => 'Attribution', 'action' => 'create', 'source' => 'rocketgate-observability-user-confirmed', 'topic' => 'docker', 'topic_label' => 'Docker and containers',
                'title' => 'Self-hosted environment (personal)',
                'summary' => 'The second half of your answer. Self-hosted services, local AI models and GPU monitoring are not employment, so this is a whole-career fact, not tied to RocketGate. Edit the place if it belongs to a work project.',
                'statement' => 'Built and maintain a self-hosted environment running multiple Docker services, reverse proxies, local AI models and supporting applications, using container logs, service status, HTTP checks, GPU utilization and storage health information to diagnose failures, monitor resource usage and keep services running reliably; hands-on monitoring and diagnostics rather than a dedicated enterprise observability platform.',
                'place' => ['profile'], 'scopes' => ['implemented', 'configured', 'troubleshot'], 'visibility' => 'restricted', 'skills' => ['Docker'],
            ],
            [
                'code' => 'ATT-AZURE', 'theme' => 'Attribution', 'action' => 'revise', 'fact' => 'email-marketing-performance-tracker-cloud-platforms-user-confirmed',
                'title' => 'Azure identity: remove wording you did not say',
                'summary' => 'The approved wording adds "supporting authentication and account-access requirements", which is not in your answer. It also rests on one inferred word ("email"). This keeps what you said.',
                'statement' => 'Worked with Azure-based identity management for the Email Marketing Performance Tracker after Pearson migrated to Microsoft.',
                'place' => ['project', 'Email Marketing Performance Tracker'], 'skills' => ['Microsoft Azure'],
                'notes' => ['caution' => 'The project is inferred from the word "email" in your answer. Reject this item if Azure belonged to a different project.'],
            ],
            [
                'code' => 'ATT-SQL', 'theme' => 'SQL aggregation', 'action' => 'revise', 'fact' => 'pearson-online-learning-services-scalability-performance-user-confirmed',
                'title' => 'SQL-side aggregation and reporting performance',
                'summary' => 'Keeps the concrete technique, dropping the generic closing sentence. It stays on the Pearson role because your answer names no project; the Nexus question asks whether it applies there.',
                'statement' => 'Used SQL aggregation queries (GROUP BY, SUM) to calculate reporting totals in the database, reducing unnecessary data transfer and application-side computation in analytics and reporting workflows, instead of retrieving every record and processing it in application code.',
                'place' => $pearson, 'skills' => ['SQL'],
                'decision' => ['required' => false, 'question' => 'Did this apply to a specific project (for example Nexus)?', 'candidates' => [$nexus, ['project', 'Executive Insights Dashboard'], ['project', 'Marketing Budget & Forecast Hub']]],
                'notes' => ['caution' => 'No measured speedup is claimed; your answer gives none.'],
            ],
            // --- Liquid Gravity outcomes: what produced each result, as the user confirmed it (2026-11-04) ----------
            [
                'code' => 'LG-37', 'theme' => 'Liquid Gravity outcomes', 'action' => 'revise', 'fact' => 'liquid-gravity-conversion-increase',
                'title' => 'Liquid Gravity: what produced the 37% post-purchase conversion increase',
                'summary' => 'The 37% figure is unchanged (it came from your resume and portfolio). Your confirmation adds what produced it. The fact keeps its key, metric, visibility and original evidence; the current wording becomes its first revision.',
                'statement' => 'Improved post-purchase conversion rates by 37% through better alignment between initial purchases and follow-up offers, multiple upsell paths, and ongoing conversion optimization.',
                'place' => ['role', 'Founder & Full Stack Developer / Marketing Consultant'], 'scopes' => ['implemented'],
                'links' => ['liquid-gravity-ecommerce-checkout-upsell', 'liquid-gravity-experimentation-cro-landing-funnel'],
                'confirmation' => [
                    'topic_key' => 'post_purchase_conversion', 'topic_label' => 'Liquid Gravity: post-purchase conversion',
                    'question' => 'What produced the 37% increase in post-purchase conversion at Liquid Gravity?',
                    'answer' => 'The 37% improvement in post-purchase conversion came from better alignment of upsell offers with the customer\'s initial purchase, multiple post-purchase upsell paths, and ongoing conversion optimization.',
                ],
                'notes' => [
                    'why' => 'This fact came from your resume and portfolio, not an interview answer. Approving records those sources as its first revision and keeps them as evidence; no second fact is created.',
                    'caution' => 'Still unrecorded: which client or site, the platform, and whether the results were one engagement or consultancy-wide. The wording does not claim any of them, and the metric\'s scope note ("consultancy/role-level") is unchanged. "Ongoing conversion optimization" is general: no A/B method is claimed.',
                ],
            ],
            [
                'code' => 'LG-201', 'theme' => 'Liquid Gravity outcomes', 'action' => 'revise', 'fact' => 'liquid-gravity-lead-generation-growth',
                'title' => 'Liquid Gravity: what produced the 201.2% lead-generation growth',
                'summary' => 'The 201.2% figure and its "no added ad spend" qualifier are unchanged. Your confirmation adds what produced the growth: a direct-response landing page, a refined offer and ongoing optimization.',
                'statement' => 'Increased lead generation by 201.2% without additional advertising spend by developing a direct-response landing page, refining the offer, and continuously optimizing conversion performance.',
                'place' => ['role', 'Founder & Full Stack Developer / Marketing Consultant'], 'scopes' => ['implemented'],
                'links' => ['liquid-gravity-lead-generation-systems', 'liquid-gravity-experimentation-cro-landing-funnel'],
                'confirmation' => [
                    'topic_key' => 'lead_generation_growth', 'topic_label' => 'Liquid Gravity: lead-generation growth',
                    'question' => 'What produced the 201.2% increase in lead generation without increased advertising spend at Liquid Gravity?',
                    'answer' => 'The 201.2% increase in lead generation without increased advertising spend came from developing a landing page using direct-response marketing principles, improving the offer, and ongoing conversion optimization.',
                ],
                'notes' => [
                    'why' => 'This fact came from your resume and portfolio, not an interview answer. Approving records those sources as its first revision and keeps them as evidence; no second fact is created.',
                    'caution' => 'Still unrecorded: the client or site, the landing page\'s technology, and whether this was one engagement. No lead-generation system, funnel or A/B test is claimed beyond what is stated.',
                ],
            ],
            // --- Capability indexing (skill associations only) ---------------------------------------
            self::tag('TAG-TS', 'TypeScript', ['workflow-intelligence-typescript-user-confirmed', 'verbatim-typescript-user-confirmed', 'transaction-toolkit-typescript-user-confirmed'], ['TypeScript']),
            self::tag('TAG-GCP', 'GCP and BigQuery', ['salesforce-migration-data-warehouse-cloud-platforms-user-confirmed', 'executive-insights-dashboard-cloud-platforms-user-confirmed'], ['Google Cloud (general)', 'BigQuery']),
            self::tag('TAG-WEBHOOKS', 'Payment webhooks', ['rocketgate-webhooks-user-confirmed'], ['Webhooks']),
            self::tag('TAG-BILLING', 'Recurring billing', ['rocketgate-recurring-billing-user-confirmed'], ['Recurring billing']),
        ];
    }

    /**
     * Interview questions that would materially deepen the record, anchored to known places.
     *
     * @return array<int, array{code: string, topic_key: string, topic_label: string, text: string, place: array<int, string>}>
     */
    public static function questions(): array
    {
        return [
            ['code' => 'Q-NEXUS', 'topic_key' => 'nexus_architecture', 'topic_label' => 'Nexus architecture', 'place' => ['project', 'Nexus: Analytics Command Center'],
                'text' => 'Nexus: what was one architectural decision you would point to (for example how the API layer unified BigQuery and Salesforce, where calculations and aggregation happened, or how dashboards received their data)? And did the SQL-side aggregation you described for Pearson (GROUP BY and SUM in the database instead of summing in application code) apply to Nexus, or to a different project?'],
            ['code' => 'Q-FORECAST', 'topic_key' => 'forecast_hub_design', 'topic_label' => 'Marketing Forecasting Platform design', 'place' => ['project', 'Marketing Budget & Forecast Hub'],
                'text' => 'Marketing Forecasting Platform: how did the approval workflow work (who could submit, review and approve, and what happened at each step)? What did the forecast and budget data look like at a high level, and which parts did you design and build yourself?'],
            ['code' => 'Q-VERBATIM', 'topic_key' => 'verbatim_outcomes', 'topic_label' => 'Verbatim outcomes', 'place' => ['project', 'Verbatim'],
                'text' => 'Your record says onboarding effort fell from about 10 to about 3 active hours and integration-support tickets fell about 30%. Which of those came from the developer documentation and knowledge base, which from Verbatim\'s retrieval or support-reply drafting, and which from both together? Is there anything Verbatim alone produced that you can point to?'],
            ['code' => 'Q-LG', 'topic_key' => 'liquid_gravity_stack', 'topic_label' => 'Liquid Gravity technical work', 'place' => ['role', 'Founder & Full Stack Developer / Marketing Consultant'],
                'text' => 'Liquid Gravity: name one or two representative client builds. What platform or CMS were they on, what languages or frameworks did you code in, and what did you build yourself? Which build or client produced the 37% post-purchase conversion increase and the 201.2% lead-generation growth?'],
            ['code' => 'Q-CLOUD', 'topic_key' => 'aws_docker_responsibilities', 'topic_label' => 'AWS, Docker and Cloud Functions', 'place' => ['profile'],
                'text' => 'Which projects ran on AWS (Pearson, RocketGate, Liquid Gravity or personal), and what did you do there (deploy, configure services, manage environments)? Where did you use Docker and Compose in professional work, as opposed to personal projects? And did the Executive Insights Dashboard use Google Cloud Functions, and for what?'],
        ];
    }

    /**
     * @param  array<int, string>  $place
     * @return array<string, mixed>
     */
    private static function frontendOwnership(string $code, array $place): array
    {
        return [
            'code' => $code, 'theme' => 'Frontend architecture', 'action' => 'create', 'source' => 'rocketgate-frontend-architecture-user-confirmed', 'topic' => 'frontend_architecture', 'topic_label' => 'Front-end architecture and design systems',
            'title' => "Frontend ownership on {$place[1]}",
            'summary' => 'Your answer names Workflow Intelligence, Nexus and Transaction Toolkit as applications whose frontend you owned. One claim, three places: it counts once, and each place can be approved or declined separately.',
            'statement' => 'Owned the implementation of the frontend experience alongside the underlying application functionality, rather than working from pre-existing UI specifications.',
            'place' => $place, 'scopes' => ['implemented'], 'visibility' => 'restricted', 'group' => self::FRONTEND_GROUP,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function advice(string $code, int $proposalId, string $title, string $why): array
    {
        return [
            'code' => $code, 'theme' => 'Pending proposals', 'action' => 'advice', 'proposal' => $proposalId, 'title' => $title,
            'summary' => "Recommendation: reject as redundant. {$why} Approving this item rejects that proposal; declining leaves it exactly as it is.",
        ];
    }

    /**
     * @param  array<int, string>  $factKeys
     * @param  array<int, string>  $skills
     * @return array<string, mixed>
     */
    private static function tag(string $code, string $label, array $factKeys, array $skills): array
    {
        return [
            'code' => $code, 'theme' => 'Capability indexing', 'action' => 'tag', 'fact_keys' => $factKeys, 'skills' => $skills,
            'title' => "Tag skills: {$label}",
            'summary' => 'Adds only technologies the fact itself names. The wording is unchanged and no capability is inferred; related experience is not turned into direct experience.',
        ];
    }
}
