import { Head } from '@inertiajs/react';
import AppShell from '@/layouts/app-shell';

/** The Opportunity workspace; the full layout is built up in the next commit. */
export default function OpportunityShow({
    opportunity,
}: {
    opportunity: { title: string; company: string };
}) {
    return (
        <AppShell>
            <Head title={`${opportunity.title} at ${opportunity.company}`} />
            <h1 className="text-fg text-xl font-semibold tracking-tight">
                {opportunity.title}
            </h1>
            <p className="text-fg-muted mt-1 text-sm">{opportunity.company}</p>
        </AppShell>
    );
}
