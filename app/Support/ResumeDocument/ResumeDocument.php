<?php

namespace App\Support\ResumeDocument;

/**
 * The complete, presentation-ready content of one rendered resume —
 * immutable value objects only, never an Eloquent model and never a
 * raw provider/model response. Built once by GenerateResumeDocument
 * from one ResumeVariant's frozen snapshot data; both the HTML preview
 * (ResumeVariantPreviewController) and PDF export
 * (ResumeVariantPdfController / GenerateResumePdf) are pure,
 * deterministic renderers over this tree and nothing else — the same
 * resources/views/resume/print.blade.php markup for both. See
 * docs/resume-variant-generation.md "PDF export".
 *
 * Section order (Summary -> Skills -> Experience -> Selected Projects
 * -> Education) is a fixed v1 display convention — not derived from
 * anything, and NOT the same as this constructor's own param order
 * below — applied only by whatever consumes this tree (currently
 * resources/views/resume/print.blade.php). This order is a deliberate
 * product decision: Summary establishes positioning, Skills
 * immediately demonstrates target-job relevance and is highly
 * scannable, and Experience then provides proof. Selected Projects
 * sits between Experience and Education, matching Selected Projects'
 * own role as supplementary independent-project evidence rather than
 * primary Experience proof (see docs/domain-model.md "ResumeVariant"
 * -> "Selected Projects").
 */
final readonly class ResumeDocument
{
    /**
     * @param  ResumeExperienceRole[]  $experience
     * @param  ResumeSkillGroup[]  $skills  Fixed v1 category groups, in a fixed display order; each group's skill names preserve Selection's own order. See GenerateResumeDocument.
     * @param  ResumeSelectedProject[]  $selectedProjects  0-1 under the current Selection cap (ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS); a historical variant persisted under the original 0-3 schema may still carry more — this DTO renders whatever was actually persisted, never re-validates it. Ordered by Selection's own relevance order; empty when none were selected.
     * @param  ResumeEducationEntry[]  $education
     */
    public function __construct(
        public ResumeContact $contact,
        public string $summary,
        public array $experience,
        public array $skills,
        public array $selectedProjects,
        public array $education,
        /** Reference resumes show consecutive roles at one employer under a single employer line. */
        public bool $groupEmployers = false,
    ) {}

    /**
     * A copy with the single lowest-priority optional block removed, or
     * null when nothing optional remains. Used only by the PDF fit step
     * when a document cannot fit the two-page limit: Selected Projects
     * go first (last-ranked first), then the last bullet of whichever
     * role currently has the most bullets (oldest role on ties). Every
     * role keeps at least one bullet, and Selection already orders a
     * role's bullets strongest first, so this only ever drops the
     * weakest remaining evidence. Never adds or rewrites anything.
     */
    public function withoutLowestPriorityBlock(): ?self
    {
        if ($this->selectedProjects !== []) {
            return $this->with(selectedProjects: array_slice($this->selectedProjects, 0, -1));
        }

        $target = null;
        $targetCount = 1;

        foreach ($this->experience as $index => $role) {
            $count = count($role->bullets);

            if ($count >= 2 && $count >= $targetCount) {
                $target = $index;
                $targetCount = $count;
            }
        }

        if ($target === null) {
            return null;
        }

        $experience = $this->experience;
        $role = $experience[$target];
        $experience[$target] = new ResumeExperienceRole(
            employerName: $role->employerName,
            displayTitle: $role->displayTitle,
            dateRangeLabel: $role->dateRangeLabel,
            isCurrent: $role->isCurrent,
            bullets: array_slice($role->bullets, 0, -1),
        );

        return $this->with(experience: $experience);
    }

    /**
     * The experience as the renderer lays it out: each entry is the roles of one employer, in order. Roles are grouped
     * only for reference resumes, and only when they follow one another; otherwise every role stands alone.
     *
     * @return array<int, array{employer: string, roles: array<int, ResumeExperienceRole>, shared: bool}>
     */
    public function employerGroups(): array
    {
        $groups = [];

        foreach ($this->experience as $role) {
            $last = array_key_last($groups);

            if ($this->groupEmployers && $last !== null && $groups[$last]['employer'] === $role->employerName) {
                $groups[$last]['roles'][] = $role;
                $groups[$last]['shared'] = true;
            } else {
                $groups[] = ['employer' => $role->employerName, 'roles' => [$role], 'shared' => false];
            }
        }

        return $groups;
    }

    /**
     * @param  array<int, ResumeExperienceRole>|null  $experience
     * @param  array<int, ResumeSelectedProject>|null  $selectedProjects
     */
    private function with(?array $experience = null, ?array $selectedProjects = null): self
    {
        return new self(
            contact: $this->contact,
            summary: $this->summary,
            experience: $experience ?? $this->experience,
            skills: $this->skills,
            selectedProjects: $selectedProjects ?? $this->selectedProjects,
            education: $this->education,
            groupEmployers: $this->groupEmployers,
        );
    }
}
