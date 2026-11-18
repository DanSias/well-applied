import { useRef, useState } from 'react';
import { PreparationDrawer } from '@/components/preparation/preparation-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useApplicationPreparation } from '@/hooks/use-application-preparation';
import { presentPreparation } from '@/lib/preparation-status';
import type { PreparationPanel } from '@/types/application-preparation';

/**
 * The Opportunity workspace's "Application preparation" section: a one-line status and the button that opens the drawer.
 * It keeps its own state (and polling while an attempt is active) so the badge stays true with the drawer closed. It is
 * a section of its own, beside Application inspection and the recorded Applications: preparation is not either of them.
 */
export function PreparationSection({
    panel: initial,
    title,
    company,
}: {
    panel: PreparationPanel;
    title: string;
    company: string;
}) {
    const prep = useApplicationPreparation(initial);
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
        <section
            id="preparation"
            aria-labelledby="preparation-title"
            className="scroll-mt-4 px-4 py-4 sm:px-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2
                    id="preparation-title"
                    tabIndex={-1}
                    className="text-fg text-sm font-semibold tracking-tight focus:outline-none"
                >
                    Application preparation
                </h2>
                <Badge tone={view.headline.tone}>{view.headline.label}</Badge>
            </div>
            <div className="mt-2">
                <p className="text-fg-muted text-sm">
                    Fills in the application form with your approved details and
                    your tailored resume, then stops for you to review. It never
                    submits anything.
                </p>
                {unavailable && (
                    <p className="text-fg-subtle mt-1 text-xs">
                        {prep.panel.problem?.message}
                    </p>
                )}
                <Button
                    ref={trigger}
                    size="sm"
                    className="mt-2"
                    variant={prep.panel.can_prepare ? 'secondary' : 'secondary'}
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
        </section>
    );
}
