<?php

namespace App\Console\Commands;

use App\Models\ClarificationAnswer;
use App\Models\EvidenceProposal;
use App\Support\Expansion\ExpandAnswerClaims;
use Illuminate\Console\Command;

class ExpandAnswerCommand extends Command
{
    protected $signature = 'evidence:expand {answer : Clarification answer id} {--force : Ignore the cache}';

    protected $description = 'Read one interview answer into separate proposed claims with the local model (nothing is approved).';

    public function handle(ExpandAnswerClaims $expand): int
    {
        $answer = ClarificationAnswer::query()->findOrFail((int) $this->argument('answer'));
        $expansion = $expand->expand($answer, (bool) $this->option('force'));

        $this->line("Expansion #{$expansion->id}: {$expansion->status}".($expansion->failure !== null ? " ({$expansion->failure})" : '')." model {$expansion->model}, {$expansion->latency_ms}ms, usage ".json_encode($expansion->usage));

        foreach (EvidenceProposal::query()->where('expansion_id', $expansion->id)->orderBy('id')->get()->groupBy('claim_group') as $members) {
            $lead = $members->first();
            $this->line("\n[{$lead->topic_key}] {$lead->statement}\n  scopes: ".implode(',', $lead->scopes)."  confidence: {$lead->confidence}\n  quote: \"{$lead->source_quote}\"\n  why: {$lead->rationale}");

            foreach ($members as $m) {
                $this->line("  -> {$m->attributable_type}:{$m->attributable_id} (suggested {$m->visibility->value})");
            }
        }

        foreach ((array) ($expansion->result['rejected'] ?? []) as $r) {
            $this->line("\nDROPPED: {$r['reason']}: {$r['statement']}");
        }

        foreach ((array) ($expansion->result['followups'] ?? []) as $f) {
            $this->line("\nFOLLOW-UP [{$f['capability']}]: {$f['question']}");
        }

        return $expansion->status === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
