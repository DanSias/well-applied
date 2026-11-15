<?php

namespace App\Support\Navigation;

use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\JobPosting;
use Illuminate\Http\Request;

/**
 * Which part of the header a page belongs to, decided from the route and, for anything about one job, from that job's
 * disposition: never from the address text, and never from the link that was clicked to get there.
 *
 * - Home, Sources (discovery providers and their filtered candidates), Jobs (the list and adding one), Opportunities (the list and its workspaces) and Applications (the list of
 *   recorded applications) are themselves.
 * - Every page about one job (its page, analysis, match, resumes, and the browser inspection record) belongs to the
 *   workflow stage the job is in: Opportunities while it is pursued, Jobs otherwise. So the preparation pages of a
 *   pursued job keep Opportunities lit, and a job back on the Shortlist is under Jobs again.
 * - Career Data and Resume Profiles belong to the profile menu, not to the job-search workflow.
 */
final class NavigationContext
{
    public const HOME = 'home';

    public const SOURCES = 'sources';

    public const JOBS = 'jobs';

    public const OPPORTUNITIES = 'opportunities';

    public const APPLICATIONS = 'applications';

    public const PROFILE = 'profile';

    /**
     * @return array{section: string|null}
     */
    public static function for(Request $request): array
    {
        $route = $request->route();
        $name = $route?->getName() ?? '';

        $section = match (true) {
            $name === 'home' => self::HOME,
            // Sources is where discovery is observed; its filtered-candidates list is a drill-down of it.
            str_starts_with($name, 'sources.') || str_starts_with($name, 'discovery.') => self::SOURCES,
            $name === 'applications.index' => self::APPLICATIONS,
            str_starts_with($name, 'opportunities.') => self::OPPORTUNITIES,
            str_starts_with($name, 'career-data.') || str_starts_with($name, 'resume-profiles.') => self::PROFILE,
            default => self::forJobContext($route?->parameter('jobPosting'), $route?->parameter('application'), $name),
        };

        return ['section' => $section];
    }

    private static function forJobContext(mixed $job, mixed $inspection, string $routeName): ?string
    {
        if ($inspection instanceof Application) {
            $job = $inspection->jobPosting;
        }

        if ($job instanceof JobPosting) {
            return $job->disposition === JobDisposition::Pursued ? self::OPPORTUNITIES : self::JOBS;
        }

        return str_starts_with($routeName, 'jobs.') ? self::JOBS : null;
    }
}
