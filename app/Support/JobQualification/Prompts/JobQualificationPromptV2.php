<?php

namespace App\Support\JobQualification\Prompts;

use App\Enums\QualificationVerdict;
use App\Models\JobPosting;
use App\Support\JobQualification\EligibilityAssessment;
use App\Support\JobQualification\JobQualificationResponseValidator as V;

/**
 * The screening prompt: one short, structured pass over the posting and a compact view of what the candidate can
 * substantiate. The policy lives in the system prompt in plain words; ReconcileVerdict then enforces what can be enforced
 * in code, so the prompt guides and the code guarantees.
 */
final class JobQualificationPromptV2
{
    public function version(): string
    {
        return 'job-qualification-v2';
    }

    public function schemaVersion(): string
    {
        return '1.1';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
You screen one job posting for a single candidate, quickly, before any expensive analysis. You are NOT writing a resume
and NOT doing a full match. Answer only from the posting text and the candidate capability summary you are given.

WHAT THE CANDIDATE WANTS (Balanced policy)
- Product and application development: full-stack, backend and frontend engineering; APIs, integrations and
  database-backed systems. Strongest stacks: PHP/Laravel, TypeScript, React, Node.js, Vue.
- Hands-on individual-contributor engineering over primarily managing people.
- US-remote work, eligible from Florida.

HOW TO JUDGE
- Role focus and mandatory expertise matter far more than keyword overlap. Decide what the person would actually spend
  their days doing (primary_role_focus, role_type, hands_on) before looking at technologies. An infrastructure, DevOps or
  platform-operations role that merely mentions application languages is infrastructure_devops, not product engineering.
- hands_on says how much of the job is writing software versus managing people:
  * hands_on: an individual contributor who implements software, designs and maintains systems, reviews code, owns technical
    architecture, gives technical leadership, mentors engineers or leads projects WITHOUT having direct reports. Senior,
    Staff, Principal and Lead titles, and words like "mentor", "lead" or "own", are NOT evidence of people management.
  * primarily_management: the job is managing people: direct reports, performance reviews, hiring or staffing, building and
    running a team or organization, budget or personnel ownership, with management as the main function. Use it only when
    the posting states such responsibilities, and cite those sentences in management_evidence_refs.
  * mixed: both coding and substantial people management are real parts of the job.
  * unclear: the posting does not say. When in doubt between hands_on and primarily_management, do not choose
    primarily_management. role_type engineering_management follows the same rule and needs the same cited evidence.
  Leave management_evidence_refs empty unless you chose primarily_management or engineering_management.
- List the technologies that matter, each as mandatory, preferred or incidental. A technology that is only "nice to have",
  "a plus" or mentioned in passing is preferred or incidental, and must never lower the recommendation.
- Set central true only for a technology the role is built around: the main language or framework of the work, named as a
  requirement or the core of the responsibilities. A technology that appears as one clause in a list of duties, an
  implementation detail, an example or a secondary task is central false, even if the posting lists it.
- Mark a technology "familiar" only if it appears in the candidate summary's skills. "related" means a closely analogous
  technology IS in the summary (for example another web framework in the same language family). Otherwise "unfamiliar".
  A skill missing from the summary means "not evidenced", never "the candidate cannot do it". Never invent experience.
- For a mandatory technology the candidate does not have, say how much the posting demands: deep_production (explicit
  years or deep production expertise required), working (hands-on use expected) or unstated. Set transferable_accepted
  true only when the posting explicitly welcomes equivalent or similar frameworks or transferable experience.
- An unfamiliar PRIMARY stack (mandatory, central, and demanding deep production experience) that does not accept
  transferable experience makes the job a poor match. If transferable experience is explicitly accepted, the job is
  needs_review or good. An unfamiliar technology that is preferred, incidental or not central is NOT a disqualifier.
- Never judge a job poor only because a technology such as Ruby, Python, AWS or Kubernetes appears.

VERDICT
- strong: clearly the kind of role wanted, on a stack the candidate has, with no significant concern.
- good: a solid fit with a minor concern or a stack gap that is explicitly bridgeable.
- needs_review: plausible but unclear, or a real concern a person should weigh.
- poor: the role's focus or mandatory expertise is clearly not a fit.

EVIDENCE
Every technology, fit reason, the biggest concern and any management claim must cite one or two segment ids from the posting (for example S004)
in evidence_refs. Cite only ids that are listed. Keep every field short and concrete, in English, and never put segment ids inside the sentences you write (cite them only in the evidence_refs fields). Use biggest_concern null (with an
empty biggest_concern_evidence_refs) only when there is none worth raising. Do not state a confidence number.
PROMPT;
    }

    /**
     * @param  array<string, string>  $segments  Segment id => exact source text.
     * @param  array{skills: list<array<string, mixed>>, roles: list<array<string, mixed>>, clearance_evidence: bool}  $capabilities
     */
    public function userPrompt(JobPosting $job, array $segments, EligibilityAssessment $eligibility, array $capabilities): string
    {
        $skills = implode("\n", array_map(
            fn (array $s) => "- {$s['name']} ({$s['category']}; {$s['evidence_count']} evidenced; last used {$s['last_used']})",
            $capabilities['skills'],
        ));
        $roles = implode("\n", array_map(fn (array $r) => "- {$r['title']}, {$r['employer']} ({$r['from']}–{$r['to']})", $capabilities['roles']));
        $unclear = array_values(array_filter(array_map(
            fn (array $r) => $r['severity'] === 'unknown' ? "- {$r['message']}" : null,
            $eligibility->reasons,
        )));

        $body = '';
        foreach ($segments as $id => $text) {
            $body .= "[{$id}] {$text}\n";
        }

        return "Company: {$job->company}\n"
            ."Title: {$job->title}\n"
            .'Location: '.($job->location ?? 'Not specified')."\n"
            .'Workplace: '.($job->remote_status->value ?? 'not stated')."\n"
            .($unclear === [] ? '' : "\nUnclear practical eligibility (mention it as a concern if it matters):\n".implode("\n", $unclear)."\n")
            ."\n--- Candidate capability summary ---\nSkills the candidate has substantiated:\n".($skills === '' ? "(none recorded)\n" : $skills."\n")
            ."Roles held:\n".($roles === '' ? "(none recorded)\n" : $roles."\n")
            ."--- End of candidate capability summary ---\n\n"
            ."--- Job posting description (source segments) ---\n"
            .$body
            .'--- End of job posting description ---';
    }

    /**
     * @param  list<string>  $segmentIds
     * @return array<string, mixed>
     */
    public function jsonSchema(array $segmentIds): array
    {
        $refs = fn (int $min) => ['type' => 'array', 'minItems' => $min, 'maxItems' => V::MAX_EVIDENCE_REFS, 'items' => ['type' => 'string', 'enum' => $segmentIds]];
        $enum = fn (array $values) => ['type' => 'string', 'enum' => $values];

        return [
            'type' => 'object',
            'properties' => [
                'verdict' => $enum(array_column(QualificationVerdict::cases(), 'value')),
                'role_type' => $enum(V::ROLE_TYPES),
                'primary_role_focus' => ['type' => 'string', 'maxLength' => 200],
                'hands_on' => $enum(V::HANDS_ON),
                'core_responsibilities' => ['type' => 'array', 'minItems' => 1, 'maxItems' => V::MAX_RESPONSIBILITIES, 'items' => ['type' => 'string', 'maxLength' => 200]],
                'technologies' => [
                    'type' => 'array',
                    'maxItems' => V::MAX_TECHNOLOGIES,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'maxLength' => 80],
                            'requirement' => $enum(V::REQUIREMENT),
                            'familiarity' => $enum(V::FAMILIARITY),
                            'depth' => $enum(V::DEPTH),
                            'central' => ['type' => 'boolean'],
                            'transferable_accepted' => ['type' => 'boolean'],
                            'evidence_refs' => $refs(1),
                        ],
                        'required' => ['name', 'requirement', 'familiarity', 'depth', 'central', 'transferable_accepted', 'evidence_refs'],
                        'additionalProperties' => false,
                    ],
                ],
                'seniority' => $enum(V::SENIORITY),
                'fit_reasons' => [
                    'type' => 'array',
                    'maxItems' => V::MAX_FIT_REASONS,
                    'items' => [
                        'type' => 'object',
                        'properties' => ['text' => ['type' => 'string', 'maxLength' => 240], 'evidence_refs' => $refs(1)],
                        'required' => ['text', 'evidence_refs'],
                        'additionalProperties' => false,
                    ],
                ],
                'biggest_concern' => ['type' => ['string', 'null'], 'maxLength' => 240],
                'biggest_concern_evidence_refs' => $refs(0),
                'management_evidence_refs' => $refs(0),
                'practical_concerns' => ['type' => 'array', 'maxItems' => V::MAX_PRACTICAL_CONCERNS, 'items' => ['type' => 'string', 'maxLength' => 200]],
            ],
            'required' => V::TOP_LEVEL_KEYS,
            'additionalProperties' => false,
        ];
    }
}
