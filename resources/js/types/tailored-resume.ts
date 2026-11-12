import type { LayoutQuality } from '@/types/layout-quality';
/**
 * Matches App\Support\TailoredResume\PresentTailoredResume exactly: the
 * one unified status of "Generate Tailored Resume", used for both the
 * initial page props and the polling endpoint. Stage-level only; there
 * is intentionally no percentage field.
 */
export type TailoredResumeState =
    | 'idle'
    | 'running'
    | 'ready'
    | 'failed'
    | 'needs_clarification';

export type TailoredResumeStageKey = 'analysis' | 'match' | 'resume' | 'pdf';

export type TailoredResumeStage = {
    key: TailoredResumeStageKey;
    label: string;
    status: 'pending' | 'queued' | 'running' | 'done' | 'reused' | 'failed';
    phase: string | null;
    duration_ms: number | null;
};

export type TailoredResumeReviewItem = {
    label: string;
    status: 'review' | 'generalized' | 'omitted';
    needs_verification: boolean;
    text?: string | null;
    original?: string | null;
    action: string | null;
    findings: {
        kind: string;
        severity: string;
        detail: string;
        message: string;
    }[];
    evidence: {
        fact_key: string;
        visibility?: string;
        statement?: string;
    }[];
};

export type ClarificationScope = { value: string; label: string };

export type ClarificationAttributionOption = {
    type: 'employer' | 'role' | 'project';
    id: number;
    label: string;
};

export type EvidenceProposal = {
    id: number;
    kind: 'fact' | 'limitation' | 'preference';
    status: 'proposed' | 'approved' | 'rejected' | 'superseded';
    statement: string;
    scopes: string[];
    attributable_type: 'employer' | 'role' | 'project' | null;
    attributable_id: number | null;
    attribution_label: string | null;
    visibility: 'public' | 'restricted' | 'private';
    verification: 'verified' | 'strongly_supported' | 'needs_confirmation';
    answer_text: string | null;
    career_fact_key: string | null;
    history: { at: string; action: string }[];
};

export type ClarificationQuestion = {
    id: number;
    topic_key: string;
    topic_label: string;
    text: string;
    status: 'open' | 'answered' | 'skipped' | 'covered' | 'retired';
    score: number;
    why: {
        coverage: string | null;
        requirement: string | null;
        strength: string | null;
        emphasis: string | null;
        related_evidence: {
            key: string;
            statement: string;
            visibility: string;
            relationship: string;
        }[];
        /** discovery (new to the record) or deepening (documented, thin). Profile interviews only. */
        kind?: 'discovery' | 'deepening' | null;
        refs?: { type: string; id: number; label: string }[];
        /** Set for Resume Profile interview questions. */
        market?: {
            postings: number;
            sample_postings: number;
            share: number;
            required_share: number;
            preferred_share: number;
        } | null;
    };
    suggested: {
        attributable_type: string | null;
        attributable_id: number | null;
        visibility: string;
    };
    proposal: EvidenceProposal | null;
};

export type Clarification = {
    session_id: number;
    mode: 'standard' | 'deep';
    can_deepen: boolean;
    /** A finished resume already exists; continuing only regenerates if evidence was approved. */
    resume_ready: boolean;
    questions: ClarificationQuestion[];
    attribution_options: ClarificationAttributionOption[];
    scopes: ClarificationScope[];
    limitations: {
        id: number;
        topic: string;
        status: string;
        confirmed_at: string;
    }[];
    preferences: { id: number; topic: string; guidance: string }[];
};

export type TailoredResumeStatus = {
    state: TailoredResumeState;
    label: string;
    run_id: string | null;
    stages: TailoredResumeStage[];
    needs_confirmation: boolean;
    current_stage: TailoredResumeStageKey | null;
    elapsed_seconds: number | null;
    failure: {
        stage: TailoredResumeStageKey;
        stage_label: string;
        category: string | null;
        message: string | null;
        phase: string | null;
        provider_calls: number | null;
        selection_saved: boolean;
    } | null;
    review: {
        counts: Record<'passed' | 'review' | 'generalized' | 'omitted', number>;
        items: TailoredResumeReviewItem[];
    } | null;
    resume_variant_id: number | null;
    preview_url: string | null;
    download_url: string | null;
    layout: {
        pages: number | null;
        mode: string | null;
        last_page_fill: number | null;
        quality?: LayoutQuality | null;
    } | null;
    waiting_for_worker: boolean;
    clarification: Clarification | null;
    evidence_changed: boolean;
    profile_resume: {
        profile_version: number;
        profile_id: number;
        profile_name: string;
        review_url: string | null;
        kind: 'generated' | 'revision' | null;
        label: string | null;
        summary: string | null;
        generated_at: string | null;
        revisions: number;
        preferred: { id: number; is_current: boolean; url: string } | null;
    } | null;
};

export type TailoringProfile = {
    id: number;
    version: number;
    name: string;
    stale: boolean;
    approved_at: string | null;
    recommended: boolean;
};

export type ApprovedEvidenceRevision = {
    revision: number;
    statement: string;
    visibility: string;
    verification: string;
    at: string | null;
    changed: string[];
    current: boolean;
};

export type ApprovedEvidenceFact = {
    fact_id: number;
    fact_key: string;
    topic: string;
    statement: string;
    scopes: string[];
    attributable_type: string | null;
    attributable_id: number | null;
    attribution_label: string | null;
    visibility: 'public' | 'restricted' | 'private';
    verification: 'verified' | 'strongly_supported' | 'needs_confirmation';
    revision: number;
    original_answer: string | null;
    from_this_job: boolean;
    revisions: ApprovedEvidenceRevision[];
    shared_with: { fact_key: string; place: string }[];
};

export type ApprovedEvidence = {
    facts: ApprovedEvidenceFact[];
    limitations: {
        id: number;
        topic: string;
        status: string;
        confirmed_at: string;
    }[];
    preferences: { id: number; topic: string; guidance: string }[];
    attribution_options: ClarificationAttributionOption[];
    scopes: ClarificationScope[];
};
