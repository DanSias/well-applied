import type { LayoutQuality } from '@/types/layout-quality';
export type ResumeCareerFactSummary = {
    key: string;
    statement: string;
    visibility: string;
    attribution: {
        employer: string | null;
        role: string | null;
        project: string | null;
    };
};

export type ResumeTargetTermUsage = {
    term: string;
    posture: 'direct' | 'qualified' | 'capability';
    relationship_phrase: string | null;
    job_analysis_finding_statement: string;
    evidence: ResumeCareerFactSummary[];
};

export type ResumeBullet = {
    id: number;
    project: string | null;
    text: string;
    citations: ResumeCareerFactSummary[];
    target_term_usages: ResumeTargetTermUsage[];
    /** Profile-assisted tailoring: the job requirements this bullet answers. */
    answers?: string[];
};

export type ResumeRoleGroup = {
    role_id: number;
    employer: string;
    display_title: string | null;
    start_year: number;
    start_month: number | null;
    end_year: number | null;
    end_month: number | null;
    bullets: ResumeBullet[];
};

export type ResumeSkill = {
    id: number;
    name: string;
    category: string;
};

export type ResumeEducation = {
    institution: string;
    degree: string;
    field_of_study: string | null;
    start_year: number | null;
    end_year: number | null;
};

export type ResumeVariantDetail = {
    id: number;
    generated_at: string | null;
    selection_generated_by: string | null;
    wording_generated_by: string | null;
    schema_version: string;
    selection_prompt_version: string;
    wording_prompt_version: string;
    summary: string | null;
    summary_evidence: ResumeCareerFactSummary[];
    summary_target_term_usages: ResumeTargetTermUsage[];
    experience: ResumeRoleGroup[];
    skills: ResumeSkill[];
    education: ResumeEducation[];
};

export type ResumeVariantJobContext = {
    /** The job is pursued, so its home is the Opportunity workspace rather than the job page. */
    pursued?: boolean;
    /** Where "back to the job" leads: the Opportunity workspace for a pursued job, the job page otherwise. */
    home_url?: string;
    id: number;
    company: string;
    title: string;
};

export type ResumeVariantAnalysisContext = {
    id: number;
};

export type ResumeVariantMatchContext = {
    id: number;
};

export type ResumeTailoringReview = {
    profile_version: number | null;
    analysis_prompt_version: string | null;
    requirements: {
        id: number;
        label: string;
        category: string;
        weight: number;
        statement: string;
    }[];
    gaps: {
        label: string;
        status: string;
        finding: string;
        required: boolean;
    }[];
    added: { key: string; statement: string }[];
    dropped: { key: string; statement: string }[];
    composition: ResumeCompositionFinding[];
};

/** A whole-resume quality finding: advice for a person, never an automatic change. */
export type ResumeCompositionFinding = {
    check: 'engineering_evidence' | 'result_context' | 'narrative_consistency' | 'duplicate_accomplishment' | 'story_dimensions';
    severity: 'blocking' | 'review' | 'informational';
    message: string;
    selected: { key: string; statement: string }[];
    available: { key: string; statement: string }[];
};

export type WorkspaceFact = {
    key: string;
    statement: string;
    visibility: string;
};

export type WorkspaceAttention = {
    source: 'wording' | 'composition';
    severity: 'review' | 'informational' | 'blocking';
    status: string;
    title: string;
    role_id: number | null;
    bullet_order: number | null;
    messages: string[];
    claim: string | null;
    facts: WorkspaceFact[];
    available: WorkspaceFact[];
};

export type WorkspaceHistoryItem = {
    id: number;
    kind: 'generated' | 'revision';
    label: string;
    parent_id: number | null;
    created_at: string;
    summary: string;
    current: boolean;
    preferred: boolean;
};

export type ResumeWorkspace = {
    variant: {
        id: number;
        kind: 'generated' | 'revision';
        label: string;
        parent_id: number | null;
        generated_at: string;
        current: boolean;
        preferred: boolean;
        pages: number | null;
        layout_quality: LayoutQuality | null;
        profile: { name: string; version: number } | null;
        editable: boolean;
    };
    history: WorkspaceHistoryItem[];
    attention: WorkspaceAttention[];
    edit_log: { label: string; note: string | null }[];
};

export type ReplacementFact = {
    key: string;
    statement: string;
    visibility: string;
    type: string;
    project: string | null;
    suggested: boolean;
    reason: string | null;
};

export type ResumeVariantShowProps = {
    job: ResumeVariantJobContext;
    analysis: ResumeVariantAnalysisContext;
    match: ResumeVariantMatchContext | null;
    resume: ResumeVariantDetail;
    tailoring?: ResumeTailoringReview | null;
    workspace?: ResumeWorkspace;
    readiness?: ReadinessState;
};

export type ReadinessTarget =
    | { type: 'summary' }
    | { type: 'skills' }
    | { type: 'bullet'; bullet_id: number }
    | { type: 'role_fact'; role_id: number; fact_key: string }
    | null;

export type ReadinessFactRef = {
    key: string;
    statement: string;
    visibility?: string;
    role_id?: number;
    role?: string | null;
};

export type ReadinessGap = {
    status:
        | 'in_resume'
        | 'omitted_available'
        | 'transferable'
        | 'not_established';
    qualification_gap: boolean;
    capabilities: string[];
    candidates: ReadinessFactRef[];
    adjacent: ReadinessFactRef[];
    reason: string;
};

export type ReadinessRequirement = {
    id: string;
    text: string;
    priority: 'critical' | 'important' | 'nice';
    coverage: 'strong' | 'partial' | 'missing';
    depth: 'built' | 'supported' | 'adjacent' | 'none';
    passage_ids: string[];
    finding_ids: number[];
    note: string;
    gap: ReadinessGap | null;
};

export type ReadinessResult = {
    prompt_version: string;
    hiring_argument: {
        rating: 'persuasive' | 'adequate' | 'weak';
        summary: string;
    };
    emphasis: { over: string[]; under: string[] };
    counts: { strong: number; partial: number; missing: number };
    requirements: ReadinessRequirement[];
    improvements: {
        kind: 'add_evidence' | 'engineering_evidence' | 'edit';
        title: string;
        advice: string;
        target: ReadinessTarget;
        requirement_ids: string[];
        facts: { key: string; statement: string }[];
    }[];
    gaps: {
        requirement_id: string;
        text: string;
        priority: string;
        status: string;
        reason: string;
        adjacent: ReadinessFactRef[];
    }[];
    terms: {
        capability: string;
        status: string;
        candidates: ReadinessFactRef[];
    }[];
    document: {
        pages: number;
        text_chars: number;
        findings: {
            kind: 'defect' | 'suggestion';
            code: string;
            message: string;
        }[];
    };
    risks: {
        composition: { check: string; severity: string; message: string }[];
        wording: { label: string; status: string; messages: string[] }[];
        adjustments: string[];
    };
    passages: Record<string, { label: string; bullet_id: number | null }>;
    artifact: { document_key: string; pdf_sha256: string };
};

export type ReadinessState = {
    state: 'none' | 'queued' | 'running' | 'failed' | 'stale' | 'ready';
    review_id?: number;
    requested_at?: string;
    started_at?: string | null;
    phase?: string | null;
    message?: string | null;
    stale_reasons?: string[];
    reviewed_at?: string | null;
    model?: string | null;
    metrics?: {
        duration_ms?: number;
        document_ms?: number;
        model_ms?: number;
        classification_ms?: number;
        prompt_tokens?: number | null;
        completion_tokens?: number | null;
        reasoning_tokens?: number | null;
        reasoning_effort?: string;
        output_budget?: number;
    } | null;
    fingerprints?: {
        description: string;
        analysis: string | null;
        artifact: string | null;
        prompt: string;
    };
    result?: ReadinessResult;
};
