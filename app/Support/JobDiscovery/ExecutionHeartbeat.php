<?php

namespace App\Support\JobDiscovery;

use App\Models\DiscoveryProviderAttempt;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Keeps the running provider's lease and its attempt's `updated_at` fresh while it works, so "silent for a lease" really
 * means abandoned. It beats on every outgoing HTTP request (through a single global request middleware registered once
 * per application, so no provider's own request code changes) and once per candidate.
 *
 * Only one provider executes at a time in a process, so there is one current holder; outside an execution the
 * middleware does nothing.
 */
final class ExecutionHeartbeat
{
    private ?ExecutionLease $lease = null;

    private ?DiscoveryProviderAttempt $attempt = null;

    private bool $registered = false;

    public function start(ExecutionLease $lease, DiscoveryProviderAttempt $attempt): void
    {
        $this->register();
        $this->lease = $lease;
        $this->attempt = $attempt;
    }

    public function stop(): void
    {
        $this->lease = null;
        $this->attempt = null;
    }

    public function beat(): void
    {
        if ($this->lease === null || $this->attempt === null) {
            return;
        }

        $this->lease->renew();
        DiscoveryProviderAttempt::query()->whereKey($this->attempt->id)->update(['updated_at' => now()]);
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
