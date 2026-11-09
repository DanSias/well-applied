import type { Tone } from '@/components/ui/badge';

export type HomeOverview = {
    opportunities: number;
    not_analyzed: number;
    in_flight: number;
    resumes_in_progress: number;
    resumes_final: number;
};

export type HomeAttentionItem = {
    key: string;
    kind: 'questions' | 'resume' | 'summary';
    title: string;
    company: string | null;
    message: string;
    action: string;
    job_id: number | null;
    resume_variant_id: number | null;
    /** Triage filter to open on the Opportunities page (summary items). */
    filter: 'raw' | 'failed' | null;
};

export type HomeContinueItem = {
    job_id: number;
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
