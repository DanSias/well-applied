<?php

namespace App\Support\ResumeRevision;

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeEmptyOutputException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Exceptions\ResumeRevisionException;
use App\Models\ResumeVariant;
use App\Support\ProfileSynthesis\StoryRole;
use App\Support\ResumeDocument\ComposedResumePdf;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumeText;
use App\Support\ResumeVariant\CompositionChecks;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV4;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one bounded corrective pass after a resume's first PDF has been rendered and measured.
 *
 * It runs only for a resume written for a job from an approved profile that has not itself been revised, and only when
 * LayoutQuality calls the last page UNDERFILLED. It then
 *
 *   1. asks SelectLayoutAdditions which relevant, Public, approved accomplishments are left off (at most a few, by the gap);
 *   2. makes ONE narrow model call that words exactly those accomplishments, from their own facts;
 *   3. refuses a duplicate before anything is saved;
 *   4. saves the original plus those additions as a NEW derived variant through ReviseResumeVariant, which judges every added
 *      bullet by the same claim, technology, visibility and restricted-evidence rules as generation and renders it;
 *   5. keeps the derived variant only if it still fits the same number of pages untrimmed, its last page is meaningfully
 *      fuller without being nearly a page more, and it has no more awkward employer splits than before.
 *
 * Otherwise nothing is kept: the whole attempt runs inside a transaction that is rolled back, so the original variant, its
 * summary, its bullets and its PDF are exactly as they were and no half-made variant exists. There is no retry. Job analysis,
 * selection and the rest of generation are never rerun. The outcome, and the reason, are recorded with the first render's
 * diagnostics.
 */
final class FinishResumeLayout
{
    public function __construct(
        private readonly SelectLayoutAdditions $selector,
        private readonly ReviseResumeVariant $revisions,
        private readonly ResumeCandidatePayloadBuilder $candidates,
        private readonly CompositionChecks $composition,
        private readonly GenerateResumeDocument $documents,
        private readonly ResumePdfStore $store,
        private readonly ProfileJobWordingPromptV4 $prompt,
    ) {}

    /**
     * Whether a correction is warranted, from the first render's judgment alone.
     *
     * @param  array<string, mixed>|null  $quality  LayoutQuality::assess()
     * @return array{request: bool, outcome: string, message: string}
     */
    public function decide(ResumeVariant $variant, ?array $quality): array
    {
        return match (true) {
            ! (bool) config('resume.finishing.enabled') => ['request' => false, 'outcome' => 'disabled', 'message' => 'Layout finishing is switched off.'],
            $variant->job_analysis_id === null || $variant->resume_profile_synthesis_id === null || $variant->parent_variant_id !== null
                || ! str_starts_with((string) $variant->wording_prompt_version, 'resume-wording-profile-job') => ['request' => false, 'outcome' => 'not_eligible', 'message' => 'Only a resume written for a job from an approved profile, and not yet revised, is finished.'],
            ($quality['status'] ?? null) !== 'underfilled' => ['request' => false, 'outcome' => 'not_needed', 'message' => 'The page balance does not need a correction.'],
            default => ['request' => true, 'outcome' => 'requested', 'message' => 'The last page is underfilled.'],
        };
    }

    /**
     * @param  array<string, mixed>  $diagnostics  the first render's diagnostics (mode, pages, last_page_fill, placement, quality)
     * @return array<string, mixed> the same diagnostics, plus `correction` and, when it says something a reader needs, a quality finding
     */
    public function finish(ResumeVariant $variant, ComposedResumePdf $composed, array $diagnostics): array
    {
        $decision = $this->decide($variant, $diagnostics['quality'] ?? null);
        $correction = ['requested' => $decision['request'], 'outcome' => $decision['outcome'], 'message' => $decision['message']];

        if ($decision['request']) {
            $started = hrtime(true);

            try {
                $correction = [...$correction, ...$this->attempt($variant, $composed, $diagnostics)];
            } catch (Throwable $e) {
                report($e);
                $correction = [...$correction, 'outcome' => 'error', 'message' => 'The correction could not be completed, so the resume was left as it was.'];
            }

            $correction['duration_ms'] = (int) round((hrtime(true) - $started) / 1_000_000);
        }

        $diagnostics['correction'] = $correction;
        $finding = $this->finding($correction, $diagnostics);

        if ($finding !== null) {
            $diagnostics['quality']['findings'][] = $finding;
            $diagnostics['quality']['severity'] = array_filter((array) $diagnostics['quality']['findings'], fn (array $f) => $f['severity'] === 'review') !== [] ? 'review' : 'informational';
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    private function attempt(ResumeVariant $variant, ComposedResumePdf $composed, array $diagnostics): array
    {
        $variant->loadMissing(['jobAnalysis.jobPosting', 'careerProfile', 'experienceRoles.bullets.citations.careerFact', 'skillSelections']);
        $posting = $variant->jobAnalysis->jobPosting;
        $needed = (int) ($diagnostics['quality']['room']['bullets_to_balance'] ?? 1);
        $picked = $this->selector->select($posting, $variant, $needed, $composed->placement);
        $before = ['pages' => $composed->pageCount, 'last_page_fill' => $composed->lastPageFill];

        if ($picked['additions'] === []) {
            return [
                'outcome' => 'no_evidence', 'before' => $before, 'considered' => $picked['considered'],
                'message' => 'No further relevant approved evidence can be added, so this is the honest length for this job.',
            ];
        }

        $additions = $picked['additions'];
        $words = $this->word($variant, $additions);

        if (is_string($words)) {
            return ['outcome' => $words, 'before' => $before, 'selected' => $this->summary($additions), 'message' => $this->failureMessage($words)];
        }

        foreach ($additions as $i => &$addition) {
            $addition['text'] = $words[$i];
        }
        unset($addition);

        $duplicate = $this->duplicate($variant, $additions);

        if ($duplicate !== null) {
            return ['outcome' => 'duplicate', 'before' => $before, 'selected' => $this->summary($additions), 'message' => "Not kept: {$duplicate}"];
        }

        DB::beginTransaction();

        try {
            $derived = $this->revisions->revise($posting, $variant, $this->draft($variant, $additions));
        } catch (ResumeRevisionException $e) {
            DB::rollBack();

            return ['outcome' => 'validation_failed', 'before' => $before, 'selected' => $this->summary($additions), 'message' => 'Not kept: an added bullet failed the claim checks ('.implode(' ', $e->errors).')'];
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $after = (array) ($derived->edit_log['layout'] ?? []);
        $verdict = $this->compare($diagnostics, $before, $after);

        if ($verdict !== null) {
            $this->store->forget($this->documents->generate($derived));
            DB::rollBack();

            return ['outcome' => $verdict['outcome'], 'before' => $before, 'after' => $this->measure($after), 'selected' => $this->summary($additions), 'message' => $verdict['message']];
        }

        $correction = [
            'outcome' => 'applied', 'variant_id' => $derived->id, 'before' => $before, 'after' => $this->measure($after), 'selected' => $this->summary($additions),
            'message' => 'Added '.count($additions).' relevant accomplishment'.(count($additions) === 1 ? '' : 's').' as a new version; the original is kept.',
        ];
        DB::table('resume_variants')->where('id', $derived->id)->update([
            'wording_generated_by' => 'layout-correction',
            'edit_log' => json_encode([...(array) $derived->edit_log, 'correction' => ['parent_variant_id' => $variant->id, ...$correction]]),
        ]);
        DB::commit();

        return $correction;
    }

    /**
     * The one model call: wording for exactly the chosen accomplishments. A string is a failure outcome.
     *
     * @param  array<int, array<string, mixed>>  $additions
     * @return array<int, string>|string
     */
    private function word(ResumeVariant $variant, array $additions): array|string
    {
        $bullets = $variant->experienceRoles->flatMap(fn ($r) => $r->bullets->pluck('text'))->all();
        $figures = [];

        foreach ($bullets as $text) {
            preg_match_all('/\$?\d[\d,]*(?:\.\d+)?\+?%?/', $text, $m);
            $figures = [...$figures, ...array_filter($m[0], fn (string $f) => str_contains($f, '%') || strlen(preg_replace('/\D/', '', $f)) >= 2)];
        }

        $input = [
            'job' => ['role' => $variant->jobAnalysis->jobPosting->title, 'summary' => $variant->jobAnalysis->role_summary],
            'additions' => array_map(fn (array $a, int $i) => [
                'id' => $i + 1, 'under_role' => $a['role_title'], 'project' => $a['project'],
                'facts' => array_values(array_map(fn (string $statement) => ['role' => StoryRole::of($statement, $statement === $a['statements'][0]), 'statement' => $statement], (array) $a['statements'])),
            ], $additions, array_keys($additions)),
            'figures_already_on_resume' => array_values(array_unique($figures)),
            'openings_already_used' => array_values(array_unique(array_map(fn (string $t) => strtok(trim($t), ' ') ?: '', $bullets))),
        ];
        $ids = range(1, count($additions));

        try {
            $response = app(GeneratesResumeWording::class)->generate($this->prompt->additionSystemPrompt(), $this->prompt->additionUserPrompt($input), $this->prompt->additionSchema($ids));
        } catch (ResumeEmptyOutputException|ResumeGenerationProviderException) {
            return 'wording_unavailable';
        }

        $byId = [];

        foreach ((array) ($response->structuredContent['bullets'] ?? []) as $b) {
            $text = ResumeText::clean(trim((string) ($b['text'] ?? '')));

            if (isset($b['id']) && $text !== '') {
                $byId[(int) $b['id']] = $text;
            }
        }

        if (array_diff($ids, array_keys($byId)) !== []) {
            return 'wording_incomplete';
        }

        return array_map(fn (int $id) => $byId[$id], $ids);
    }

    /**
     * A finding about the written additions against what is already on the resume, or null when there is none.
     *
     * @param  array<int, array<string, mixed>>  $additions
     */
    private function duplicate(ResumeVariant $variant, array $additions): ?string
    {
        $facts = collect($this->candidates->buildForCareer($variant->careerProfile)['career_facts'])->keyBy('key')->all();
        $bullets = [];

        foreach ($variant->experienceRoles->sortBy('display_order') as $role) {
            foreach ($role->bullets->sortBy('display_order')->values() as $i => $bullet) {
                $bullets[] = ['role_id' => $role->role_id, 'index' => $i, 'text' => $bullet->text, 'fact_keys' => $bullet->citations->map(fn ($c) => $c->careerFact->key)->all()];
            }

            foreach ($additions as $n => $a) {
                if ($a['role_id'] === $role->role_id) {
                    $bullets[] = ['role_id' => $role->role_id, 'index' => 100 + $n, 'text' => $a['text'], 'fact_keys' => $a['fact_keys']];
                }
            }
        }

        foreach ($this->composition->duplicates($bullets, $facts) as $finding) {
            if (array_filter($finding['bullets'], fn (array $b) => $b['index'] >= 100) !== []) {
                return $finding['message'];
            }
        }

        return null;
    }

    /**
     * The draft ReviseResumeVariant expects: the resume as it is, with each addition appended to its role.
     *
     * @param  array<int, array<string, mixed>>  $additions
     * @return array<string, mixed>
     */
    private function draft(ResumeVariant $variant, array $additions): array
    {
        $roles = [];

        foreach ($variant->experienceRoles->sortBy('display_order') as $role) {
            $bullets = [];

            foreach ($role->bullets->sortBy('display_order') as $bullet) {
                $bullets[] = ['id' => $bullet->id, 'text' => $bullet->text];
            }

            foreach ($additions as $a) {
                if ($a['role_id'] === $role->role_id) {
                    $bullets[] = ['fact_keys' => $a['fact_keys'], 'text' => $a['text']];
                }
            }

            $roles[] = ['role_id' => $role->role_id, 'bullets' => $bullets];
        }

        return ['summary' => (string) $variant->summary, 'roles' => $roles, 'skills' => $variant->skillSelections->sortBy('display_order')->pluck('skill_id')->all()];
    }

    /**
     * Why a corrected layout is not worth keeping, or null when it is.
     *
     * @param  array<string, mixed>  $diagnostics
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{outcome: string, message: string}|null
     */
    private function compare(array $diagnostics, array $before, array $after): ?array
    {
        $cfg = (array) config('resume.finishing');
        $pages = (int) ($after['pages'] ?? 0);
        $fill = (float) ($after['last_page_fill'] ?? 0.0);
        $awkward = fn (?array $q) => count(array_filter((array) ($q['findings'] ?? []), fn (array $f) => $f['code'] === 'role_split_awkward'));

        return match (true) {
            $pages !== $before['pages'] || ($after['mode'] ?? null) === 'trimmed' => ['outcome' => 'pages', 'message' => 'Not kept: with the additions the resume no longer fits the same number of pages untrimmed.'],
            $fill > $cfg['max_fill'] => ['outcome' => 'pages', 'message' => 'Not kept: with the additions the last page is nearly full and a small change would spill onto another page.'],
            $fill < ($before['last_page_fill'] ?? 0.0) + $cfg['min_fill_gain'] => ['outcome' => 'not_better', 'message' => 'Not kept: the additions did not make the last page meaningfully fuller.'],
            $awkward($after['quality'] ?? null) > $awkward($diagnostics['quality'] ?? null) => ['outcome' => 'not_better', 'message' => 'Not kept: the additions left an employer split with a single bullet stranded on one side of a page.'],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{pages: int|null, last_page_fill: float|null}
     */
    private function measure(array $layout): array
    {
        return ['pages' => $layout['pages'] ?? null, 'last_page_fill' => $layout['last_page_fill'] ?? null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $additions
     * @return array<int, array<string, mixed>>
     */
    private function summary(array $additions): array
    {
        return array_map(fn (array $a) => ['role_id' => $a['role_id'], 'fact_keys' => $a['fact_keys'], 'relevance' => $a['relevance'], 'reasons' => $a['reasons']], $additions);
    }

    private function failureMessage(string $outcome): string
    {
        return match ($outcome) {
            'wording_unavailable' => 'Not corrected: the model could not word the additions, so the resume was left as it was.',
            default => 'Not corrected: the model did not return a bullet for every addition, so the resume was left as it was.',
        };
    }

    /**
     * What a reader of the layout assessment needs to know about the correction, or null when there is nothing to add.
     *
     * @param  array<string, mixed>  $correction
     * @param  array<string, mixed>  $diagnostics
     * @return array{code: string, severity: string, message: string}|null
     */
    private function finding(array $correction, array $diagnostics): ?array
    {
        $percent = fn (?float $fill) => $fill === null ? '?' : (int) round($fill * 100).'%';

        return match ($correction['outcome']) {
            'applied' => ['code' => 'correction_applied', 'severity' => 'informational', 'message' => $correction['message'].' The last page went from '.$percent($correction['before']['last_page_fill']).' to '.$percent($correction['after']['last_page_fill']).' full.'],
            'validation_failed', 'duplicate', 'pages', 'not_better', 'wording_unavailable', 'wording_incomplete', 'error' => ['code' => 'correction_not_kept', 'severity' => 'review', 'message' => $correction['message'].' The last page is still '.$percent($diagnostics['last_page_fill'] ?? null).' full; add or reword evidence yourself or accept the shorter length.'],
            default => null,
        };
    }
}
