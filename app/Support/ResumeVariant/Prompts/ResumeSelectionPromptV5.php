<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * A policy revision of ResumeSelectionPromptV4 — a new class, never an
 * in-place edit, because `resume-selection-v4` is the persisted
 * `selection_prompt_version` on real ResumeVariants.
 *
 * What changed (Sprint 1: resume quality), and why: a read-only audit
 * of the finished Spotify resume found it underfilled (10 bullets, no
 * project highlights, older roles compressed to one bullet) even
 * though the eligible corpus held 10+ facts for each older role. The
 * cause was policy, not evidence: V4 asked for a 10-12 bullet TARGET
 * with a hard maximum of 13, told the model older roles deserve
 * "exactly the one bullet" their floor requires, and allowed one
 * independent project only. V5 replaces exactly three sections:
 *
 *  - "Overall resume length": no quota. Volume follows job relevance
 *    and evidence strength; page fit is a deterministic rendering
 *    concern handled after generation (GenerateResumePdf), so the model
 *    is told to order strongest-first rather than count to a budget.
 *    The validator keeps only wide sanity ceilings.
 *  - "Skills": relevance and support, no 12-16 quota.
 *  - "Selected Projects": up to three Project Highlights, independent
 *    OR professional, under a strict rule that a CareerFact is cited in
 *    exactly one place (Experience bullet group or highlight) — a
 *    deterministic validator check, not just prompt text.
 *
 * Every other V4 section is reused verbatim by deriving this prompt
 * from V4's own text, so the two cannot drift. The response SHAPE is
 * unchanged (schemaVersion stays '2.0'); only the legal
 * `selected_projects.project_id` set widens, which the caller supplies.
 */
final readonly class ResumeSelectionPromptV5
{
    private ResumeSelectionPromptV4 $base;

    public function __construct()
    {
        $this->base = new ResumeSelectionPromptV4;
    }

    public function version(): string
    {
        return 'resume-selection-v5';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        $sections = preg_split('/\n\n(?=## )/', $this->base->systemPrompt()) ?: throw new \LogicException('ResumeSelectionPromptV4 system prompt could not be split into sections.');
        $replacements = [
            'Overall resume length' => $this->lengthSection(),
            'Skills' => $this->skillsSection(),
            'Selected Projects' => $this->projectsSection(),
        ];

        foreach ($sections as $i => $section) {
            foreach ($replacements as $headingPrefix => $replacement) {
                if (str_starts_with($section, '## '.$headingPrefix)) {
                    $sections[$i] = $replacement;
                    unset($replacements[$headingPrefix]);
                }
            }
        }

        // A replaced section that no longer exists in V4's text would
        // silently drop a policy block — fail loudly instead.
        if ($replacements !== []) {
            throw new \LogicException('ResumeSelectionPromptV5 could not find V4 section(s): '.implode(', ', array_keys($replacements)));
        }

        return (string) preg_replace('/within the total\s+budget above/', 'within the relevance-and-evidence guidance above', implode("\n\n", $sections));
    }

    /**
     * @param  array<string, mixed>  $candidatePayload
     * @param  array<string, mixed>  $jobPayload
     * @param  array<int, array<string, mixed>>  $targetTerminology
     */
    public function userPrompt(array $candidatePayload, array $jobPayload, array $targetTerminology): string
    {
        return $this->base->userPrompt($candidatePayload, $jobPayload, $targetTerminology);
    }

    /**
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validSkillIds
     * @param  array<int, int>  $validFindingIds
     * @param  array<int, string>  $validTargetTerms
     * @param  array<int, string>  $validTitleChoiceKeys
     * @param  array<int, int>  $validProjectIds  Every Project (independent OR professional) with eligible evidence — the legal `selected_projects.project_id` set.
     * @return array<string, mixed>
     */
    public function jsonSchema(
        array $validRoleIds,
        array $validFactKeys,
        array $validSkillIds,
        array $validFindingIds,
        array $validTargetTerms,
        array $validTitleChoiceKeys,
        array $validProjectIds,
    ): array {
        return $this->base->jsonSchema(
            $validRoleIds, $validFactKeys, $validSkillIds, $validFindingIds,
            $validTargetTerms, $validTitleChoiceKeys, $validProjectIds,
        );
    }

    private function lengthSection(): string
    {
        return <<<'PROMPT'
        ## Overall resume length: relevance and evidence, not a quota

        There is no required or target number of bullets or Skills. Include
        every Experience bullet group that adds distinct, relevant, well
        supported evidence for this job; leave out evidence that is weak,
        redundant, or irrelevant. Do not pad to reach a count, and do not
        cut strong relevant evidence merely to be short.

        The finished document is laid out to one or two pages
        automatically after you respond, and if it cannot fit, the
        lowest-ranked material is trimmed from the end. So your job is
        ranking, not counting: within each role, order bullet groups
        strongest first, and order Project Highlights strongest first.

        Under-selection is the more common failure: do not stop at a handful
        of bullets while strong, distinct, relevant evidence remains
        unused. Read each role's eligible CareerFacts and ask whether each
        distinct, job-relevant accomplishment is represented. When a role
        has many eligible facts that bear on this job, it should normally
        carry several bullet groups, not one or two.

        Depth follows relevance, not recency alone. A role whose work is
        highly relevant to this job deserves as many bullet groups as its
        evidence genuinely supports, including an older role. A role with
        only weak relevance gets one modest bullet group (it must still
        appear, as the section above requires). A deterministic sanity
        ceiling exists (24 bullet groups in total, 24 Skills) only to
        catch runaway output; approaching it is never a goal.
        PROMPT;
    }

    private function skillsSection(): string
    {
        return <<<'PROMPT'
        ## Skills

        The Technical Skills section must never be empty: always select
        Skills. Select Skills from the supplied eligible-skills list only. Choose
        the Skills that are relevant to this job and supported by evidence
        you have selected, ordered by relevance to this job. Do not select
        a Skill whose only evidence is old and clearly superseded unless
        the job genuinely calls for it, and do not pad the list. A Skill
        attached to a CareerFact you have already selected elsewhere,
        especially one carrying the direct/required/high signal described
        above, is a cheap, high-value inclusion candidate.

        There is no Education selection here. Every resume-eligible
        Education record is always included in the final resume,
        deterministically, outside this response.
        PROMPT;
    }

    private function projectsSection(): string
    {
        return <<<'PROMPT'
        ## Project Highlights (selected_projects)

        You may choose up to three Project Highlights, each a dedicated
        entry with one generated bullet, for projects that deserve more
        than the passing mention an Experience bullet gives. A project may
        be independent (personal work outside any employer; its
        CareerFacts show `project` with no `employer`/`role`) or
        professional (its CareerFacts show a real `employer`/`role`). Where
        an earlier section speaks of "the single Selected Projects slot"
        or of independent projects only, read it as this section.

        Choose a highlight only when it adds evidence that the Experience
        bullets do not already tell effectively: a named system with a
        distinct technical story that is genuinely relevant to this job.
        Actively review the professional projects in the corpus (named
        systems the candidate built for an employer), not only independent
        ones: a substantial, job-relevant system with several supporting
        CareerFacts is a strong highlight candidate. Omitting highlights
        entirely is a normal, correct outcome when none qualifies. Never
        add one merely because the section exists.

        Fact exclusivity (checked deterministically, a violation is
        rejected outright): every CareerFact may appear in exactly one
        place among the Experience bullet groups and the Project
        Highlights. A fact you cite in a highlight must not appear in any
        Experience bullet group, and a fact used in an Experience bullet
        group must not appear in a highlight. If a project's story is
        already carried by an Experience bullet, do not highlight it.
        Remember that every resume-eligible role must keep at least one
        Experience bullet group of its own, so reserve a fact for each
        role before moving the rest of a project into a highlight.

        A highlight declares its own `project_id` and cites only
        CareerFacts attributed to that exact project. You choose evidence
        only: not wording, not technologies, not links. Any technology
        names shown come deterministically from the cited evidence's
        attached Skills.
        PROMPT;
    }
}
