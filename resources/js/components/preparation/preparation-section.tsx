import { useRef, useState } from 'react';
import { PreparationDrawer } from '@/components/preparation/preparation-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    WorkflowStep,
    stepStatusFromTone,
} from '@/components/opportunity/workflow-step';
import { useApplicationPreparation } from '@/hooks/use-application-preparation';
import { presentPreparation } from '@/lib/preparation-status';
import type { PreparationPanel } from '@/types/application-preparation';

/**
 * The Opportunity workspace's "Application preparation" section: a one-line status and the button that opens the drawer.
 * It keeps its own state (and polling while an attempt is active) so the badge stays true with the drawer closed. It is
 * a step of its own in the workflow, beside Application inspection and the recorded applications: preparation is not either of them.
 */
export function PreparationSection({
    panel: initial,
    title,
    company,
    blocked = null,
}: {
    panel: PreparationPanel;
    title: string;
    company: string;
    /** Why a new preparation cannot be started (an expired or closed job); null when it can. */
    blocked?: string | null;
}) {
    const prep = useApplicationPreparation(initial, blocked);
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const view = presentPreparation(prep.panel.state);
    const unavailable =
        prep.panel.problem !== null && prep.panel.state === null;

    const close = () => {
        setOpen(false);
        window.setTimeout(() => trigger.current?.focus(), 60);
    };

    return (
        <WorkflowStep
            id="preparation"
            title="Application preparation"
            status={stepStatusFromTone(view.headline.tone)}
            badge={
                <Badge tone={view.headline.tone}>{view.headline.label}</Badge>
            }
        >
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div className="min-w-0 flex-1 basis-60">
                    <p className="text-fg-muted text-sm">
                        Fills the application using your approved details and
                        resume, then stops for your review. It never submits
                        anything.
                    </p>
                    {unavailable && (
                        <p className="text-fg-subtle mt-1 text-xs">
                            {prep.panel.problem?.message}
                        </p>
                    )}
                </div>
                <Button
                    ref={trigger}
                    size="sm"
                    variant="secondary"
                    aria-haspopup="dialog"
                    onClick={() => setOpen(true)}
                >
                    {prep.panel.state?.attempt
                        ? view.active
                            ? 'View progress'
                            : 'View preparation'
                        : unavailable
                          ? 'Why not?'
                          : 'Prepare application'}
                </Button>
            </div>
            {open && (
                <PreparationDrawer
                    prep={prep}
                    title={title}
                    company={company}
                    onClose={close}
                />
            )}
        </WorkflowStep>
    );
}
