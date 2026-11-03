<?php

namespace App\Console\Commands;

use App\Models\EvidenceExpansion;
use App\Models\EvidenceProposal;
use App\Support\Expansion\ExpansionRegrounding;
use Illuminate\Console\Command;

class RegroundExpansionCommand extends Command
{
    protected $signature = 'evidence:reground {--dry-run : Report only}';

    protected $description = 'Apply the attribution grounding rules to undecided expansion claims (no model call). Approved facts are never touched.';

    public function handle(ExpansionRegrounding $reground): int
    {
        $ids = EvidenceProposal::query()->whereNotNull('expansion_id')->where('status', 'proposed')->distinct()->pluck('expansion_id');

        foreach (EvidenceExpansion::query()->whereIn('id', $ids)->get() as $expansion) {
            foreach ($reground->apply($expansion, (bool) $this->option('dry-run')) as $r) {
                $this->line("\n".substr($r['group'], 0, 8).': '.$r['statement']);
                $this->line('  before: '.implode('; ', $r['before']));
                $this->line('  kept:   '.implode('; ', array_map(fn ($l) => $l, $r['kept'])).'  ['.implode(',', array_unique($r['basis'])).']');
                $this->line('  left out: '.($r['dropped'] === [] ? 'none' : implode('; ', $r['dropped'])));
            }
        }

        return self::SUCCESS;
    }
}
