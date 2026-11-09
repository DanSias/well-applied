import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/surface';
import type { MetricDetail } from '@/types/career-data';

/** The metric and what it does and does not cover. Codes (unit, comparator) live in the page's Advanced details. */
export default function MetricDisplay({ metric }: { metric: MetricDetail }) {
    return (
        <Card className="p-5">
            <p className="text-fg text-2xl font-semibold tracking-tight">
                {metric.display}
            </p>

            {metric.scope_note && (
                <p className="text-fg-muted mt-3 text-sm leading-relaxed">
                    <span className="text-fg font-medium">Scope: </span>
                    {metric.scope_note}
                </p>
            )}

            {metric.guardrail && (
                <Alert tone="warn" className="mt-3">
                    <span className="font-medium">Do not overstate: </span>
                    {metric.guardrail}
                </Alert>
            )}
        </Card>
    );
}
