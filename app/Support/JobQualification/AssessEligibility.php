<?php

namespace App\Support\JobQualification;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobRemoteStatus;
use App\Enums\QualificationEligibility;
use App\Models\JobPosting;
use App\Support\JobAnalysis\SegmentJobPostingDescription;
use App\Support\JobDiscovery\LocationSignals;

/**
 * Conservative, explainable eligibility checks. A job is only Ineligible on explicit evidence that is reliable: a
 * provider-stated on-site or hybrid location outside the home state, a sentence that restricts the role to a place the
 * candidate is not in, or a required security clearance the profile gives no sign of holding. Anything ambiguous is
 * Unknown, and a posting too thin to judge is InsufficientInformation; neither is a rejection. Every reason keeps the
 * field value or sentence it came from.
 *
 * Precedence: Ineligible > InsufficientInformation > Unknown > Eligible. Explicit structured evidence (a provider field)
 * stays reliable even when the description is thin, so it is not hidden behind the insufficiency.
 */
final class AssessEligibility
{
    private const US_STATES = [
        'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Georgia', 'Hawaii',
        'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts',
        'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey',
        'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania',
        'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington',
        'West Virginia', 'Wisconsin', 'Wyoming',
    ];

    /** A sentence that ties the role to where the person must live or be based. */
    private const RESIDENCY_PHRASE = '/\b(?:must\s+(?:be\s+)?(?:located|based|reside|live|resident)|(?:reside|residents?|live|located|based|living)\s+(?:in|within|of)|hiring\s+(?:only\s+)?in|eligible\s+states?|open\s+to\s+candidates\s+in|available\s+(?:only\s+)?in)\b/i';

    private const RESTRICTION_MARKER = '/\b(?:must|only|required|requires|eligible|exclusively|restricted|hiring)\b/i';

    public function __construct(private readonly SegmentJobPostingDescription $segmenter = new SegmentJobPostingDescription) {}

    public function assess(JobPosting $job, bool $candidateHasClearanceEvidence = false): EligibilityAssessment
    {
        $reasons = [
            ...$this->contentReasons($job),
            ...$this->structuredLocationReasons($job),
            ...$this->describedLocationReasons($job),
            ...$this->clearanceReasons($job, $candidateHasClearanceEvidence),
        ];

        $has = fn (string $severity) => array_filter($reasons, fn (array $r) => $r['severity'] === $severity) !== [];

        $outcome = match (true) {
            $has('ineligible') => QualificationEligibility::Ineligible,
            $has('insufficient') => QualificationEligibility::InsufficientInformation,
            $has('unknown') => QualificationEligibility::Unknown,
            default => QualificationEligibility::Eligible,
        };

        return new EligibilityAssessment($outcome, $reasons);
    }

    /**
     * @return list<array{code: string, severity: string, message: string, evidence: string|null}>
     */
    private function contentReasons(JobPosting $job): array
    {
        $length = mb_strlen(trim($job->description));

        if ($job->description_completeness === DescriptionCompleteness::Preview) {
            return [$this->reason('preview_only', 'insufficient', 'Only a preview of the description is available, which is not enough to judge the role reliably.', "description_completeness: preview ({$length} characters)")];
        }

        if ($length < QualificationPolicy::MIN_DESCRIPTION_CHARS) {
            return [$this->reason('description_too_short', 'insufficient', 'The description is too short to judge the role reliably.', "{$length} characters")];
        }

        return [];
    }

    /**
     * What the provider said about the workplace, which is more reliable than prose.
     *
     * @return list<array{code: string, severity: string, message: string, evidence: string|null}>
     */
    private function structuredLocationReasons(JobPosting $job): array
    {
        $location = trim((string) $job->location);
        $inHomeState = $location !== '' && $this->mentionsHomeState($location);

        if ($job->remote_status === JobRemoteStatus::Onsite || $job->remote_status === JobRemoteStatus::Hybrid) {
            $kind = $job->remote_status->value;
            $evidence = "remote_status: {$kind}".($location !== '' ? "; location: {$location}" : '');

            if ($location === '') {
                return [$this->reason('workplace_location_unclear', 'unknown', "The role is {$kind} but no location is given.", $evidence)];
            }

            return $inHomeState
                ? [$this->reason('workplace_in_home_state', 'unknown', "The role is {$kind}, not remote; it is in Florida, so it is worth a look.", $evidence)]
                : [$this->reason('workplace_outside_home_state', 'ineligible', "The role is {$kind} outside Florida.", $evidence)];
        }

        if ($job->remote_status === JobRemoteStatus::Remote) {
            if ($location !== '' && $this->mentionsNonUsPlace($location) && ! $this->mentionsUs($location)) {
                return [$this->reason('remote_region_unclear', 'unknown', 'The role is remote, but its listed location points outside the US.', "location: {$location}")];
            }

            return [];
        }

        // No provider-stated workplace: only an explicit US-remote location reads as eligible.
        if ($location !== '' && preg_match('/\bremote\b/i', $location) === 1 && $this->mentionsUs($location)) {
            return [];
        }

        return [$this->reason('remote_status_unknown', 'unknown', 'The posting does not say whether the role is remote.', $location !== '' ? "location: {$location}" : 'no location or remote status given')];
    }

    /**
     * Sentences of the description that restrict where the person must live.
     *
     * @return list<array{code: string, severity: string, message: string, evidence: string|null}>
     */
    private function describedLocationReasons(JobPosting $job): array
    {
        $reasons = [];

        foreach ($this->segmenter->segment($job->description) as $sentence) {
            if (preg_match(self::RESIDENCY_PHRASE, $sentence) !== 1) {
                continue;
            }

            if ($this->mentionsUs($sentence) || $this->mentionsHomeState($sentence)) {
                continue;
            }

            // A sentence only restricts when it says so: "our engineers are based in Germany" describes the team, not a rule.
            if (preg_match(self::RESTRICTION_MARKER, $sentence) !== 1) {
                continue;
            }

            if ($this->mentionsNonUsPlace($sentence)) {
                $reasons[] = $this->reason('location_restricted_outside_us', 'ineligible', 'The posting restricts the role to a place outside the US.', $this->trim($sentence));

                continue;
            }

            if ($this->mentionsOtherState($sentence)) {
                $reasons[] = $this->reason('location_restricted_to_other_states', 'ineligible', 'The posting restricts the role to states that do not include Florida.', $this->trim($sentence));
            }
        }

        return array_values(array_unique($reasons, SORT_REGULAR));
    }

    /**
     * @return list<array{code: string, severity: string, message: string, evidence: string|null}>
     */
    private function clearanceReasons(JobPosting $job, bool $candidateHasClearanceEvidence): array
    {
        $reasons = [];

        foreach ($this->segmenter->segment($job->description) as $sentence) {
            if (preg_match('/\bclearance\b/i', $sentence) !== 1) {
                continue;
            }

            if (preg_match('/\b(?:preferred|a plus|nice to have|desirable|bonus|beneficial)\b/i', $sentence) === 1) {
                continue;
            }

            if (preg_match('/\b(?:ability|able|eligible|eligibility|willing|willingness|can|capable)\b[^.]{0,40}\b(?:obtain|get|receive|be granted|qualify)/i', $sentence) === 1) {
                $reasons[] = $this->reason('clearance_obtainable', 'unknown', 'The role may require obtaining a security clearance.', $this->trim($sentence));

                continue;
            }

            if (preg_match('/\b(?:require[sd]?|must|active|current|currently|hold|possess|have|existing)\b/i', $sentence) !== 1) {
                continue;
            }

            $reasons[] = $candidateHasClearanceEvidence
                ? $this->reason('clearance_required_profile_mentions', 'unknown', 'The role requires a security clearance, and the profile mentions one; confirm it.', $this->trim($sentence))
                : $this->reason('clearance_required', 'ineligible', 'The role requires a security clearance, and nothing in the profile substantiates one.', $this->trim($sentence));
        }

        return array_values(array_unique($reasons, SORT_REGULAR));
    }

    private function mentionsHomeState(string $text): bool
    {
        return LocationSignals::mentionsFlorida($text);
    }

    private function mentionsUs(string $text): bool
    {
        return LocationSignals::mentionsUs($text);
    }

    private function mentionsNonUsPlace(string $text): bool
    {
        return LocationSignals::mentionsNonUsPlace($text);
    }

    private function mentionsOtherState(string $text): bool
    {
        foreach (self::US_STATES as $state) {
            if (preg_match('/\b'.preg_quote($state, '/').'\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function trim(string $sentence): string
    {
        return mb_strlen($sentence) > 240 ? mb_substr($sentence, 0, 237).'...' : $sentence;
    }

    /**
     * @return array{code: string, severity: string, message: string, evidence: string|null}
     */
    private function reason(string $code, string $severity, string $message, ?string $evidence): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message, 'evidence' => $evidence];
    }
}
