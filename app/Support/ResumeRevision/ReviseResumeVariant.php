<?php

namespace App\Support\ResumeRevision;

use App\Exceptions\InvalidResumePdfException;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use App\Exceptions\ResumeRevisionException;
use App\Models\CareerFact;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\ResumeVariantExperienceRole;
use App\Models\ResumeVariantSkillSelection;
use App\Models\Role;
use App\Support\JobTailoring\JobRelevance;
use App\Support\JobTailoring\ProfileJobAdapter;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ResumeDocument\AssessResumeLayout;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use App\Support\ResumeDocument\ResumeText;
use App\Support\ResumeVariant\CompositionChecks;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\RoleEligibleFacts;
use App\Support\ResumeVariant\WordingContextInputs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saves an edited resume as a new variant, leaving the one it was made from untouched (variants are immutable). No model
 * is called: a bullet is edited, replaced with another eligible fact (its own statement is the default text), removed or
 * moved, the summary is edited, and skills are removed. Everything not touched is copied with its wording and evidence.
 *
 * Edited text is judged by the same checks as generated text, against the facts it cites (for the summary, the facts
 * behind the whole resume): a blocking violation refuses the save and names the field; a substantive concern is kept
 * and shown. The revised resume must still render within the page limit. Review findings and composition checks are
 * recomputed for the revision, so it never shows a warning that belonged to a bullet that is gone.
 *
 * The draft is the desired end state:
 *   summary: string
 *   roles: [{role_id, bullets: [{id?: existing bullet id, fact_key?: replacement fact (or fact_keys?: several facts of one project), replaces?: bullet id, text: string}]}]
 *   skills: [skill ids to keep, in order]
 */
final class ReviseResumeVariant
{
    private const MAX_BULLETS = 20;

    private const MAX_FACTS_PER_BULLET = 4;

    public function __construct(
        private readonly ResumeCandidatePayloadBuilder $candidates,
        private readonly WordingContextInputs $inputs,
        private readonly ResumeWordingResponseValidator $validator,
        private readonly RoleEligibleFacts $eligible,
        private readonly CompositionChecks $composition,
        private readonly GenerateResumePdf $renderer,
        private readonly ResumePdfValidator $pdfValidator,
        private readonly ResumePdfStore $store,
        private readonly GenerateResumeDocument $documents,
        private readonly JobResumes $jobResumes,
        private readonly AssessResumeLayout $layoutReview,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     *
     * @throws ResumeRevisionException
     */
    public function revise(JobPosting $posting, ResumeVariant $base, array $draft): ResumeVariant
    {
        $base->loadMissing(['careerProfile', 'jobAnalysis.jobPosting', 'synthesis', 'experienceRoles.bullets.citations.careerFact', 'summaryEvidence.careerFact', 'skillSelections', 'educationSelections', 'projects.bullets.citations']);

        if ($base->jobAnalysis === null || $base->synthesis === null || $base->jobAnalysis->job_posting_id !== $posting->id) {
            throw new ResumeRevisionException(['resume' => 'Only a resume tailored for this job from an approved profile can be revised.']);
        }

        if ($base->targetTermUsages()->exists()) {
            throw new ResumeRevisionException(['resume' => 'This resume carries target-term positioning, which revisions do not support.']);
        }

        $profile = $base->careerProfile;
        $payload = $this->candidates->buildForCareer($profile);
        $facts = collect($payload['career_facts'])->keyBy('key')->all();
        $inputs = $this->inputs->build($profile, $facts, $payload['eligible_skills']);
        $roles = Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profile->id))->with('projects')->get();
        $eligibleByRole = $this->eligible->forRoles($roles, $profile);
        $synthesis = $base->synthesis;
        $effective = EffectiveProfile::resolve($synthesis);
        $excluded = array_column(array_filter($effective['accomplishments'], fn (array $a) => ! $a['included']), 'fact_key');
        $pinned = array_column(array_filter($effective['accomplishments'], fn (array $a) => $a['pinned'] ?? false), 'fact_key');
        $claimGroup = CareerFact::query()->where('career_profile_id', $profile->id)->pluck('claim_group', 'key')->all();

        $errors = [];
        $edits = [];
        $locations = [];
        $newRoles = [];
        $findingsByLocation = [];

        // ---- Experience
        $baseRoles = $base->experienceRoles->sortBy('display_order');
        $draftRoles = collect((array) ($draft['roles'] ?? []))->keyBy(fn (array $r) => (int) ($r['role_id'] ?? 0));
        $total = 0;
        $usedKeys = [];

        // What stays on the resume is registered first, so a replacement is checked against all of it.
        foreach ($baseRoles as $role) {
            $kept = $draftRoles->has($role->role_id) ? array_filter(array_column((array) $draftRoles[$role->role_id]['bullets'], 'id')) : $role->bullets->pluck('id')->all();

            foreach ($role->bullets->whereIn('id', $kept) as $bullet) {
                foreach ($bullet->citations as $citation) {
                    $usedKeys[$citation->careerFact->claim_group ?? $citation->careerFact->key] = true;
                }
            }
        }

        foreach ($baseRoles as $role) {
            $baseBullets = $role->bullets->keyBy('id');
            $entries = $draftRoles->has($role->role_id)
                ? array_values((array) $draftRoles[$role->role_id]['bullets'])
                : $role->bullets->sortBy('display_order')->map(fn (ResumeVariantExperienceBullet $b) => ['id' => $b->id, 'text' => $b->text])->values()->all();

            if ($entries === []) {
                $errors["roles.{$role->role_id}"] = "Keep at least one bullet under {$role->employer_name}.";

                continue;
            }

            $list = [];
            $keptIds = [];

            foreach ($entries as $i => $entry) {
                $field = "roles.{$role->role_id}.bullets.{$i}";
                $text = ResumeText::clean(trim((string) ($entry['text'] ?? '')));
                $id = isset($entry['id']) ? (int) $entry['id'] : null;

                if ($id !== null) {
                    $source = $baseBullets->get($id);

                    if ($source === null) {
                        $errors[$field] = 'That bullet does not belong to this role.';

                        continue;
                    }

                    $keys = $source->citations->map(fn ($c) => $c->careerFact->key)->all();
                    $changed = $text !== trim($source->text);
                    $keptIds[] = $id;
                    $item = ['source' => $source, 'project_id' => $source->project_id, 'keys' => $keys, 'text' => $text, 'changed' => $changed, 'new' => false];
                } else {
                    // One fact (`fact_key`) or several (`fact_keys`): each is held to the same rules, and the text is judged against all of them.
                    $keys = array_values(array_unique(array_map('strval', array_filter((array) ($entry['fact_keys'] ?? [$entry['fact_key'] ?? '']), fn ($k) => $k !== null && $k !== ''))));
                    $projects = [];
                    $refused = null;

                    if ($keys === [] || count($keys) > self::MAX_FACTS_PER_BULLET) {
                        $refused = 'Choose between 1 and '.self::MAX_FACTS_PER_BULLET.' facts for a bullet.';
                    }

                    foreach ($keys as $key) {
                        $fact = $facts[$key] ?? null;

                        if ($fact === null || ! isset($eligibleByRole[$role->role_id][$key]) || in_array($fact['fact_type'], ['capability'], true)) {
                            $refused = 'That fact cannot be used under this role.';

                            break;
                        }

                        if (in_array($key, $excluded, true)) {
                            $refused = 'Your approved profile excludes that fact.';

                            break;
                        }

                        if (($fact['attribution']['project_id'] ?? null) !== null) {
                            $projects[$fact['attribution']['project_id']] = true;
                        }
                    }

                    // A bullet describes one piece of work; facts from different projects would blend two.
                    if ($refused === null && count($projects) > 1) {
                        $refused = 'A bullet can combine facts from one project only.';
                    }

                    if ($refused !== null) {
                        $errors[$field] = $refused;

                        continue;
                    }

                    $item = ['source' => null, 'project_id' => array_key_first($projects), 'keys' => $keys, 'text' => $text, 'changed' => true, 'new' => true, 'replaces' => isset($entry['replaces']) ? (int) $entry['replaces'] : null];
                }

                if ($text === '' || mb_strlen($text) > 700) {
                    $errors[$field] = $text === '' ? 'A bullet cannot be empty.' : 'A bullet should stay under 700 characters.';

                    continue;
                }

                if ($item['new']) {
                    foreach ($item['keys'] as $key) {
                        $marker = $claimGroup[$key] ?? $key;

                        if (isset($usedKeys[$marker])) {
                            $errors[$field] = 'That claim is already on the resume.';
                        }

                        $usedKeys[$marker] = true;
                    }
                }

                $item['field'] = $field;
                $list[] = $item;
            }

            $total += count($list);
            $newRoles[$role->role_id] = ['role' => $role, 'items' => $list];

            $removed = array_values(array_diff($baseBullets->keys()->all(), $keptIds));
            $replaced = array_filter(array_column(array_filter($list, fn (array $it) => $it['new']), 'replaces'));

            $editAt = [];

            foreach ($removed as $removedId) {
                $cited = $baseBullets[$removedId]->citations->map(fn ($c) => $c->careerFact->key)->all();
                $editAt[$removedId] = count($edits);
                $edits[] = [
                    'type' => in_array($removedId, $replaced, true) ? 'bullet_replaced' : 'bullet_removed',
                    'role_id' => $role->role_id, 'bullet_id' => $removedId, 'fact_keys' => $cited,
                    ...(array_intersect($cited, $pinned) !== [] ? ['note' => 'The approved profile pins this evidence.'] : []),
                ];
            }

            foreach ($list as $it) {
                if ($it['new'] && ! in_array($it['replaces'] ?? null, $removed, true)) {
                    $edits[] = ['type' => 'bullet_added', 'role_id' => $role->role_id, 'fact_keys' => $it['keys']];
                } elseif ($it['new']) {
                    $edits[$editAt[$it['replaces']]]['with'] = $it['keys'];
                } elseif ($it['changed']) {
                    $edits[] = ['type' => 'bullet_edited', 'role_id' => $role->role_id, 'bullet_id' => $it['source']->id, 'fact_keys' => $it['keys']];
                }
            }

            $baseOrder = array_values(array_intersect($baseBullets->sortBy('display_order')->keys()->all(), $keptIds));

            if ($baseOrder !== $keptIds) {
                $edits[] = ['type' => 'bullets_reordered', 'role_id' => $role->role_id];
            }
        }

        if ($total > self::MAX_BULLETS) {
            $errors['roles'] = 'A resume this long will not fit two pages: keep it to '.self::MAX_BULLETS.' bullets or fewer.';
        }

        // ---- Summary
        $summary = ResumeText::clean(trim((string) ($draft['summary'] ?? $base->summary)));
        $summaryChanged = $summary !== trim((string) $base->summary);

        if ($summary === '') {
            $errors['summary'] = 'The summary cannot be empty.';
        }

        if ($summaryChanged) {
            $edits[] = ['type' => 'summary_edited'];
        }

        // ---- Skills
        $keepSkills = array_map('intval', (array) ($draft['skills'] ?? $base->skillSelections->sortBy('display_order')->pluck('skill_id')->all()));
        $baseSkills = $base->skillSelections->keyBy('skill_id');

        foreach ($keepSkills as $skillId) {
            if (! $baseSkills->has($skillId)) {
                $errors['skills'] = 'A skill that is not on this resume cannot be added here.';
            }
        }

        foreach ($baseSkills as $skillId => $selection) {
            if (! in_array($skillId, $keepSkills, true)) {
                $edits[] = ['type' => 'skill_removed', 'skill_id' => $skillId, 'name' => $selection->name];
            }
        }

        if ($errors !== []) {
            throw new ResumeRevisionException($errors);
        }

        // ---- Judge the text that changed, by the rules generation uses
        // The facts behind this document, not the ones a parent once rested on: a rewritten summary is judged against, and
        // records as its evidence, only what is still on the resume (see summaryEvidence()).
        $documentKeys = array_values(array_unique(array_merge([], ...array_map(fn (array $r) => array_merge([], ...array_column($r['items'], 'keys')), array_values($newRoles)))));
        $parentSummaryKeys = $base->summaryEvidence->map(fn ($e) => $e->careerFact->key)->all();
        $summaryKeys = $summaryChanged ? $this->summaryEvidence($summary, $documentKeys, $parentSummaryKeys, $facts) : $parentSummaryKeys;
        $byLocation = ['summary' => $summaryKeys];

        foreach ($newRoles as $roleId => $entry) {
            foreach ($entry['items'] as $i => $it) {
                $byLocation["{$roleId}:{$i}"] = $it['keys'];
            }
        }

        $context = $this->inputs->contextFor($inputs, $byLocation);

        if ($summaryChanged) {
            $findingsByLocation['summary'] = $this->validator->textFindings('summary', $summary, 'summary', $context, targetTerms: false, wordCount: false);
        }

        foreach ($newRoles as $roleId => $entry) {
            foreach ($entry['items'] as $i => $it) {
                if ($it['changed']) {
                    $findingsByLocation["{$roleId}:{$i}"] = $this->validator->textFindings($it['field'], $it['text'], "{$roleId}:{$i}", $context, targetTerms: false, wordCount: true);

                    // A new bullet that cites several facts must state each of them, so a citation is never padding for a claim.
                    if ($it['new']) {
                        foreach ($this->validator->unstatedFacts($it['text'], $it['keys'], $context) as $unstated) {
                            $findingsByLocation["{$roleId}:{$i}"][] = ['kind' => 'unstated_fact', 'severity' => 'block', 'detail' => $unstated, 'message' => "cites [{$unstated}] but does not state what it says"];
                        }
                    }
                }
            }
        }

        foreach ($findingsByLocation as $location => $found) {
            $blocking = array_values(array_filter($found, fn (array $f) => $f['severity'] === 'block'));

            if ($blocking !== []) {
                $field = $location === 'summary' ? 'summary' : $newRoles[(int) explode(':', $location)[0]]['items'][(int) explode(':', $location)[1]]['field'];
                $errors[$field] = implode(' ', array_map(fn (array $f) => ucfirst(trim($f['message'])).(str_ends_with($f['message'], '.') ? '' : '.'), $blocking));
            }
        }

        if ($errors !== []) {
            throw new ResumeRevisionException($errors);
        }

        return DB::transaction(function () use ($posting, $base, $profile, $facts, $newRoles, $summary, $summaryChanged, $summaryKeys, $documentKeys, $parentSummaryKeys, $keepSkills, $baseSkills, $edits, $findingsByLocation, $effective, $synthesis) {
            $factIds = CareerFact::query()->where('career_profile_id', $profile->id)->pluck('id', 'key')->all();

            // The job's requirements, re-read deterministically so new bullets carry the requirement they answer.
            $relevance = app(JobRelevance::class)->analyze($base->jobAnalysis, $synthesis->content['inventory']['facts'], $profile);
            $adapted = app(ProfileJobAdapter::class)->adapt($effective, $relevance);
            $validFindingIds = $base->jobAnalysis->findings()->pluck('id')->all();
            $baseGroups = [];

            foreach ((array) ($base->selection_raw_response['experience'] ?? []) as $r) {
                foreach ($r['bullet_groups'] as $g) {
                    $baseGroups[$r['role_id'].':'.$g['order']] = $g;
                }
            }

            $reportItems = [];
            $experience = [];
            $baseItems = collect((array) ($base->review_report['items'] ?? []));

            $variant = ResumeVariant::create([
                'career_profile_id' => $base->career_profile_id,
                'job_match_id' => $base->job_match_id,
                'job_analysis_id' => $base->job_analysis_id,
                'resume_profile_synthesis_id' => $base->resume_profile_synthesis_id,
                'schema_version' => $base->schema_version,
                'selection_prompt_version' => $base->selection_prompt_version,
                'wording_prompt_version' => $base->wording_prompt_version,
                'selection_generated_by' => $base->selection_generated_by,
                'wording_generated_by' => 'manual-edit',
                'generated_at' => now(),
                'selection_input_snapshot' => $base->selection_input_snapshot,
                'selection_raw_response' => $base->selection_raw_response,
                'wording_input_snapshot' => $base->wording_input_snapshot,
                'wording_raw_response' => $base->wording_raw_response,
                'summary' => $summary,
                'review_report' => null,
                'parent_variant_id' => $base->id,
                'edit_log' => null,
            ]);

            foreach ($base->experienceRoles->sortBy('display_order') as $roleRow) {
                $copy = $variant->experienceRoles()->create([
                    'role_id' => $roleRow->role_id, 'employer_name' => $roleRow->employer_name, 'display_title' => $roleRow->display_title,
                    'start_year' => $roleRow->start_year, 'start_month' => $roleRow->start_month, 'end_year' => $roleRow->end_year, 'end_month' => $roleRow->end_month,
                    'display_order' => $roleRow->display_order,
                ]);
                $groups = [];

                foreach ($newRoles[$roleRow->role_id]['items'] as $i => $it) {
                    $order = $i + 1;
                    $bullet = $copy->bullets()->create(['resume_variant_id' => $variant->id, 'project_id' => $it['project_id'], 'display_order' => $order, 'text' => $it['text']]);

                    foreach ($it['keys'] as $key) {
                        $bullet->citations()->create(['career_fact_id' => $factIds[$key]]);
                    }

                    $ids = $it['new']
                        ? array_slice(array_values(array_intersect(array_unique(array_merge([], ...array_map(fn (string $k) => $relevance['facts'][$k]['finding_ids'] ?? [], $it['keys']))), $validFindingIds)), 0, 3)
                        : ($baseGroups[$roleRow->role_id.':'.$it['source']->display_order]['job_analysis_finding_ids'] ?? []);
                    $groups[] = ['order' => $order, 'career_fact_keys' => $it['keys'], 'job_analysis_finding_ids' => $ids];
                    $reportItems[] = $this->reportItem($roleRow, $i, $it, $findingsByLocation["{$roleRow->role_id}:{$i}"] ?? [], $baseItems, $facts);
                }

                $experience[$roleRow->role_id] = $groups;
            }

            // The summary's evidence was settled before the text was judged: unchanged with an unchanged summary, otherwise the
            // facts still on this document (plus any earlier summary-only fact the new text still states).
            foreach ($summaryKeys as $key) {
                $variant->summaryEvidence()->create(['career_fact_id' => $factIds[$key]]);
            }

            foreach ($keepSkills as $order => $skillId) {
                $row = $baseSkills[$skillId];
                $variant->skillSelections()->create(['skill_id' => $skillId, 'name' => $row->name, 'category' => $row->category, 'display_order' => $order]);
            }

            foreach ($base->educationSelections->sortBy('display_order') as $row) {
                $variant->educationSelections()->create($row->only(['education_id', 'institution', 'degree', 'field_of_study', 'start_year', 'end_year', 'display_order']));
            }

            foreach ($base->projects as $project) {
                $copy = $variant->projects()->create($project->only(['project_id', 'name', 'technology_names', 'live_url', 'repository_url', 'display_order']));

                foreach ($project->bullets as $b) {
                    $nb = $copy->bullets()->create($b->only(['display_order', 'text']));

                    foreach ($b->citations as $c) {
                        $nb->citations()->create(['career_fact_id' => $c->career_fact_id]);
                    }
                }
            }

            $summaryFound = $findingsByLocation['summary'] ?? [];
            $summaryRestricted = array_values(array_filter($summaryKeys, fn (string $k) => ($facts[$k]['visibility'] ?? '') === 'restricted' && $this->statesFact($summary, (string) ($facts[$k]['statement'] ?? ''), true)));

            if ($summaryChanged && $summaryRestricted !== []) {
                $summaryFound[] = $this->restrictedSupport($summaryRestricted);
            }

            $summaryItem = $summaryChanged
                ? $this->summaryItem($summary, $summaryFound)
                : ($baseItems->firstWhere('location', 'summary') ?? $this->summaryItem($summary, []));
            $omitted = $baseItems->where('status', 'omitted')->map(fn (array $i) => [...$i, 'label' => $i['label'].' (in the original)'])->values()->all();
            $items = [$summaryItem, ...$reportItems, ...$omitted];
            $counts = array_count_values(array_column($items, 'status')) + ['passed' => 0, 'review' => 0, 'generalized' => 0, 'omitted' => 0];

            // Requirements each bullet answers, and the composition checks, follow the revised content.
            $raw = $base->selection_raw_response;
            // What the revision's own rows say, so the stored response never describes a parent's summary evidence or skills.
            $raw['summary_evidence'] = $summaryKeys;
            $raw['skills'] = array_map(fn (int $skillId, int $order) => ['skill_id' => $skillId, 'order' => $order], $keepSkills, array_keys($keepSkills));
            $raw['experience'] = array_map(fn (array $r) => [...$r, 'bullet_groups' => $experience[$r['role_id']] ?? $r['bullet_groups']], (array) ($raw['experience'] ?? []));
            $snapshot = $base->selection_input_snapshot;
            $content = ['experience' => array_map(fn (array $r) => ['role_id' => $r['role_id'], 'bullet_groups' => $r['bullet_groups']], $raw['experience']), 'summary_evidence' => $summaryKeys];
            $snapshot['tailoring']['composition'] = $this->composition->forJob($content, $facts, $profile, $adapted, $relevance, $base->jobAnalysis);
            $snapshot['revision'] = ['parent_variant_id' => $base->id, 'provenance' => $this->provenance($base, $summaryKeys, $documentKeys, $parentSummaryKeys, $keepSkills, $baseSkills, $facts)];

            $document = $this->documents->generate($variant->fresh());

            try {
                $composed = $this->renderer->compose($variant->fresh());
                $this->pdfValidator->validate($composed->pdf);
            } catch (ResumePdfPageBudgetExceededException $e) {
                throw new ResumeRevisionException(['pdf' => "This resume would run to {$e->pageCount} pages; the limit is {$e->maxPages}. Remove or shorten a bullet and save again."]);
            } catch (InvalidResumePdfException) {
                throw new ResumeRevisionException(['pdf' => 'The revised resume could not be rendered to a verifiable PDF.']);
            }

            $this->store->put($document, $composed->pdf);

            // Finishing the new row's own provenance while it is first being created; an existing snapshot is never changed.
            DB::table('resume_variants')->where('id', $variant->id)->update([
                'selection_raw_response' => json_encode($raw),
                'selection_input_snapshot' => json_encode($snapshot),
                'review_report' => json_encode(['version' => 1, 'counts' => $counts, 'items' => $items]),
                'edit_log' => json_encode(['edits' => $edits, 'layout' => ['pages' => $composed->pageCount, 'mode' => $composed->mode, 'last_page_fill' => $composed->lastPageFill, 'quality' => $this->layoutReview->forVariant($variant->fresh(), $composed)]]),
            ]);

            $this->jobResumes->setCurrent($posting, $variant);

            return $variant->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $it
     * @param  array<int, array<string, mixed>>  $found
     * @param  Collection<int, array<string, mixed>>  $baseItems
     * @param  array<string, array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private function reportItem(ResumeVariantExperienceRole $role, int $index, array $it, array $found, $baseItems, array $facts): array
    {
        $label = "{$role->employer_name}, {$role->display_title}, bullet ".($index + 1);

        $restricted = array_values(array_filter($it['keys'], fn (string $key) => ($facts[$key]['visibility'] ?? '') === 'restricted'));

        // A bullet that was not touched keeps what generation concluded about it.
        if (! $it['new'] && ! $it['changed']) {
            $old = $baseItems->first(fn (array $i) => $i['location'] === 'experience' && $i['role_id'] === $role->role_id && $i['bullet_group_index'] === $it['source']->display_order - 1 && $i['status'] !== 'omitted');

            if ($old !== null && ($restricted === [] || in_array('restricted_support', array_column($old['findings'] ?? [], 'kind'), true))) {
                return [...$old, 'label' => $label, 'bullet_group_index' => $index];
            }
        }

        // Restricted evidence behind a bullet is never shown as approved without a word: the bullet is held for review.
        if ($restricted !== []) {
            $found[] = $this->restrictedSupport($restricted);
        }

        $flagged = array_values(array_filter($found, fn (array $f) => $f['severity'] !== 'block'));

        return [
            'location' => 'experience', 'label' => $label, 'status' => $flagged === [] ? 'passed' : 'review', 'role_id' => $role->role_id, 'bullet_group_index' => $index,
            'project_id' => null, 'needs_verification' => $flagged !== [], 'text' => $flagged === [] ? null : $it['text'],
            'findings' => array_map(fn (array $f) => ['kind' => $f['kind'], 'severity' => $f['severity'], 'detail' => $f['detail'], 'message' => $f['message']], $flagged),
            'action' => null,
            'evidence' => array_map(fn (string $key) => array_filter(['fact_key' => $key, 'visibility' => $facts[$key]['visibility'] ?? null, 'statement' => $flagged === [] ? null : ($facts[$key]['statement'] ?? null)], fn ($v) => $v !== null), $it['keys']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $found
     * @return array<string, mixed>
     */
    private function summaryItem(string $summary, array $found): array
    {
        $flagged = array_values(array_filter($found, fn (array $f) => $f['severity'] !== 'block'));

        return [
            'location' => 'summary', 'label' => 'Summary', 'status' => $flagged === [] ? 'passed' : 'review', 'role_id' => null, 'bullet_group_index' => null, 'project_id' => null,
            'needs_verification' => $flagged !== [], 'text' => $flagged === [] ? null : $summary,
            'findings' => array_map(fn (array $f) => ['kind' => $f['kind'], 'severity' => $f['severity'], 'detail' => $f['detail'], 'message' => $f['message']], $flagged),
            'action' => null, 'evidence' => [],
        ];
    }

    /**
     * The facts a revised summary rests on: every fact behind a bullet still on the resume, plus an earlier summary-only
     * fact (one no bullet cites) only if the new text still states it. A fact that backed the old summary but is neither on
     * the resume nor stated by the new text is history, not support, so it neither authorizes the new summary nor is
     * recorded as its evidence.
     *
     * @param  array<int, string>  $documentKeys
     * @param  array<int, string>  $parentSummaryKeys
     * @param  array<string, array<string, mixed>>  $facts
     * @return array<int, string>
     */
    private function summaryEvidence(string $summary, array $documentKeys, array $parentSummaryKeys, array $facts): array
    {
        $stillStated = array_filter(
            array_diff($parentSummaryKeys, $documentKeys),
            fn (string $key) => isset($facts[$key]) && $this->statesFact($summary, (string) $facts[$key]['statement'], ($facts[$key]['visibility'] ?? '') === 'restricted'),
        );

        return array_values(array_unique([...$documentKeys, ...$stillStated]));
    }

    /**
     * Whether text draws on a fact: it restates at least two, and at least two fifths, of the fact's distinctive words, or a figure it states. A figure
     * only counts for a fact that is not Restricted, because a Restricted-only figure may never be published.
     */
    private function statesFact(string $text, string $statement, bool $restricted): bool
    {
        $words = array_unique(array_filter(preg_split('/[^a-z0-9]+/', strtolower($statement)) ?: [], fn (string $w) => strlen($w) >= 6));
        $present = array_filter($words, fn (string $w) => stripos($text, substr($w, 0, 6)) !== false);

        // Two shared words are not enough: ordinary words ("applications", "developers") recur across unrelated facts, so a fact must be
        // substantially restated, not just brushed against.
        if ((count($present) >= 2 && count($present) / count($words) >= 0.4) || (count($words) === 1 && $present !== [])) {
            return true;
        }

        if ($restricted) {
            return false;
        }

        preg_match_all('/\d[\d,.]*%?/', $statement, $figures);

        return array_filter($figures[0], fn (string $f) => strlen(rtrim($f, '.,')) >= 2 && str_contains($text, rtrim($f, '.,'))) !== [];
    }

    /**
     * @param  array<int, string>  $keys
     * @return array{kind: string, severity: string, detail: string, message: string}
     */
    private function restrictedSupport(array $keys): array
    {
        return ['kind' => 'restricted_support', 'severity' => 'review', 'detail' => implode(', ', $keys), 'message' => 'rests on Restricted evidence ['.implode(', ', $keys).']; a generalized claim is not clearance, so confirm each claim is also backed by Public evidence before sharing'];
    }

    /**
     * What this revision's own rows rest on, kept apart from the parent's generation record that is copied unchanged:
     * the current summary and bullet facts, which of them are Restricted, what the parent rested on that is no longer here,
     * and whether each skill still shown has Public evidence behind it or only Restricted implementation detail.
     *
     * @param  array<int, string>  $summaryKeys
     * @param  array<int, string>  $documentKeys
     * @param  array<int, string>  $parentSummaryKeys
     * @param  array<int, int>  $keepSkills
     * @param  Collection<int, ResumeVariantSkillSelection>  $baseSkills
     * @param  array<string, array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private function provenance(ResumeVariant $base, array $summaryKeys, array $documentKeys, array $parentSummaryKeys, array $keepSkills, $baseSkills, array $facts): array
    {
        $current = array_values(array_unique([...$summaryKeys, ...$documentKeys]));
        $parentBulletKeys = $base->experienceRoles->flatMap(fn (ResumeVariantExperienceRole $r) => $r->bullets->flatMap(fn (ResumeVariantExperienceBullet $b) => $b->citations->map(fn ($c) => $c->careerFact->key)))->all();
        $skills = [];

        foreach ($keepSkills as $skillId) {
            $visibilities = [];

            foreach ($facts as $fact) {
                if (in_array($skillId, array_column($fact['skills'] ?? [], 'id'), true)) {
                    $visibilities[] = $fact['visibility'] ?? '';
                }
            }

            $skills[] = [
                'skill_id' => $skillId, 'name' => $baseSkills[$skillId]->name,
                'support' => in_array('public', $visibilities, true) ? 'public' : (in_array('restricted', $visibilities, true) ? 'restricted_only' : 'unevidenced'),
            ];
        }

        return [
            'summary_fact_keys' => array_values($summaryKeys),
            'bullet_fact_keys' => array_values($documentKeys),
            'restricted_fact_keys' => array_values(array_filter($current, fn (string $k) => ($facts[$k]['visibility'] ?? '') === 'restricted')),
            'dropped_fact_keys' => array_values(array_diff(array_unique([...$parentSummaryKeys, ...$parentBulletKeys]), $current)),
            'skills' => $skills,
            'generation_record' => 'wording_input_snapshot and wording_raw_response are the parent generation record, copied unchanged for history; they do not describe this document.',
        ];
    }
}
