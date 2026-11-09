import { Badge } from '@/components/ui/badge';
import type { EvidenceSourceKey, Provenance } from '@/types/career-data';

export const SOURCE_LABEL: Record<EvidenceSourceKey, string> = {
    user_confirmed: 'Confirmed by you',
    resume: 'Your resume',
    portfolio: 'Your portfolio',
    repository: 'A code repository',
};

export const PROVENANCE_OPTIONS: { value: string; label: string }[] = [
    { value: '', label: 'Any source' },
    { value: 'confirmed', label: 'Confirmed by you' },
    { value: 'interview', label: 'Added in an interview' },
    { value: 'resume', label: 'From your resume' },
    { value: 'portfolio_repository', label: 'From portfolio or code' },
];

export function formatDate(iso: string): string {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/** One quiet sentence about where a fact came from. */
export function provenanceText(p: Provenance): string {
    const parts: string[] = [];

    if (p.confirmed) {
        parts.push(
            p.confirmed_at
                ? `Confirmed by you ${formatDate(p.confirmed_at)}`
                : 'Confirmed by you',
        );
    }

    if (p.via_interview) {
        parts.push('Added in an interview');
    }

    const others = p.sources
        .filter((s) => s !== 'user_confirmed')
        .map((s) => SOURCE_LABEL[s]);

    if (others.length > 0) {
        parts.push(`Documented in ${others.join(', ').toLowerCase()}`);
    }

    return parts.length > 0
        ? parts.join(' · ')
        : 'No supporting evidence recorded';
}

export function ConfirmedBadge({ p }: { p: Provenance }) {
    return p.confirmed ? <Badge tone="accent">Confirmed by you</Badge> : null;
}
