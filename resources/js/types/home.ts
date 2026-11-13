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
};
