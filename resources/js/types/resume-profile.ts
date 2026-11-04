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

export type InterviewQuestion = ClarificationQuestion & {
    group: number;
    enriched: boolean;
    original_text: string;
};

export type InterviewAnswer = {
    question_id: number;
    answer_id: number;
    expansion: AnswerExpansion;
    topic: string;
    kind: 'discovery' | 'deepening' | null;
    asked: string;
    answer: {
        text: string;
        mode: string;
        at: string | null;
        scopes: string[];
    };
    proposal: {
        id: number;
        kind: 'fact' | 'limitation' | 'preference';
        status: 'proposed' | 'approved' | 'rejected' | 'superseded';
        statement: string;
        scopes: string[];
        attribution: string | null;
        visibility: 'public' | 'restricted' | 'private';
        verification: string;
        fact_key: string | null;
        revision: number;
    } | null;
    separate_claims: { likely: boolean; reasons: string[] };
};

export type QuestionEnrichmentState = {
    open: number;
    current: number;
    pending: number;
    last_at: string | null;
    model: string | null;
    available: boolean;
    running: boolean;
};

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
    enrichment: QuestionEnrichmentState;
    answers: InterviewAnswer[];
    attribution_options: ClarificationAttributionOption[];
    scopes: ClarificationScope[];
    limitations: Clarification['limitations'];
    preferences: Clarification['preferences'];
};

export type ResumeProfilePage = {
    profile: {
        id: number;
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
    consolidation: ConsolidationQueue;
    synthesis: SynthesisState;
    contributed: ApprovedEvidence;
};

export type ClaimMember = {
    id: number;
    status: 'proposed' | 'approved' | 'rejected';
    target: string;
    attribution: string | null;
    visibility: 'public' | 'restricted' | 'private';
    fact_key: string | null;
    revision: number;
    existing: {
        relation: 'same_topic' | 'near_duplicate';
        fact_key: string;
        statement: string;
    } | null;
};

export type ExpandedClaim = {
    group: string;
    capability: string;
    capability_label: string;
    statement: string;
    scopes: string[];
    scope_labels: string[];
    source_quote: string | null;
    confidence: 'high' | 'medium' | 'low' | null;
    rationale: string | null;
    verification: string;
    pending: boolean;
    members: ClaimMember[];
};

export type AnswerExpansion = {
    state: 'none' | 'running' | 'ready' | 'failed';
    can_expand: boolean;
    expansion_id?: number;
    failure?: string | null;
    model?: string | null;
    latency_ms?: number | null;
    usage?: {
        input_tokens: number | null;
        output_tokens: number | null;
    } | null;
    at?: string | null;
    stale?: boolean;
    claims?: ExpandedClaim[];
    dropped?: { statement: string; reason: string }[];
    followups?: {
        index: number;
        capability: string;
        aspect: string | null;
        question: string;
        asked_question_id: number | null;
        asked_status: string | null;
    }[];
};

export type ConsolidationPlace = {
    type: 'career_profile' | 'role' | 'project';
    id: number;
    label: string;
};

export type ConsolidationItem = {
    id: number;
    code: string;
    theme: string;
    action: 'revise' | 'create' | 'tag' | 'advice';
    status: 'proposed' | 'approved' | 'rejected';
    title: string;
    summary: string | null;
    notes: {
        covered_elsewhere?: string;
        caution?: string;
        visibility?: string;
        why?: string;
    };
    original: {
        kind: 'fact' | 'proposal';
        key: string;
        statement: string | null;
        place: ConsolidationPlace | null;
        visibility: string;
        verification: string;
        shared_with?: string[];
        skills?: string[];
        revisions?: number;
        evidence_rows?: number;
        status?: string;
        rationale?: string | null;
    } | null;
    tagged: { key: string; statement: string | null; skills: string[] }[];
    proposed: {
        statement: string | null;
        place: ConsolidationPlace | null;
        visibility: string | null;
        verification: string | null;
        scopes: string[];
    } | null;
    source: {
        answer_id: number;
        topic: string;
        question: string;
        answer: string;
    } | null;
    links: { key: string; statement: string | null; place: string | null }[];
    attribution_change: { from: string; to: string | null } | null;
    skills: string[];
    effect: { kind: string; text: string };
    visibility_effect: string;
    matching: { gains: string[]; loses: string[]; direct: string[] };
    decision: {
        required: boolean;
        question: string;
        options: ConsolidationPlace[];
    } | null;
    applied: Record<string, unknown> | null;
};

export type ConsolidationQueue = {
    items: ConsolidationItem[];
    counts: { proposed: number; approved: number; rejected: number };
    options: ConsolidationPlace[];
};

export type SynthesisFact = {
    statement: string;
    visibility: 'public' | 'restricted';
    employer: string | null;
    role: string | null;
    project: string | null;
    personal: boolean;
    whole_career: boolean;
    shared_with: string[];
    support_only?: boolean;
};

type Decided = {
    id: string;
    included: boolean;
    pinned: boolean;
    edited: boolean;
    rank: number;
};

export type SynthesisProject = Decided & {
    project_id: number;
    name: string;
    employer: string | null;
    role: string | null;
    personal: boolean;
    score: number;
    signals: Record<string, number>;
    reason: string;
    fact_keys: string[];
    capabilities: string[];
    stack: string[];
};

export type SynthesisAccomplishment = Decided & {
    fact_key: string;
    kind: 'outcome' | 'ownership' | 'implementation';
    score: number;
    role_id: number;
    project_id: number | null;
    visibility: string;
    shared_with: string[];
};

export type SynthesisCapability = Decided & {
    key: string;
    label: string;
    category: string;
    depth: string | null;
    market_postings: number;
    market_share: number;
    fact_keys: string[];
};

export type SynthesisProfile = {
    provenance: {
        evidence_revision: number;
        sample: {
            id: number;
            pilot: boolean;
            postings: number;
            companies: number;
            providers: string[];
            newest: string | null;
            oldest: string | null;
            extraction_version: string | null;
        } | null;
    };
    market_priorities: {
        key: string;
        label: string;
        postings: number;
        share: number;
        required: number;
        status: string;
        depth: string | null;
    }[];
    limitations: string[];
    excluded: { fact_key: string; reason: string }[];
    private_omitted: number;
    roles: {
        role_id: number;
        title: string;
        employer: string;
        relevance: number;
    }[];
    facts: Record<string, SynthesisFact>;
    capabilities: {
        direct: SynthesisCapability[];
        transferable: { key: string; label: string; via_fact_keys: string[] }[];
        underdocumented: { key: string; label: string }[];
    };
    projects: SynthesisProject[];
    accomplishments: SynthesisAccomplishment[];
    presentation: {
        headline: string;
        headline_original: string;
        summary: string;
        summary_original: string;
        summary_fact_keys: string[];
        differentiators: (Decided & {
            statement: string;
            original: string;
            fact_keys: string[];
        })[];
        project_notes: {
            id: string;
            project_id: number;
            note: string;
            original: string;
            fact_keys: string[];
        }[];
    };
};

export type SynthesisCandidate = {
    key: string;
    statement: string;
    visibility: 'public' | 'restricted';
    support_only?: boolean;
    place: string;
    capabilities: string[];
};

export type SynthesisVersion = {
    id: number;
    version: number;
    status: 'draft' | 'approved' | 'superseded';
    stale: boolean;
    approved_at: string | null;
};

export type ReferenceState = {
    synthesis_id: number;
    running: boolean;
    state: 'running' | 'ready' | 'failed' | null;
    phase: string | null;
    started_at: string | null;
    finished_at: string | null;
    error: string | null;
    variant_id: number | null;
    preview_url: string | null;
    download_url: string | null;
    duration_ms: number | null;
    wording_ms: number | null;
    model_calls: number | null;
    pages: number | null;
    tokens: Record<string, number | null> | null;
    stale: boolean;
};

export type SynthesisState = {
    building: boolean;
    error: string | null;
    versions: SynthesisVersion[];
    approved_version: number | null;
    synthesis: {
        id: number;
        version: number;
        status: 'draft' | 'approved' | 'superseded';
        stale: boolean;
        outdated: boolean;
        pilot: boolean;
        generated_by: string | null;
        prompt_version: string | null;
        model_calls: number | null;
        duration_ms: number | null;
        notes: string[];
        profile: SynthesisProfile;
    } | null;
    reference: ReferenceState | null;
};
