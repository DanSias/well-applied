import type {
    ApprovedEvidence,
    Clarification,
    ClarificationAttributionOption,
    ClarificationQuestion,
    ClarificationScope,
} from '@/types/tailored-resume';

export type CoverageStatus =
    | 'directly_supported'
    | 'transferable'
    | 'underdocumented'
    | 'limitation'
    | 'no_evidence';

export type MarketCapability = {
    key: string;
    label: string;
    postings: number;
    share: number;
    required_share: number;
    preferred_share: number;
    coverage: CoverageStatus;
    coverage_label: string;
    weak_postings: number;
    depth: 'deep' | 'moderate' | 'shallow' | null;
    evidence: {
        company: string;
        title: string;
        level: string;
        confidence: 'high' | 'medium' | 'low';
        matched: string | null;
        snippet: string;
    }[];
};

export type MarketGroup = {
    category: string;
    label: string;
    capabilities: MarketCapability[];
};

export type SamplePosting = {
    id: number;
    company: string;
    title: string;
    provider: string;
    source_url: string | null;
    posted_at: string | null;
    seniority: string | null;
    why_included: string;
    basis: 'title' | 'description' | null;
    frontend: string[];
    backend: string[];
    description: { complete: boolean; chars: number };
    reused: boolean;
};

export type ProfileSample = {
    size: number;
    target: number;
    pilot: boolean;
    collected_at: string | null;
    age_days: number | null;
    companies: number;
    providers: Record<string, number>;
    seniority: Record<string, number>;
    posted_between: [string | null, string | null];
    considered: number;
    exclusions: { reason: string; label: string; count: number }[];
    provider_log: {
        provider: string;
        status: string;
        retrieved: number;
        reused: number;
    }[];
    postings: SamplePosting[];
    user_excluded: {
        id: number;
        company: string;
        title: string;
        provider: string;
        detail: string | null;
    }[];
    excluded_examples: {
        company: string;
        title: string;
        provider: string;
        decision: string;
        detail: string | null;
    }[];
};

export type InterviewQuestion = ClarificationQuestion & { group: number };

export type ProfileInterviewState = {
    progress: {
        total: number;
        answered: number;
        skipped: number;
        covered: number;
        open: number;
        groups: {
            index: number;
            question_ids: number[];
            done: number;
            total: number;
        }[];
        current_group: number | null;
        evidence_changed: boolean;
    };
    session_id: number | null;
    questions: InterviewQuestion[];
    attribution_options: ClarificationAttributionOption[];
    scopes: ClarificationScope[];
    limitations: Clarification['limitations'];
    preferences: Clarification['preferences'];
};

export type ResumeProfilePage = {
    profile: {
        slug: string;
        name: string;
        purpose: string | null;
        status: 'draft' | 'sampling' | 'interviewing' | 'ready';
        status_label: string;
        revision: number;
    };
    build: {
        state: 'none' | 'building' | 'failed' | 'ready';
        label: string;
        pilot_available: boolean;
    };
    sample: ProfileSample | null;
    market: MarketGroup[];
    coverage: {
        counts: Partial<Record<CoverageStatus, number>>;
        evidence_changed: boolean;
    };
    interview: ProfileInterviewState;
    contributed: ApprovedEvidence;
};
