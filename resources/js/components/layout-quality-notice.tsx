import type { LayoutQuality } from '@/types/layout-quality';

/**
 * Says so when a rendered resume has a presentation defect worth a person's attention, such as a last page that is
 * mostly empty, with what the measurement found and, where there is relevant approved evidence left off, which facts
 * could balance it. Shows nothing for a balanced, one-page or merely informational layout, and never blocks anything.
 */
export function LayoutQualityNotice({
    quality,
}: {
    quality: LayoutQuality | null | undefined;
}) {
    if (!quality || quality.severity !== 'review') {
        return null;
    }

    const findings = quality.findings.filter((f) => f.severity === 'review');
    const candidates = quality.room?.candidates ?? [];

    return (
        <div
            role="note"
            className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <p className="font-medium">Layout: {quality.summary}</p>
            <ul className="mt-1 list-disc space-y-1 pl-5">
                {findings.map((f) => (
                    <li key={f.code}>{f.message}</li>
                ))}
            </ul>
            {candidates.length > 0 && (
                <details className="mt-2">
                    <summary className="cursor-pointer text-xs font-medium">
                        Relevant approved facts not on this resume
                    </summary>
                    <ul className="mt-1 space-y-1 text-xs">
                        {candidates.map((c) => (
                            <li key={c.key}>
                                <span className="font-mono">{c.key}</span>
                                {c.project ? ` (${c.project})` : ''}:{' '}
                                {c.statement}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
