import { Badge } from '@/components/ui/badge';
import type { EvidenceDetail } from '@/types/career-data';
import { SOURCE_LABEL, formatDate } from './provenance';

function EvidenceRow({ evidence }: { evidence: EvidenceDetail }) {
    const label =
        SOURCE_LABEL[evidence.source as keyof typeof SOURCE_LABEL] ??
        evidence.source;

    return (
        <li className="rounded-control border-line bg-canvas/50 border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Badge
                    tone={
                        evidence.source === 'user_confirmed'
                            ? 'accent'
                            : 'neutral'
                    }
                >
                    {label}
                </Badge>
                {evidence.confirmed_at && (
                    <span className="text-fg-subtle text-xs">
                        {formatDate(evidence.confirmed_at)}
                    </span>
                )}
            </div>

            {evidence.quoted_text && (
                <blockquote className="border-line-strong text-fg-muted mt-3 border-l-2 pl-3 leading-relaxed italic">
                    &ldquo;{evidence.quoted_text}&rdquo;
                </blockquote>
            )}

            {evidence.note && (
                <p className="text-fg-muted mt-2 leading-relaxed">
                    {evidence.note}
                </p>
            )}
        </li>
    );
}

export default function EvidenceList({
    evidence,
}: {
    evidence: EvidenceDetail[];
}) {
    if (evidence.length === 0) {
        return (
            <p className="text-fg-subtle text-sm">
                No supporting evidence is recorded for this fact.
            </p>
        );
    }

    return (
        <ul className="space-y-3">
            {evidence.map((item, index) => (
                // Evidence has no stable id of its own in the domain model
                // (see docs/domain-model.md) — position is stable for a
                // given page render.
                <EvidenceRow key={index} evidence={item} />
            ))}
        </ul>
    );
}
