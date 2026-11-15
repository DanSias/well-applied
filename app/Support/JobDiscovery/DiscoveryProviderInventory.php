<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\Providers\EightfoldDiscoveryProvider;
use App\Support\JobDiscovery\Providers\UsajobsDiscoveryProvider;

/**
 * A read-only description of every discovery provider Well Applied knows about: whether it is registered with
 * RunJobDiscovery, whether it has what it needs to run, and how the scheduler treats it today. Manual is not a provider,
 * and the Greenhouse / Lever / Ashby canonical adapters enrich postings rather than discover them, so none of those are
 * listed.
 *
 * It describes existing behaviour and decides nothing. RunJobDiscovery still builds its own provider list and
 * DiscoveryScheduleIsDue still decides what is due; the predicates here mirror theirs and a test holds them in step.
 *
 * Registered and configured are different questions:
 *   - registered: RunJobDiscovery will include the provider in a run. Himalayas, Adzuna and Jobicy always are; Eightfold
 *     and USAJOBS only when active.
 *   - configured: the provider has what it needs to succeed. Adzuna is registered without credentials, and a run then
 *     fails closed, so it reports registered but not configured.
 *
 * Nothing here ever carries a credential, an application id or the USAJOBS registered email address, only whether they
 * are present.
 */
final class DiscoveryProviderInventory
{
    public const SCHEDULE_AUTOMATIC = 'automatic';

    public const SCHEDULE_MANUAL_ONLY = 'manual_only';

    public const SCHEDULE_UNAVAILABLE = 'unavailable';

    /**
     * @return list<JobDiscoverySource>
     */
    public static function sources(): array
    {
        return array_values(array_filter(
            JobDiscoverySource::cases(),
            fn (JobDiscoverySource $source) => $source !== JobDiscoverySource::Manual,
        ));
    }

    /**
     * @return list<array{provider: string, label: string, registered: bool, configured: bool, unavailable_reason: string|null, default_schedule: string, effective_schedule: string, manual_run_available: bool}>
     */
    public function all(): array
    {
        return array_map(fn (JobDiscoverySource $source) => $this->describe($source), self::sources());
    }

    /**
     * @return array{provider: string, label: string, registered: bool, configured: bool, unavailable_reason: string|null, default_schedule: string, effective_schedule: string, manual_run_available: bool}
     */
    public function describe(JobDiscoverySource $source): array
    {
        $registered = self::isRegistered($source);
        $configured = self::isConfigured($source);
        $defaultAutomatic = self::isAutomaticByDefault($source);

        // What the scheduler does today. An Adzuna without credentials is still scheduled (and fails closed each time):
        // that is existing behaviour, reported as it is, and `configured` is what shows the problem.
        $scheduled = in_array($source, DiscoveryScheduleIsDue::scheduledSources(), true);

        return [
            'provider' => $source->value,
            'label' => self::label($source),
            'registered' => $registered,
            'configured' => $configured,
            'unavailable_reason' => $configured ? null : $this->unavailableReason($source),
            'default_schedule' => $defaultAutomatic ? self::SCHEDULE_AUTOMATIC : self::SCHEDULE_MANUAL_ONLY,
            'effective_schedule' => $scheduled ? self::SCHEDULE_AUTOMATIC
                : ($registered && $configured ? self::SCHEDULE_MANUAL_ONLY : self::SCHEDULE_UNAVAILABLE),
            // A manual run executes registered providers only, and one without what it needs would just fail.
            'manual_run_available' => $registered && $configured,
        ];
    }

    public static function label(JobDiscoverySource $source): string
    {
        return $source === JobDiscoverySource::Usajobs ? 'USAJOBS' : ucfirst($source->value);
    }

    /** Mirrors the registration conditions in RunJobDiscovery::__construct. */
    public static function isRegistered(JobDiscoverySource $source): bool
    {
        return match ($source) {
            JobDiscoverySource::Manual => false,
            JobDiscoverySource::Eightfold => EightfoldDiscoveryProvider::isActive(),
            JobDiscoverySource::Usajobs => UsajobsDiscoveryProvider::isActive(),
            default => true,
        };
    }

    public static function isConfigured(JobDiscoverySource $source): bool
    {
        return match ($source) {
            JobDiscoverySource::Manual => false,
            JobDiscoverySource::Adzuna => self::filled(config('services.adzuna.app_id')) && self::filled(config('services.adzuna.app_key')),
            // Registration for these two is exactly "has what it needs".
            JobDiscoverySource::Eightfold, JobDiscoverySource::Usajobs => self::isRegistered($source),
            default => true,
        };
    }

    /** The code-defined default: automatic unless the provider is an opt-in whose `scheduled` flag is off. */
    private static function isAutomaticByDefault(JobDiscoverySource $source): bool
    {
        return match ($source) {
            JobDiscoverySource::Manual => false,
            JobDiscoverySource::Eightfold => config('job_discovery.eightfold.scheduled') === true,
            JobDiscoverySource::Usajobs => config('job_discovery.usajobs.scheduled') === true,
            default => true,
        };
    }

    private function unavailableReason(JobDiscoverySource $source): ?string
    {
        return match ($source) {
            JobDiscoverySource::Adzuna => 'Adzuna credentials are not configured.',
            JobDiscoverySource::Eightfold => config('job_discovery.eightfold.enabled') === true
                ? 'No Eightfold tenants are enabled.'
                : 'Eightfold discovery is switched off.',
            JobDiscoverySource::Usajobs => 'USAJOBS credentials are not configured.',
            default => null,
        };
    }

    private static function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
