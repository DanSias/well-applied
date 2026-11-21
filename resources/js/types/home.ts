import type { Tone } from '@/components/ui/badge';

export type HomeOverview = {
    jobs: number;
    awaiting: number;
    shortlisted: number;
    pursued: number;
    not_analyzed: number;
    in_flight: number;
    resumes_in_progress: number;
    resumes_final: number;
    existing_work_to_review: number;
    /** Present only with job freshness on: the jobs currently actionable, and those left out as expired or closed (`jobs` stays the total). */
    actionable?: number;
    left_out?: number;
};

export type HomeAttentionItem = {
    key: string;
    kind: 'questions' | 'resume' | 'summary' | 'review';
    title: string;
    company: string | null;
    message: string;
    action: string;
    job_id: number | null;
    /** The job's default destination (its Opportunity workspace when pursued); null for summary items. */
    job_url: string | null;
    resume_variant_id: number | null;
    /** Triage filter to open on the Opportunities page (summary items). */
    filter: 'raw' | 'failed' | null;
};

export type HomeContinueItem = {
    job_id: number;
    job_url: string;
    title: string;
    company: string;
    status: { label: string; tone: Tone };
    activity_at: string | null;
    resume_variant_id: number | null;
    action: string;
};

export type HomeProps = {
    has_profile: boolean;
    overview: HomeOverview;
    attention: { total: number; items: HomeAttentionItem[] };
    continue: HomeContinueItem[];
    quick_access: { resume_profiles: number };
    discovery: HomeDiscovery;
};

export type DiscoveryProviderHealth = {
    provider: string;
    label: string;
    /** Whether the hourly `discovery:run --due` schedule includes this provider. */
    scheduled: boolean;
    /** The effective schedule, including a person's pause or enable. */
    schedule: 'automatic' | 'paused' | 'manual_only' | 'unavailable';
    /** Scheduled, and its last success is missing or older than twice the daily cadence. */
    stale: boolean;
    last_success: {
        /** `partial` means some of the provider's work failed but the rest was kept. */
        status: 'succeeded' | 'partial';
        partial_summary: string | null;
        at: string | null;
        age_hours: number | null;
        retrieved: number | null;
        accepted: number | null;
        created: number | null;
        updated: number | null;
        /** Null when the attempt did not record filter counts (older attempts, or a stage that was not observed). */
        filtered: number | null;
        filtered_by_reason: { code: string; label: string; count: number }[];
        /** `after_prefilter` providers report only what survived their own prefilter, so counts are not comparable. */
        retrieved_basis: 'feed' | 'after_prefilter';
        normalization_dropped: number | null;
    } | null;
    latest: {
        status: string;
        at: string | null;
        failure: string | null;
        is_last_success: boolean;
    } | null;
};

export type HomeDiscovery = {
    providers: DiscoveryProviderHealth[];
    active_rejections: number;
    stale: boolean;
    ever_ran: boolean;
};
