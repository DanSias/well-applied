export type DiscoveryRejectionRow = {
    id: number;
    title: string;
    company: string;
    provider: string;
    reason: { code: string; label: string };
    matched_term: string | null;
    matched_field: string | null;
    evidence: string | null;
    location: string | null;
    remote_status: string | null;
    source_url: string | null;
    first_seen_at: string;
    last_seen_at: string;
    seen_count: number;
};

export type DiscoveryRejectionsProps = {
    rejections: DiscoveryRejectionRow[];
    filters: { q: string; reason: string; provider: string | null };
    providers: { id: string; label: string }[];
    reasons: { code: string; label: string }[];
    pagination: {
        page: number;
        per_page: number;
        total: number;
        last_page: number;
        from: number;
        to: number;
    };
};
