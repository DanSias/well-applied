<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryProviderAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * The execution's ownership checkpoint. It runs before every outgoing HTTP request (through a single global request
 * middleware registered once per application, so no provider's own request code changes), before every candidate, right
 * after retrieval and once more before the outcome is written. Each time it:
 *
 *   1. renews the provider lease for a full lease again, which fails if the lease expired or was taken by another owner;
 *      that is "ownership lost" and throws LeaseLostException, and the loss is sticky, so a provider or adapter that
 *      swallows the exception still cannot get a later checkpoint to pass;
 *   2. throws ExecutionDeadlineException once a queued run has used its time budget;
 *   3. touches the attempt's `updated_at`, so "silent for a whole lease" means abandoned.
 *
 * A request already in flight when ownership is lost cannot be recalled; its response is discarded because the next
 * checkpoint (the first candidate, or the final check) stops the execution before anything is processed or written.
 * Only one provider executes at a time in a process, so there is one current holder; outside an execution the
 * middleware does nothing.
 */
final class ExecutionHeartbeat
{
    private ?ExecutionLease $lease = null;

    private ?DiscoveryProviderAttempt $attempt = null;

    private ?CarbonInterface $deadline = null;

    private bool $lost = false;

    private int $beats = 0;

    private bool $registered = false;

    public function start(ExecutionLease $lease, DiscoveryProviderAttempt $attempt, ?CarbonInterface $deadline = null): void
    {
        $this->register();
        $this->lease = $lease;
        $this->attempt = $attempt;
        $this->deadline = $deadline;
        $this->lost = false;
    }

    public function stop(): void
    {
        $this->lease = null;
        $this->attempt = null;
        $this->deadline = null;
    }

    /** Whether this execution lost its lease. Stays true until the next start(). */
    public function lost(): bool
    {
        return $this->lost;
    }

    /** How many checkpoints have run in this process (for tests of the transport paths). */
    public function beats(): int
    {
        return $this->beats;
    }

    /**
     * @throws LeaseLostException
     * @throws ExecutionDeadlineException
     */
    public function beat(): void
    {
        if ($this->lease === null || $this->attempt === null) {
            return;
        }

        $this->beats++;

        if ($this->lost || ! $this->lease->renew()) {
            $this->lost = true;

            throw new LeaseLostException;
        }

        if ($this->deadline !== null && now()->greaterThanOrEqualTo($this->deadline)) {
            throw new ExecutionDeadlineException;
        }

        DiscoveryProviderAttempt::query()->whereKey($this->attempt->id)->where('status', DiscoveryStatus::Running)->update(['updated_at' => now()]);
    }

    private function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            $this->beat();

            return $request;
        });
    }
}
