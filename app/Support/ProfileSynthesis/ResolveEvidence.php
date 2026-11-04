<?php

namespace App\Support\ProfileSynthesis;

use App\Models\ResumeProfileSynthesis;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeVariant\ClaimFidelityChecker;

/**
 * Finds the approved facts that support a piece of profile wording, so the user edits language and never assembles a
 * citation list. Deterministic; no model call.
 *
 * Each sentence is read for the claims it makes: the capabilities it names, its figures, the projects and employers it
 * names, the technical approaches it describes, its scale terms and its ownership verbs. A fact is a CANDIDATE for a
 * claim only if it states that claim (a capability the fact states affirmatively, the figure itself, the same name, the
 * same approach). Candidates are ranked, never authorized, by how much a sentence and a fact share. The chosen facts must
 * cover every claim, together may support one sentence, and then everything is judged again by the profile validator and
 * the claim-fidelity check against the combined evidence. Three outcomes:
 *
 *  supported    every claim is covered and nothing fails: save the wording with the resolved facts;
 *  uncertain    candidates exist but support is not established (a sentence with no specific anchor, a scope or cause
 *               the facts might back): nothing is saved, and the most relevant facts are suggested;
 *  unsupported  no approved fact states a claim, or a hard rule fails: nothing is saved, the claim is named, and a
 *               deterministic edit that removes it is offered.
 */
final class ResolveEvidence
{
    /** Technical approaches a sentence can describe; a fact must affirmatively say it too. */
    private const APPROACHES = [
        'deterministic' => '/\bdeterministic\b/i', 'idempotent writes' => '/\bidempoten\w+\b/i', 'evidence-linked design' => '/\bevidence[- ]linked\b/i',
        'SQL-side aggregation' => '/\baggregat\w+\b/i', 'retrieval' => '/\bretrieval\b/i', 'local-first design' => '/\blocal[- ]first\b/i',
        'encryption' => '/\bencrypt\w+\b/i', 'request throttling' => '/\bthrottl\w+\b/i', 'dry-run safety' => '/\bdry[- ]run\b/i',
        'resumable processing' => '/\bresumable\b/i', 'citations' => '/\bcitation\w*\b/i', 'audit trails' => '/\baudit\w*\b/i',
    ];

    /** Scale a fact must state itself; absence of a denial is not enough. */
    private const SCALE = [
        'high-traffic systems' => '/\bhigh[- ]traffic\b/i', 'large-scale systems' => '/\blarge[- ]scale\b/i', 'work at scale' => '/\bat scale\b/i',
        'millions of users or records' => '/\bmillions? of\b/i', 'thousands of users or records' => '/\bthousands of\b/i', 'enterprise scale' => '/\benterprise[- ]scale\b/i',
        'global reach' => '/\bglobal(?:ly)?\b/i', 'massive scale' => '/\bmassive\b/i',
    ];

    private const STOP = ['which', 'their', 'there', 'these', 'those', 'other', 'while', 'where', 'would', 'about', 'after', 'being', 'every', 'using', 'across', 'through', 'within', 'between', 'during', 'could', 'should', 'built', 'platform', 'platforms', 'systems', 'system', 'applications', 'application', 'developer', 'engineering', 'engineer', 'experience', 'full-stack', 'across', 'working', 'delivery', 'implementation', 'requirements'];

    public function __construct(
        private readonly CapabilityCoverage $coverage,
        private readonly ProfilePresentationValidator $validator,
        private readonly ClaimFidelityChecker $fidelity,
    ) {}

    /**
     * @param  array<int, string>  $preferred  the facts already cited for this item; kept when still relevant
     * @return array{status: string, keys: array<int, string>, gaps: array<int, array<string, mixed>>, alternative: string|null, message: string}
     */
    public function resolve(ResumeProfileSynthesis $synthesis, string $itemId, string $text, array $preferred = []): array
    {
        $inventory = $synthesis->content['inventory'];
        $pool = $this->pool($inventory, $itemId);
        $texts = array_map(fn (array $f) => (string) $f['statement'], $pool);
        $sentences = $this->sentences($text);
        $score = array_column($inventory['accomplishments'], 'score', 'fact_key');
        $chosenAll = [];
        $gaps = [];
        $perSentence = [];

        foreach ($sentences as $sentence) {
            $obligations = $this->obligations($sentence, $pool, $inventory);
            [$chosen, $uncovered] = $this->cover($sentence, $obligations, $pool, $preferred, $score);
            $perSentence[] = ['text' => $sentence, 'keys' => $chosen];
            $chosenAll = [...$chosenAll, ...$chosen];

            foreach ($uncovered as $ob) {
                $gaps[] = $this->gap($ob['kind'], $sentence, $ob['label'], "No approved fact states {$ob['label']}.", [], 'unsupported');
            }

            if ($uncovered === [] && ! $this->anchored($sentence, $chosen, $texts)) {
                $gaps[] = $this->gap('anchor', $sentence, $sentence, 'This sentence names things the evidence supports, but nothing in your approved facts says what the sentence itself says.', $this->nearest($sentence, $texts, $chosen), 'uncertain');
            }

            // Scope, cause, role strength and figure precision, against the facts that jointly support this sentence.
            if ($chosen !== []) {
                foreach ($this->fidelity->findings($sentence, $chosen, $texts) as $finding) {
                    $repaired = $this->fidelity->repair($sentence, $finding);
                    $near = in_array($finding['kind'], ['scope_expansion', 'unsupported_cause'], true) ? $this->nearest((string) $finding['detail'], $texts, $chosen) : [];
                    $gaps[] = $this->gap((string) $finding['kind'], $sentence, (string) $finding['detail'], 'This '.$this->plain((string) $finding['kind']).': it '.$finding['message'].'.', $near, $near === [] ? 'unsupported' : 'uncertain', $repaired);
                }
            }
        }

        $keys = array_values(array_unique($chosenAll));

        // The whole item is also judged by the profile validator (employers, Restricted figures, limitations, years, and
        // the capabilities the cited facts jointly state).
        foreach ($this->validate($synthesis, $itemId, $text, $keys) as $error) {
            $hard = preg_match('/not direct experience|no direct experience|confidential|Restricted|years|first person|names .+ but no cited fact|not an eligible/i', $error) === 1;
            $gaps[] = $this->gap('rule', $text, '', $error, [], $hard ? 'unsupported' : 'uncertain');
        }

        $gaps = $this->unique($gaps);
        $status = match (true) {
            array_filter($gaps, fn (array $g) => $g['level'] === 'unsupported') !== [] => 'unsupported',
            $gaps !== [] => 'uncertain',
            default => 'supported',
        };

        return [
            'status' => $status, 'keys' => $status === 'supported' ? $keys : $keys, 'gaps' => $gaps,
            'alternative' => $status === 'unsupported' ? $this->withoutUnsupported($sentences, $gaps) : null,
            'message' => $this->message($status, $gaps),
        ];
    }

    /**
     * The facts the wording may draw on. Summaries and headlines: the whole eligible record, including first-person facts
     * kept as support-only. A project note: its project and the role evidence legitimately around it.
     *
     * @param  array<string, mixed>  $inventory
     * @return array<string, array<string, mixed>>
     */
    private function pool(array $inventory, string $itemId): array
    {
        $facts = $inventory['facts'];

        if (! str_starts_with($itemId, 'note:')) {
            return $facts;
        }

        $projectId = (int) substr($itemId, 5);
        $project = null;

        foreach ((array) $inventory['projects'] as $candidate) {
            if ($candidate['project_id'] === $projectId) {
                $project = $candidate;
            }
        }

        if ($project === null) {
            return [];
        }

        return array_filter($facts, fn (array $f, string $key) => in_array($key, $project['fact_keys'], true)
            || ($project['role_id'] !== null && ($f['role_id'] ?? null) === $project['role_id'] && ($f['project_id'] ?? null) === null && ! ($f['whole_career'] ?? false)), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<int, string>
     */
    private function sentences(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [])));
    }

    /**
     * @param  array<string, array<string, mixed>>  $pool
     * @param  array<string, mixed>  $inventory
     * @return array<int, array{kind: string, label: string, candidates: array<int, string>}>
     */
    private function obligations(string $sentence, array $pool, array $inventory): array
    {
        $obligations = [];
        $keys = array_keys($pool);

        foreach ($this->coverage->capabilitiesIn($sentence) as $capability) {
            $obligations[] = ['kind' => 'capability', 'label' => (string) config("resume_profiles.capabilities.{$capability}.label", $capability), 'candidates' => array_values(array_filter($keys, fn (string $k) => in_array($capability, $pool[$k]['capabilities'], true)))];
        }

        preg_match_all('/\$?\d[\d,]*(?:\.\d+)?\+?%?/', $sentence, $figures);

        foreach (array_unique($figures[0]) as $figure) {
            $core = str_replace(',', '', rtrim($figure, '.'));

            if (strlen(preg_replace('/\D/', '', $core)) >= 2 || preg_match('/[%$+]/', $core) === 1) {
                $obligations[] = ['kind' => 'figure', 'label' => "the figure {$figure}", 'candidates' => array_values(array_filter($keys, fn (string $k) => str_contains(str_replace(',', '', $pool[$k]['statement']), $core)))];
            }
        }

        $names = array_unique([...array_filter(array_column($inventory['projects'], 'name')), ...array_filter(array_column($inventory['facts'], 'employer'))]);

        foreach ($names as $name) {
            if (stripos($sentence, (string) $name) !== false) {
                $obligations[] = ['kind' => 'name', 'label' => $name, 'candidates' => array_values(array_filter($keys, fn (string $k) => ($pool[$k]['project'] ?? null) === $name || ($pool[$k]['employer'] ?? null) === $name || stripos($pool[$k]['statement'], (string) $name) !== false))];
            }
        }

        foreach (self::APPROACHES as $label => $pattern) {
            if (preg_match($pattern, $sentence) === 1) {
                $obligations[] = ['kind' => 'approach', 'label' => $label, 'candidates' => array_values(array_filter($keys, fn (string $k) => $this->coverage->affirms($pattern, $pool[$k]['statement'])))];
            }
        }

        foreach (self::SCALE as $label => $pattern) {
            if (preg_match($pattern, $sentence) === 1) {
                $obligations[] = ['kind' => 'scale', 'label' => $label, 'candidates' => array_values(array_filter($keys, fn (string $k) => $this->coverage->affirms($pattern, $pool[$k]['statement'])))];
            }
        }

        foreach (ClaimFidelityChecker::AUTHORITY as $group => $patterns) {
            if (preg_match($patterns['text'], $sentence, $hit) === 1) {
                $obligations[] = ['kind' => 'ownership', 'label' => 'that you "'.strtolower($hit[0]).'" it', 'candidates' => array_values(array_filter($keys, fn (string $k) => preg_match($patterns['evidence'], $pool[$k]['statement']) === 1))];
            }
        }

        return $obligations;
    }

    /**
     * Covers a sentence's claims. Facts already cited that still cover something are kept first; the rest are chosen by
     * how many open claims they cover, then by the evidence a resume would rather lean on. Several facts may jointly
     * support one sentence. Two facts that say the same thing (a shared claim) are not both cited.
     *
     * @param  array<int, array{kind: string, label: string, candidates: array<int, string>}>  $obligations
     * @param  array<string, array<string, mixed>>  $pool
     * @param  array<int, string>  $preferred
     * @param  array<string, float>  $score
     * @return array{0: array<int, string>, 1: array<int, array{kind: string, label: string, candidates: array<int, string>}>}
     */
    private function cover(string $sentence, array $obligations, array $pool, array $preferred, array $score): array
    {
        $uncovered = array_values(array_filter($obligations, fn (array $o) => $o['candidates'] === []));
        $open = array_values(array_filter($obligations, fn (array $o) => $o['candidates'] !== []));
        $chosen = [];
        $said = [];
        $take = function (string $key) use (&$chosen, &$open, &$said, $pool): void {
            $chosen[] = $key;
            $said[$this->normalize($pool[$key]['statement'])] = true;
            $open = array_values(array_filter($open, fn (array $o) => ! in_array($key, $o['candidates'], true)));
        };

        foreach ($preferred as $key) {
            if (isset($pool[$key]) && ! isset($said[$this->normalize($pool[$key]['statement'])]) && array_filter($open, fn (array $o) => in_array($key, $o['candidates'], true)) !== []) {
                $take($key);
            }
        }

        while ($open !== []) {
            $best = null;
            $bestScore = -1.0;

            foreach (array_unique(array_merge(...array_column($open, 'candidates'))) as $key) {
                if (isset($said[$this->normalize($pool[$key]['statement'])])) {
                    continue;
                }

                $f = $pool[$key];
                $covers = count(array_filter($open, fn (array $o) => in_array($key, $o['candidates'], true)));
                $rank = 10 * $covers + 4 * $this->overlap($sentence, $f['statement'])
                    + (in_array($key, $preferred, true) ? 3 : 0)
                    + (preg_match('/^(?:Independently\s+)?(?:Built|Designed|Implemented|Developed|Integrated|Owned|Created|Optimized|Automated)\b/', $f['statement']) === 1 ? 1.5 : 0)
                    + (($f['project'] ?? null) !== null ? 1 : 0) + ($f['visibility'] === 'public' ? 0.5 : 0) + 0.5 * ($score[$key] ?? 0);

                if ($rank > $bestScore) {
                    $bestScore = $rank;
                    $best = $key;
                }
            }

            if ($best === null) {
                // Every remaining candidate repeats a fact already cited.
                $uncovered = [...$uncovered];
                $open = [];

                break;
            }

            $take($best);
        }

        return [$chosen, $uncovered];
    }

    /**
     * A sentence is anchored when at least one of its facts shares two of the sentence's specific words (not just a
     * technology name): the guard against a capability tag standing in for what a sentence actually says.
     *
     * @param  array<int, string>  $chosen
     * @param  array<string, string>  $texts
     */
    private function anchored(string $sentence, array $chosen, array $texts): bool
    {
        if (count($this->significant($sentence)) < 3) {
            return true;
        }

        foreach ($chosen as $key) {
            if ($this->overlap($sentence, $texts[$key] ?? '') >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $exclude
     * @param  array<string, string>  $texts
     * @return array<int, string>
     */
    private function nearest(string $sentence, array $texts, array $exclude): array
    {
        $ranked = [];

        foreach ($texts as $key => $text) {
            $shared = $this->overlap($sentence, $text);

            if ($shared >= 2 && ! in_array($key, $exclude, true)) {
                $ranked[$key] = $shared;
            }
        }

        arsort($ranked);

        return array_slice(array_keys($ranked), 0, 3);
    }

    private function overlap(string $a, string $b): int
    {
        return count(array_intersect($this->stems($a), $this->stems($b)));
    }

    /**
     * @return array<int, string>
     */
    private function stems(string $text): array
    {
        return array_values(array_unique(array_map(fn (string $w) => substr($w, 0, 6), $this->significant($text))));
    }

    /**
     * @return array<int, string>
     */
    private function significant(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [];

        return array_values(array_filter($words, fn (string $w) => strlen($w) >= 5 && ! in_array($w, self::STOP, true)));
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }

    /**
     * Errors the profile validator finds with this item's wording and the resolved facts.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function validate(ResumeProfileSynthesis $synthesis, string $itemId, string $text, array $keys): array
    {
        $candidate = $synthesis->replicate();
        $edits = $synthesis->edits ?? ['items' => [], 'text' => []];
        $edits['text'][$itemId] = $text;

        if ($itemId !== 'headline') {
            $edits['keys'][$itemId] = $keys === [] ? ($this->currentKeys($synthesis, $itemId)) : $keys;
        }

        $candidate->edits = $edits;
        $candidate->setRelation('careerProfile', $synthesis->careerProfile);
        $label = $this->label($itemId);
        $errors = $this->validator->validate(EffectiveProfile::presentationForValidation(EffectiveProfile::resolve($candidate)), $synthesis->content['inventory'], $synthesis->careerProfile, enforceCounts: false)['errors'];

        // Sentence counts and length are layout rules for the whole summary, enforced when the wording is saved.
        return array_values(array_filter($errors, fn (string $e) => str_starts_with($e, $label) && ! str_contains($e, 'sentences (')));
    }

    /**
     * @return array<int, string>
     */
    private function currentKeys(ResumeProfileSynthesis $synthesis, string $itemId): array
    {
        $p = EffectiveProfile::resolve($synthesis)['presentation'];

        if ($itemId === 'summary') {
            return $p['summary_fact_keys'];
        }

        foreach ([...$p['differentiators'], ...$p['project_notes']] as $row) {
            if ($row['id'] === $itemId) {
                return $row['fact_keys'];
            }
        }

        return [];
    }

    private function label(string $id): string
    {
        return match (true) {
            str_starts_with($id, 'differentiator:') => 'differentiator '.(((int) substr($id, 15)) + 1).':',
            str_starts_with($id, 'note:') => 'project note '.substr($id, 5).':',
            default => $id.':',
        };
    }

    /**
     * @param  array<int, string>  $candidates
     * @return array<string, mixed>
     */
    private function gap(string $kind, string $sentence, string $claim, string $message, array $candidates, string $level, ?string $repaired = null): array
    {
        return ['kind' => $kind, 'level' => $level, 'sentence' => $sentence, 'claim' => $claim, 'message' => $message, 'candidates' => $candidates, 'repaired' => $repaired];
    }

    private function plain(string $kind): string
    {
        return match ($kind) {
            'scope_expansion' => 'widens the scope', 'unsupported_cause' => 'explains a result the facts do not explain',
            'ownership_upgrade' => 'claims a stronger role', default => 'changes a figure',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $gaps
     * @return array<int, array<string, mixed>>
     */
    private function unique(array $gaps): array
    {
        $seen = [];

        return array_values(array_filter($gaps, function (array $g) use (&$seen) {
            $id = $g['kind'].'|'.$g['claim'].'|'.$g['message'];

            $new = ! isset($seen[$id]);
            $seen[$id] = true;

            return $new;
        }));
    }

    /**
     * The wording with every sentence that has an unsupported claim removed, when something worth keeping remains.
     *
     * @param  array<int, string>  $sentences
     * @param  array<int, array<string, mixed>>  $gaps
     */
    private function withoutUnsupported(array $sentences, array $gaps): ?string
    {
        $bad = array_column(array_filter($gaps, fn (array $g) => $g['level'] === 'unsupported' && $g['kind'] !== 'rule'), 'sentence');
        $kept = array_values(array_filter($sentences, fn (string $s) => ! in_array($s, $bad, true)));

        return $kept === [] || count($kept) === count($sentences) ? null : implode(' ', $kept);
    }

    /**
     * @param  array<int, array<string, mixed>>  $gaps
     */
    private function message(string $status, array $gaps): string
    {
        return match ($status) {
            'supported' => 'Supported by your approved career facts.',
            'uncertain' => 'Not saved: support is uncertain. '.($gaps[0]['message'] ?? ''),
            default => 'Not saved: '.($gaps[0]['message'] ?? 'a claim has no support in your approved career facts.'),
        };
    }
}
