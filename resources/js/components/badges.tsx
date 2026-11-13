import type { InspectionSummary } from '@/types/application-inspection';
import { TONE_STYLES } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type {
    SkillCategory,
    Verification,
    Visibility,
} from '@/types/career-data';
import type {
    DescriptionCompleteness,
    OpportunityTriageCoverage,
    OpportunityTriageStage,
} from '@/types/job-posting';

const badgeClass =
    'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap';

const VERIFICATION_STYLES: Record<Verification, string> = {
    verified: TONE_STYLES.ok,
    strongly_supported: TONE_STYLES.accent,
    needs_confirmation: TONE_STYLES.warn,
};

const VERIFICATION_LABELS: Record<Verification, string> = {
    verified: 'Verified',
    strongly_supported: 'Strongly supported',
    needs_confirmation: 'Needs confirmation',
};

/** Trust dimension — deliberately styled with no relation to VisibilityBadge, so the two are never confused for one another. */
export function VerificationBadge({ value }: { value: Verification }) {
    return (
        <span className={cn(badgeClass, VERIFICATION_STYLES[value])}>
            {VERIFICATION_LABELS[value]}
        </span>
    );
}

const VISIBILITY_STYLES: Record<Visibility, string> = {
    public: TONE_STYLES.neutral,
    restricted: TONE_STYLES.warn,
    private: TONE_STYLES.bad,
};

const VISIBILITY_LABELS: Record<Visibility, string> = {
    public: 'Public',
    restricted: 'Restricted',
    private: 'Private',
};

/** Confidentiality dimension — a fact can be Verified AND Restricted at once; nothing here implies the other. */
export function VisibilityBadge({ value }: { value: Visibility }) {
    return (
        <span className={cn(badgeClass, VISIBILITY_STYLES[value])}>
            {VISIBILITY_LABELS[value]}
        </span>
    );
}

const CATEGORY_LABELS: Record<SkillCategory, string> = {
    build_technology: 'Build technology',
    platform_integration: 'Platform integration',
    capability: 'Capability',
    practice: 'Practice',
};

export function skillCategoryLabel(value: SkillCategory): string {
    return CATEGORY_LABELS[value];
}

export function SkillCategoryBadge({ value }: { value: SkillCategory }) {
    return (
        <span className={cn(badgeClass, TONE_STYLES.accent)}>
            {CATEGORY_LABELS[value]}
        </span>
    );
}

const INSPECTION_STATE_STYLES: Record<InspectionSummary['state'], string> = {
    not_inspected: TONE_STYLES.neutral,
    queued: TONE_STYLES.accent,
    running: TONE_STYLES.accent,
    inspected: TONE_STYLES.ok,
    failed: TONE_STYLES.bad,
    unsupported: TONE_STYLES.warn,
};

const DESCRIPTION_COMPLETENESS_STYLES: Record<DescriptionCompleteness, string> =
    {
        complete: TONE_STYLES.ok,
        // Noticeable (it affects downstream analysis quality) but
        // deliberately not the rose/error styling used elsewhere on this
        // page — a preview isn't a failure.
        preview: TONE_STYLES.warn,
        // Calm/neutral on purpose — unknown is not an error state.
        unknown: TONE_STYLES.neutral,
    };

const DESCRIPTION_COMPLETENESS_LABELS: Record<DescriptionCompleteness, string> =
    {
        complete: 'Complete description',
        preview: 'Preview only',
        unknown: 'Description completeness unknown',
    };

/** Whether a JobPosting's stored description is the complete posting, a short preview, or unestablished — see App\Enums\DescriptionCompleteness. */
export function DescriptionCompletenessBadge({
    value,
}: {
    value: DescriptionCompleteness;
}) {
    return (
        <span
            className={cn(badgeClass, DESCRIPTION_COMPLETENESS_STYLES[value])}
        >
            {DESCRIPTION_COMPLETENESS_LABELS[value]}
        </span>
    );
}

/** One-line status label for the Opportunities index/detail — see App\Support\ApplicationInspection\SummarizeInspectionStates. */
export function InspectionStateBadge({
    summary,
}: {
    summary: InspectionSummary;
}) {
    const label = (() => {
        switch (summary.state) {
            case 'not_inspected':
                return 'Not inspected';
            case 'queued':
                return 'Inspection queued';
            case 'running':
                return 'Inspecting…';
            case 'inspected':
                return `Inspected — ${summary.field_count ?? 0} question${summary.field_count === 1 ? '' : 's'}`;
            case 'failed':
                return 'Inspection failed';
            case 'unsupported':
                return 'Unsupported ATS';
        }
    })();

    return (
        <span className="inline-flex flex-col gap-0.5">
            <span
                className={cn(
                    badgeClass,
                    INSPECTION_STATE_STYLES[summary.state],
                )}
            >
                {label}
            </span>
            {summary.latest_attempt_failed && (
                <span className="text-bad text-xs">Latest attempt failed</span>
            )}
        </span>
    );
}

/**
 * Pipeline position of an opportunity in Opportunity Triage (raw → … →
 * matched). A workflow/process state, deliberately reusing this repository's
 * established treatment for workflow stages from App\Support\OpportunityTriage
 * \ SummarizeTriageStates: sky = in flight, emerald = a step completed and
 * produced a result (matching the `inspected` convention), rose = a step
 * failed, neutral = not yet started. None of these imply candidate fit or
 * whether the opportunity is worth pursuing.
 */
const TRIAGE_STAGE_STYLES: Record<OpportunityTriageStage, string> = {
    // Not started — calm/neutral on purpose, not an error.
    raw: TONE_STYLES.neutral,
    // In flight — the repo's established in-progress treatment.
    analyzing: TONE_STYLES.accent,
    // Analysis complete and produced a snapshot — same "completed step"
    // emerald as `inspected`, signaling a finished step, not quality.
    analyzed: TONE_STYLES.ok,
    // In flight — the repo's established in-progress treatment.
    matching: TONE_STYLES.accent,
    // Pipeline completed successfully — emerald "workflow done", never "good
    // match" or "recommended opportunity".
    matched: TONE_STYLES.ok,
    // A step failed — the repo's established failure treatment.
    'analysis-failed': TONE_STYLES.bad,
    'match-failed': TONE_STYLES.bad,
};

const TRIAGE_STAGE_LABELS: Record<OpportunityTriageStage, string> = {
    raw: 'Not analyzed',
    analyzing: 'Analyzing',
    'analysis-failed': 'Analysis failed',
    analyzed: 'Analyzed',
    matching: 'Matching',
    'match-failed': 'Match failed',
    matched: 'Matched',
};

export function TriageStageBadge({ value }: { value: OpportunityTriageStage }) {
    return (
        <span className={cn(badgeClass, TRIAGE_STAGE_STYLES[value])}>
            {TRIAGE_STAGE_LABELS[value]}
        </span>
    );
}

/**
 * The four evidence-coverage counts for a `matched` opportunity. Factual
 * counts of findings by classification — deliberately a single neutral
 * treatment across all four, with explicit text labels so color never
 * carries the meaning. Not a score, percentage, rank, or recommendation.
 */
const COVERAGE_ITEMS: {
    key: keyof OpportunityTriageCoverage;
    label: string;
}[] = [
    { key: 'supported', label: 'Supported' },
    { key: 'partial', label: 'Partial' },
    { key: 'no_evidence', label: 'No evidence' },
    { key: 'not_assessable', label: 'Not assessable' },
];

export function TriageCoverageGroup({
    coverage,
}: {
    coverage: OpportunityTriageCoverage;
}) {
    return (
        <span className="text-fg-subtle inline-flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs">
            {COVERAGE_ITEMS.map((item) => (
                <span
                    key={item.key}
                    className="inline-flex items-baseline gap-1 whitespace-nowrap"
                >
                    {item.label}
                    <span className="text-fg-muted font-medium tabular-nums">
                        {coverage[item.key]}
                    </span>
                </span>
            ))}
        </span>
    );
}
