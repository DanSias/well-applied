<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Enums\ScheduleOverride;
use App\Models\DiscoveryProviderSetting;
use App\Support\JobDiscovery\Providers\EightfoldDiscoveryProvider;
use App\Support\JobDiscovery\Providers\UsajobsDiscoveryProvider;

/**
 * Every discovery provider Well Applied knows about, and the single authority on how the scheduler treats each one.
 * Manual is not a provider, and the Greenhouse / Lever / Ashby canonical adapters enrich postings rather than discover
 * them, so none of those are listed.
 *
 * Three questions are kept apart:
 *   - registered: RunJobDiscovery will include the provider in a run. Himalayas, Adzuna and Jobicy always are; Eightfold
 *     and USAJOBS only when active.
 *   - configured: the provider has what it needs to succeed. Adzuna is registered without credentials, and a run then
 *     fails closed, so it reports registered but not configured.
 *   - scheduled: the effective schedule, resolved here from the above, the code-defined default and the person's
 *     override (discovery_provider_settings):
 *
 *       not registered or not configured -> unavailable, whatever the override says
 *       override paused                  -> paused
 *       override enabled                 -> automatic
 *       override default / no row        -> the code default (automatic, or manual only for the opt-in providers)
 *
 * An override only ever applies to a provider that is available, so it can never make an unconfigured provider run. It
 * affects the scheduler alone: a manual run (`discovery:run`) still runs every registered provider, paused or not.
 * Scheduling state and run health are independent; nothing here looks at attempts.
 *
 * Nothing here ever carries a credential, an application id or the USAJOBS registered email address, only whether they
 * are present.
 */
final class DiscoveryProviderInventory
{
    public const SCHEDULE_AUTOMATIC = 'automatic';

    public const SCHEDULE_MANUAL_ONLY = 'manual_only';

    public const SCHEDULE_PAUSED = 'paused';

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
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $overrides = self::overrides();

        return array_map(fn (JobDiscoverySource $source) => $this->describe($source, $overrides), self::sources());
    }

    /**
     * The stored overrides by provider value. A provider with no row is absent, which means Default.
     *
     * @return array<string, ScheduleOverride>
     */
    public static function overrides(): array
    {
        $overrides = [];
        foreach (DiscoveryProviderSetting::query()->get() as $setting) {
            $overrides[$setting->provider->value] = $setting->schedule_override;
        }

        return $overrides;
    }

    /**
     * The effective schedule: automatic, paused, manual_only or unavailable.
     *
     * @param  array<string, ScheduleOverride>|null  $overrides  Pass them in when resolving several providers.
     */
    public static function effectiveSchedule(JobDiscoverySource $source, ?array $overrides = null): string
    {
        if (! self::isRegistered($source) || ! self::isConfigured($source)) {
            return self::SCHEDULE_UNAVAILABLE;
        }

        $override = ($overrides ?? self::overrides())[$source->value] ?? ScheduleOverride::Default;

        return match ($override) {
            ScheduleOverride::Paused => self::SCHEDULE_PAUSED,
            ScheduleOverride::Enabled => self::SCHEDULE_AUTOMATIC,
            ScheduleOverride::Default => self::isAutomaticByDefault($source) ? self::SCHEDULE_AUTOMATIC : self::SCHEDULE_MANUAL_ONLY,
        };
    }

    /**
     * The providers the scheduler may run: those whose effective schedule is automatic.
     *
     * @return list<JobDiscoverySource>
     */
    public static function scheduledSources(): array
    {
        $overrides = self::overrides();

        return array_values(array_filter(
            self::sources(),
            fn (JobDiscoverySource $source) => self::effectiveSchedule($source, $overrides) === self::SCHEDULE_AUTOMATIC,
        ));
    }

    /**
     * @param  array<string, ScheduleOverride>|null  $overrides
     * @return array{provider: string, label: string, registered: bool, configured: bool, unavailable_reason: string|null, default_schedule: string, schedule_override: string, effective_schedule: string, schedule_control: array{label: string, override: string}|null, manual_run_available: bool}
     */
    public function describe(JobDiscoverySource $source, ?array $overrides = null): array
    {
        $overrides ??= self::overrides();
        $registered = self::isRegistered($source);
        $configured = self::isConfigured($source);
        $defaultAutomatic = self::isAutomaticByDefault($source);
        $effective = self::effectiveSchedule($source, $overrides);

        return [
            'provider' => $source->value,
            'label' => self::label($source),
            'registered' => $registered,
            'configured' => $configured,
            'unavailable_reason' => $configured ? null : $this->unavailableReason($source),
            'default_schedule' => $defaultAutomatic ? self::SCHEDULE_AUTOMATIC : self::SCHEDULE_MANUAL_ONLY,
            'schedule_override' => ($overrides[$source->value] ?? ScheduleOverride::Default)->value,
            'effective_schedule' => $effective,
            // The one action the person can take, so no caller repeats these rules. Resume restores what pausing took
            // away: the default for an automatic provider, an explicit enable for an opt-in one.
            'schedule_control' => match ($effective) {
                self::SCHEDULE_AUTOMATIC => ['label' => 'Pause scheduling', 'override' => ScheduleOverride::Paused->value],
                self::SCHEDULE_PAUSED => ['label' => 'Resume scheduling', 'override' => ($defaultAutomatic ? ScheduleOverride::Default : ScheduleOverride::Enabled)->value],
                self::SCHEDULE_MANUAL_ONLY => ['label' => 'Enable scheduling', 'override' => ScheduleOverride::Enabled->value],
                default => null,
            },
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
