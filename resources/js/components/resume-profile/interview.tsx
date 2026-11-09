import { router } from '@inertiajs/react';
import { useState } from 'react';
import { QuestionCard } from '@/components/clarification-panel';
import type { QuestionRoutes } from '@/components/clarification-panel';
import {
    answer,
    improveQuestions,
    reopen,
    skip,
} from '@/routes/resume-profiles';
import type { ResumeProfilePage } from '@/types/resume-profile';
import { Alert } from '@/components/ui/alert';
import { buttonClass } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented';
import { Card, Disclosure } from '@/components/ui/surface';

export function WordingPanel({
    slug,
    state,
    original,
    onOriginal,
}: {
    slug: string;
    state: ResumeProfilePage['interview']['enrichment'];
    original: boolean;
    onOriginal: (value: boolean) => void;
}) {
    const [busy, setBusy] = useState(false);
    const running = state.running;

    const improve = () => {
        setBusy(true);
        router.post(
            improveQuestions.url(slug),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="rounded-card border-line bg-surface text-fg-muted mt-3 flex flex-wrap items-center gap-3 border p-4 text-xs">
            <span>
                {state.current > 0
                    ? `${state.current} of ${state.open} open questions use improved wording${state.model ? ` (${state.model}${state.last_at ? `, ${state.last_at.slice(0, 10)}` : ''})` : ''}.`
                    : 'Questions use the standard wording.'}
                {state.pending > 0 &&
                    state.current > 0 &&
                    ` ${state.pending} changed since and use the standard wording.`}
            </span>
            <button
                type="button"
                className={buttonClass('secondary', 'sm')}
                disabled={
                    busy || running || state.pending === 0 || !state.available
                }
                onClick={improve}
                title={
                    state.available
                        ? 'Rewrites the open questions with your local model, once. Nothing runs automatically.'
                        : 'No local model is configured (OLLAMA_BASE_URL).'
                }
            >
                {running
                    ? 'Improving questions...'
                    : state.pending === 0 && state.current > 0
                      ? 'Questions improved'
                      : 'Improve interview questions'}
            </button>
            {state.current > 0 && (
                <label className="flex items-center gap-1.5">
                    <input
                        type="checkbox"
                        checked={original}
                        onChange={(e) => onOriginal(e.target.checked)}
                    />
                    Show the standard wording
                </label>
            )}
        </div>
    );
}

export function Interview({ page }: { page: ResumeProfilePage }) {
    const { interview } = page;
    const slug = page.profile.slug;
    const [original, setOriginal] = useState(false);
    const [group, setGroup] = useState<number | null>(
        interview.progress.current_group ??
            (interview.progress.groups.length > 0 ? 1 : null),
    );

    const routes: QuestionRoutes = {
        answer: (id) => answer.url({ resumeProfile: slug, question: id }),
        skip: (id) => skip.url({ resumeProfile: slug, question: id }),
        reopen: (id) => reopen.url({ resumeProfile: slug, question: id }),
    };

    // Questions carry their own copy of the shared clarification shape the cards expect.
    const clarification = {
        session_id: interview.session_id ?? 0,
        mode: 'deep' as const,
        can_deepen: false,
        resume_ready: false,
        questions: interview.questions,
        attribution_options: interview.attribution_options,
        scopes: interview.scopes,
        limitations: interview.limitations,
        preferences: interview.preferences,
    };

    const { progress } = interview;
    const resolved = progress.total - progress.open;

    if (progress.total === 0) {
        return (
            <Card className="text-fg-muted p-5 text-sm leading-relaxed">
                No questions yet. Nothing in the market sample is missing from
                your record, or the market study has not run.
            </Card>
        );
    }

    const inGroup = interview.questions
        .filter((q) => q.group === group)
        .map((q) => (original ? { ...q, text: q.original_text } : q));
    const open = inGroup.filter((q) => q.status === 'open');
    const handled = inGroup.filter((q) => q.status !== 'open');
    const nextGroup = progress.groups.find(
        (g) => g.index !== group && g.done < g.total,
    );

    const renderCard = (q: (typeof inGroup)[number]) => (
        <QuestionCard
            key={q.id}
            routes={routes}
            question={q}
            clarification={clarification}
        />
    );

    return (
        <div className="space-y-5">
            <Card className="p-5">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p className="text-fg text-sm font-medium">
                        {resolved} of {progress.total} topics handled
                    </p>
                    <p className="text-fg-subtle text-xs">
                        {progress.skipped > 0 &&
                            `${progress.skipped} skipped · `}
                        {progress.covered > 0 &&
                            `${progress.covered} already covered by your record · `}
                        {progress.open} open
                    </p>
                </div>
                <div
                    role="progressbar"
                    aria-label="Interview progress"
                    aria-valuemin={0}
                    aria-valuemax={progress.total}
                    aria-valuenow={resolved}
                    className="bg-sunken mt-3 h-1.5 overflow-hidden rounded-full"
                >
                    <div
                        className="bg-accent h-full rounded-full"
                        style={{
                            width: `${Math.round((resolved / progress.total) * 100)}%`,
                        }}
                    />
                </div>
                <p className="text-fg-subtle mt-3 text-xs">
                    You can stop at any point and pick up here later.
                </p>
            </Card>

            <WordingPanel
                slug={slug}
                state={interview.enrichment}
                original={original}
                onOriginal={setOriginal}
            />

            <SegmentedControl
                label="Interview group"
                value={String(group)}
                onChange={(v) => setGroup(Number(v))}
                items={progress.groups.map((g) => ({
                    value: String(g.index),
                    label: `Group ${g.index} · ${g.done}/${g.total}${g.done === g.total ? ' ✓' : ''}`,
                }))}
            />

            {open.length === 0 ? (
                <Alert tone="ok" role="status">
                    Everything in this group is handled.
                    {nextGroup && (
                        <>
                            {' '}
                            <button
                                type="button"
                                className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                                onClick={() => setGroup(nextGroup.index)}
                            >
                                Go to group {nextGroup.index}
                            </button>
                        </>
                    )}
                </Alert>
            ) : (
                <ol className="space-y-3">{open.map(renderCard)}</ol>
            )}

            {handled.length > 0 && (
                <Card className="px-5 py-3">
                    <Disclosure
                        summary={`Handled in this group (${handled.length})`}
                    >
                        <ol className="mt-1 space-y-3 pb-2">
                            {handled.map(renderCard)}
                        </ol>
                    </Disclosure>
                </Card>
            )}
        </div>
    );
}
