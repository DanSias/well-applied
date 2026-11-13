import { useEffect, useRef, useState } from 'react';
import {
    DescriptionCompletenessBadge,
    InspectionStateBadge,
    TriageCoverageGroup,
    TriageStageBadge,
} from '@/components/badges';
import {
    DecisionActions,
    DispositionBadge,
    type DecideFn,
} from '@/components/job-decisions';
import { Badge } from '@/components/ui/badge';
import { Toaster } from '@/components/ui/toaster';
import { Button, ButtonAnchor, ButtonLink } from '@/components/ui/button';
import { formatCompensation } from '@/lib/job-display';
import { setToastTarget } from '@/lib/toast';
import { show as showJob } from '@/routes/jobs';
import type { SelectedJob } from '@/types/job-posting';

export type DrawerState = 'loading' | 'ready' | 'unavailable';

const SENIORITY: Record<string, string> = {
    junior: 'Junior',
    mid: 'Mid-level',
    senior: 'Senior',
    staff_or_above: 'Staff or above',
    unspecified: 'Seniority not stated',
};

function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="text-fg text-sm font-semibold tracking-tight">
            {children}
        </h3>
    );
}

/** Placeholder lines while the job is on its way. */
function Loading() {
    return (
        <div
            role="status"
            aria-label="Loading job"
            className="space-y-3 motion-safe:animate-pulse"
        >
            <div className="bg-raised h-3 w-2/3 rounded" />
            <div className="bg-raised h-3 w-full rounded" />
            <div className="bg-raised h-3 w-full rounded" />
            <div className="bg-raised h-3 w-5/6 rounded" />
            <div className="bg-raised h-3 w-3/4 rounded" />
        </div>
    );
}

/**
 * The description first, then the analysis in brief. The description is the posting's verbatim text, shown whole and
 * as text (never as markup), with its own line breaks kept so paragraphs and lists stay readable.
 */
function JobBody({ job }: { job: SelectedJob }) {
    const completeness = job.discovery.description_completeness;

    return (
        <>
            <section aria-labelledby="drawer-description">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3
                        id="drawer-description"
                        className="text-fg text-sm font-semibold tracking-tight"
                    >
                        Job description
                    </h3>
                    {completeness !== 'complete' && (
                        <DescriptionCompletenessBadge value={completeness} />
                    )}
                </div>
                <p className="text-fg pt-2 text-sm leading-relaxed break-words whitespace-pre-wrap">
                    {job.description}
                </p>
            </section>

            <section
                aria-labelledby="drawer-analysis"
                className="border-line mt-6 border-t pt-4"
            >
                <h3
                    id="drawer-analysis"
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    Analysis
                </h3>
                {job.analysis === null ? (
                    <p className="text-fg-muted pt-1.5 text-sm">
                        Not analyzed yet. Open the full workspace to analyze
                        this job.
                    </p>
                ) : (
                    <div className="space-y-3 pt-2 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            {job.analysis.seniority !== null && (
                                <Badge>
                                    {SENIORITY[job.analysis.seniority] ??
                                        job.analysis.seniority}
                                </Badge>
                            )}
                            {job.triage.coverage !== null && (
                                <span className="text-fg-subtle text-xs">
                                    <TriageCoverageGroup
                                        coverage={job.triage.coverage}
                                    />
                                </span>
                            )}
                        </div>
                        <p className="text-fg-muted leading-relaxed">
                            {job.analysis.role_summary}
                        </p>
                        {job.analysis.requirements.length > 0 && (
                            <div>
                                <SectionTitle>Key requirements</SectionTitle>
                                <ul className="text-fg-muted mt-1 list-disc space-y-1 pl-5">
                                    {job.analysis.requirements.map(
                                        (requirement) => (
                                            <li key={requirement}>
                                                {requirement}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        )}
                        {job.analysis.technologies.length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {job.analysis.technologies.map((technology) => (
                                    <Badge key={technology}>{technology}</Badge>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </section>
        </>
    );
}

/**
 * The review drawer: a modal side sheet for reading a job and qualifying it without leaving the list. A native
 * <dialog> opened with showModal(), so the browser traps focus, makes the page behind inert and routes Escape; Escape
 * and a click on the backdrop both go through `onClose`, so the page can keep the URL and history in step. It is a
 * review surface only: analysis, matching, resumes and inspection stay on the job's own page ("Open full workspace").
 */
export function JobDrawer({
    state,
    job,
    fallback,
    decisionsDisabled,
    onDecide,
    onClose,
}: {
    state: DrawerState;
    job: SelectedJob | null;
    /** What the list already knows, to head the drawer while the rest loads. */
    fallback: { id: number; title: string; company: string } | null;
    decisionsDisabled: boolean;
    onDecide: DecideFn;
    onClose: () => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = dialog.current;

        if (element !== null && !element.open) {
            element.showModal();
        }
    }, []);

    // The app-wide toaster sits behind this modal and is inert, so toasts (and their Undo) follow the drawer while it is open.
    useEffect(() => {
        setToastTarget('drawer');

        return () => setToastTarget(undefined);
    }, []);

    const title = job?.title ?? fallback?.title ?? 'Job';
    const company = job?.company ?? fallback?.company ?? '';
    const id = job?.id ?? fallback?.id ?? null;
    const label = `${title}${company ? ` at ${company}` : ''}`;
    const meta = job
        ? [
              job.location,
              job.discovery.remote_status,
              formatCompensation(job.discovery),
              job.discovery.source === 'manual'
                  ? `Captured ${job.captured_at}`
                  : `via ${job.discovery.source.charAt(0).toUpperCase()}${job.discovery.source.slice(1)}`,
          ].filter((item): item is string => Boolean(item))
        : [];

    return (
        <dialog
            ref={dialog}
            aria-labelledby="drawer-title"
            onCancel={(event) => {
                // Escape: let the page decide how to close, so the URL and history stay in step.
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                // A click on the backdrop lands on the dialog element itself, never on its content.
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
            className="job-drawer border-line bg-surface text-fg fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-none w-full max-w-xl flex-col border-l p-0 shadow-xl backdrop:bg-black/55 open:flex"
        >
            <header className="border-line flex items-start justify-between gap-3 border-b px-5 py-4">
                <div className="min-w-0">
                    <h2
                        id="drawer-title"
                        className="text-fg text-base leading-snug font-semibold break-words"
                    >
                        {title}
                    </h2>
                    {company && (
                        <p className="text-fg-muted mt-0.5 text-sm">
                            {company}
                        </p>
                    )}
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Close job"
                    onClick={onClose}
                >
                    ✕
                </Button>
            </header>

            {state === 'ready' && job && (
                <div className="border-line border-b px-5 py-3">
                    {meta.length > 0 && (
                        <p className="text-fg-subtle text-xs leading-relaxed break-words">
                            {meta.join(' · ')}
                        </p>
                    )}
                    <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                        <DispositionBadge disposition={job.disposition} />
                        <TriageStageBadge value={job.triage.stage} />
                        {(job.inspection.state !== 'not_inspected' ||
                            job.inspection.latest_attempt_failed) && (
                            <InspectionStateBadge summary={job.inspection} />
                        )}
                        {job.source_url !== null && (
                            <ButtonAnchor
                                variant="ghost"
                                size="sm"
                                href={job.source_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="-my-1"
                            >
                                Original posting ↗
                            </ButtonAnchor>
                        )}
                    </div>
                </div>
            )}

            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4">
                {state === 'loading' && <Loading />}
                {state === 'unavailable' && (
                    <div role="alert" className="text-fg-muted text-sm">
                        <p className="text-fg font-medium">
                            This job is no longer available.
                        </p>
                        <p className="mt-1">
                            It may have been removed, or the link is not one of
                            your jobs.
                        </p>
                    </div>
                )}
                {state === 'ready' && job && <JobBody job={job} />}
            </div>

            <footer className="border-line bg-surface border-t px-5 py-3">
                <div className="flex flex-wrap items-center gap-x-1 gap-y-2">
                    {state === 'ready' && job && (
                        <DecisionActions
                            disposition={job.disposition.value}
                            jobLabel={label}
                            disabled={decisionsDisabled}
                            onDecide={onDecide}
                        />
                    )}
                    {id !== null && (
                        <ButtonLink
                            className="sm:ml-auto"
                            href={showJob.url(id)}
                        >
                            Open full workspace
                        </ButtonLink>
                    )}
                </div>
            </footer>
            <Toaster id="drawer" inset={88} />
        </dialog>
    );
}
