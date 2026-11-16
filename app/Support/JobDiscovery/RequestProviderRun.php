<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Jobs\RunDiscoveryProviderJob;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records a Run Now request for one provider as a queued DiscoveryRun and attempt, then hands it to the queue. It does
 * nothing a worker would do: no provider request is made here. The queued attempt is the durable request; the job turns
 * the same row into the real attempt through RunJobDiscovery (see executeQueued), so there is one history, not two.
 *
 * A request is refused (not queued) when the provider is not registered and configured, or already has a queued or
 * running execution. Two requests at the same moment are serialized by a short cache lock around "check, then record",
 * so exactly one wins. Paused and manual-only providers are fine: Run Now is a manual run.
 */
final class RequestProviderRun
{
    public const QUEUED = 'queued';

    public const ALREADY_ACTIVE = 'already_active';

    public const UNAVAILABLE = 'unavailable';

    public const NOT_QUEUED = 'not_queued';

    public function __construct(
        private readonly DiscoveryProviderInventory $inventory = new DiscoveryProviderInventory,
        private readonly DiscoveryExecutionRecovery $recovery = new DiscoveryExecutionRecovery,
        private readonly ProviderExecutionLeases $leases = new ProviderExecutionLeases,
    ) {}

    /**
     * @return 'queued'|'already_active'|'unavailable'|'not_queued'
     */
    public function request(JobDiscoverySource $source): string
    {
        if (! $this->inventory->describe($source)['manual_run_available']) {
            return self::UNAVAILABLE;
        }

        // A claim abandoned long ago must not block a new request.
        $this->recovery->recover($source);

        // The provider's own execution lease is the mutex: while it is held here no scheduled, CLI or queued execution can
        // start this provider, and a second simultaneous request cannot get it. A scheduled tick that wins the lease first
        // makes this request refuse (the provider is running); one that comes after sees the queued attempt recorded below
        // and defers to it. There is no gap between "check" and "claim" for either ordering to slip through.
        $gate = $this->leases->acquire($source);
        if ($gate === null) {
            return self::ALREADY_ACTIVE;
        }

        try {
            if ($this->recovery->activeAttempt($source) !== null) {
                return self::ALREADY_ACTIVE;
            }

            /** @var DiscoveryProviderAttempt $attempt */
            $attempt = DB::transaction(function () use ($source) {
                $run = DiscoveryRun::create(['status' => DiscoveryStatus::Queued, 'started_at' => now()]);

                // started_at is the request time until a worker starts it (it is reset then), so "latest attempt" ordering
                // stays right.
                return $run->providerAttempts()->create(['provider' => $source, 'status' => DiscoveryStatus::Queued, 'started_at' => now()]);
            });
        } finally {
            $gate->release();
        }

        try {
            RunDiscoveryProviderJob::dispatch($attempt->id);
        } catch (Throwable) {
            // Nothing was queued and nothing ran, so there is nothing to record: drop the request rather than leave a
            // permanent "queued" or invent a failed attempt.
            $runId = $attempt->discovery_run_id;
            $attempt->delete();
            DiscoveryRun::query()->whereKey($runId)->delete();

            return self::NOT_QUEUED;
        }

        return self::QUEUED;
    }
}
