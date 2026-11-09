import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { approve, reject, update } from '@/routes/evidence-consolidations';
import type {
    ConsolidationItem,
    ConsolidationPlace,
    ConsolidationQueue,
} from '@/types/resume-profile';
import { buttonClass } from '@/components/ui/button';
import { fieldClass } from '@/components/ui/field';
import { cn } from '@/lib/utils';

type Errors = Record<string, string>;

const field = cn(fieldClass, 'mt-1 w-full text-sm');
const primary = buttonClass('primary', 'sm');
const secondary = buttonClass('secondary', 'sm');

const EFFECT: Record<string, string> = {
    replaces: 'Replaces wording',
    creates: 'Creates a fact',
    groups: 'Groups with a shared claim',
    tags: 'Tags skills',
    rejects: 'Rejects a pending proposal',
};

const placeKey = (p: ConsolidationPlace | null) =>
    p === null ? '' : `${p.type}:${p.id}`;

function Facts({ item }: { item: ConsolidationItem }) {
    const o = item.original;

    if (item.action === 'tag') {
        return (
            <ul className="mt-1 space-y-1 text-xs">
                {item.tagged.map((t) => (
                    <li key={t.key}>
                        <span className="font-mono">{t.key}</span>
                        <span className="text-neutral-500">
                            {' '}
                            current skills:{' '}
                            {t.skills.length > 0 ? t.skills.join(', ') : 'none'}
                        </span>
                    </li>
                ))}
            </ul>
        );
    }

    if (o === null) {
        return (
            <p className="mt-1 text-xs text-neutral-500">
                No existing fact: this creates a new one from your answer.
            </p>
        );
    }

    return (
        <div className="mt-1 space-y-1 text-xs">
            <p className="text-sm whitespace-pre-wrap">
                {o.statement ?? 'Private: not shown here.'}
            </p>
            <p className="text-neutral-500">
                {o.kind === 'fact' ? o.key : o.key}
                {o.place ? ` · ${o.place.label}` : ''} · {o.visibility} ·{' '}
                {o.verification.replace('_', ' ')}
                {o.revisions ? ` · ${o.revisions} revision(s) kept` : ''}
            </p>
            {o.shared_with && o.shared_with.length > 0 && (
                <p className="text-neutral-500">
                    Shared claim with {o.shared_with.join(', ')}
                </p>
            )}
            {o.rationale && (
                <p className="text-neutral-500">Why: {o.rationale}</p>
            )}
        </div>
    );
}

function ItemCard({
    item,
    options,
}: {
    item: ConsolidationItem;
    options: ConsolidationPlace[];
}) {
    const open = item.status === 'proposed';
    const editable = item.action === 'revise' || item.action === 'create';
    const [statement, setStatement] = useState(item.proposed?.statement ?? '');
    const [place, setPlace] = useState(placeKey(item.proposed?.place ?? null));
    const [visibility, setVisibility] = useState(
        item.proposed?.visibility ?? 'restricted',
    );
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);
    const [details, setDetails] = useState(item.decision !== null && open);

    // The candidates the plan named, the place it currently has, and the whole career; never a blank.
    const placeOptions = [
        ...(item.decision?.options ?? []),
        ...(item.proposed?.place ? [item.proposed.place] : []),
        ...options.filter((o) => o.type === 'career_profile'),
    ].filter(
        (o, i, all) => all.findIndex((x) => placeKey(x) === placeKey(o)) === i,
    );
    const choices = item.decision === null ? options : placeOptions;

    const body = (): RequestPayload => {
        const data: RequestPayload = {};

        if (!editable) {
            return data;
        }

        data.statement = statement;
        data.visibility = visibility;

        if (place !== '') {
            const [type, id] = place.split(':');
            data.attributable_type = type;
            data.attributable_id = Number(id);
        }

        return data;
    };

    const send = (action: 'approve' | 'reject' | 'save') => {
        setBusy(true);
        const route =
            action === 'approve'
                ? approve.post(item.id)
                : action === 'reject'
                  ? reject.post(item.id)
                  : update.patch(item.id);
        router[route.method](route.url, action === 'reject' ? {} : body(), {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    const tone = open
        ? item.decision?.required
            ? 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100'
            : 'border-blue-300 bg-blue-50 text-blue-950 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100'
        : 'border-neutral-200 dark:border-neutral-800';

    return (
        <li className={`rounded-md border p-3 text-sm ${tone}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">
                    <span className="mr-2 font-mono text-xs opacity-70">
                        {item.code}
                    </span>
                    {item.title}
                </p>
                <span className="rounded bg-white/60 px-2 py-0.5 text-xs dark:bg-black/30">
                    {open
                        ? (EFFECT[item.effect.kind] ?? item.effect.kind)
                        : item.status}
                </span>
            </div>
            {item.summary && <p className="mt-1 text-xs">{item.summary}</p>}

            {open && (
                <>
                    <div className="mt-2 grid gap-3 md:grid-cols-2">
                        <div>
                            <p className="text-xs font-medium tracking-wide uppercase opacity-70">
                                {item.action === 'tag'
                                    ? 'Facts that get the tags'
                                    : item.action === 'advice'
                                      ? 'Pending proposal'
                                      : 'Active fact now'}
                            </p>
                            <Facts item={item} />
                        </div>
                        {item.action === 'tag' ? (
                            <div>
                                <p className="text-xs font-medium tracking-wide uppercase opacity-70">
                                    Skills to add
                                </p>
                                <p className="mt-1">{item.skills.join(', ')}</p>
                            </div>
                        ) : item.action === 'advice' ? (
                            <div>
                                <p className="text-xs font-medium tracking-wide uppercase opacity-70">
                                    If you approve
                                </p>
                                <p className="mt-1 text-xs">
                                    {item.effect.text}
                                </p>
                            </div>
                        ) : (
                            <div>
                                <p className="text-xs font-medium tracking-wide uppercase opacity-70">
                                    Proposed
                                </p>
                                <label className="mt-1 block text-xs">
                                    <span className="sr-only">Statement</span>
                                    <textarea
                                        className={field}
                                        rows={5}
                                        value={statement}
                                        onChange={(e) =>
                                            setStatement(e.target.value)
                                        }
                                    />
                                </label>
                            </div>
                        )}
                    </div>

                    {editable && (
                        <div className="mt-2 grid gap-3 md:grid-cols-2">
                            <label className="block text-xs font-medium">
                                {item.attribution_change
                                    ? `Place (was ${item.attribution_change.from})`
                                    : 'Place'}
                                <select
                                    className={field}
                                    value={place}
                                    onChange={(e) => setPlace(e.target.value)}
                                >
                                    <option value="">Choose…</option>
                                    {choices.map((o) => (
                                        <option
                                            key={placeKey(o)}
                                            value={placeKey(o)}
                                        >
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-xs font-medium">
                                Visibility
                                <select
                                    className={field}
                                    value={visibility}
                                    onChange={(e) =>
                                        setVisibility(e.target.value)
                                    }
                                >
                                    <option value="public">Public</option>
                                    <option value="restricted">
                                        Restricted
                                    </option>
                                    <option value="private">Private</option>
                                </select>
                            </label>
                        </div>
                    )}
                    {item.decision && (
                        <p className="mt-2 rounded bg-white/60 p-2 text-xs dark:bg-black/30">
                            {item.decision.required
                                ? 'Needs your decision: '
                                : 'Open question: '}
                            {item.decision.question}
                        </p>
                    )}

                    <button
                        type="button"
                        className="mt-2 text-xs underline"
                        onClick={() => setDetails(!details)}
                    >
                        {details
                            ? 'Hide details'
                            : 'Evidence, effects and matching'}
                    </button>
                    {details && (
                        <dl className="mt-2 space-y-2 text-xs">
                            {item.source && (
                                <div>
                                    <dt className="font-medium">
                                        Your original answer (kept verbatim)
                                    </dt>
                                    <dd className="opacity-80">
                                        &ldquo;{item.source.answer}&rdquo;
                                    </dd>
                                    <dd className="opacity-60">
                                        Question: {item.source.question}
                                    </dd>
                                </div>
                            )}
                            {item.links.length > 0 && (
                                <div>
                                    <dt className="font-medium">
                                        Existing facts that already say related
                                        things
                                    </dt>
                                    {item.links.map((l) => (
                                        <dd key={l.key} className="opacity-80">
                                            <span className="font-mono">
                                                {l.key}
                                            </span>
                                            {l.statement
                                                ? `: ${l.statement}`
                                                : ' (private, not shown)'}
                                        </dd>
                                    ))}
                                </div>
                            )}
                            {item.attribution_change && (
                                <div>
                                    <dt className="font-medium">Attribution</dt>
                                    <dd>
                                        {item.attribution_change.from} →{' '}
                                        {item.attribution_change.to ??
                                            'to be chosen'}
                                    </dd>
                                </div>
                            )}
                            {item.skills.length > 0 &&
                                item.action !== 'tag' && (
                                    <div>
                                        <dt className="font-medium">
                                            Skill tags added
                                        </dt>
                                        <dd>{item.skills.join(', ')}</dd>
                                    </div>
                                )}
                            <div>
                                <dt className="font-medium">
                                    What approving does
                                </dt>
                                <dd>{item.effect.text}</dd>
                            </div>
                            <div>
                                <dt className="font-medium">Visibility</dt>
                                <dd>{item.visibility_effect}</dd>
                            </div>
                            {item.action !== 'tag' &&
                                item.action !== 'advice' && (
                                    <div>
                                        <dt className="font-medium">
                                            How the matcher reads the new
                                            wording
                                        </dt>
                                        <dd>
                                            {item.matching.direct.length > 0
                                                ? `Direct evidence for: ${item.matching.direct.join(', ')}.`
                                                : 'No capability matched directly.'}
                                            {item.matching.gains.length > 0 &&
                                                ` New: ${item.matching.gains.join(', ')}.`}
                                            {item.matching.loses.length > 0 &&
                                                ` No longer matched by this fact: ${item.matching.loses.join(', ')} (see covered elsewhere).`}
                                        </dd>
                                    </div>
                                )}
                            {item.notes.covered_elsewhere && (
                                <div>
                                    <dt className="font-medium">
                                        Covered elsewhere, not repeated
                                    </dt>
                                    <dd>{item.notes.covered_elsewhere}</dd>
                                </div>
                            )}
                            {item.notes.visibility && (
                                <div>
                                    <dt className="font-medium">
                                        Visibility note
                                    </dt>
                                    <dd>{item.notes.visibility}</dd>
                                </div>
                            )}
                            {item.notes.why && (
                                <div>
                                    <dt className="font-medium">
                                        Why separate
                                    </dt>
                                    <dd>{item.notes.why}</dd>
                                </div>
                            )}
                            {item.notes.caution && (
                                <div className="font-medium">
                                    Caution: {item.notes.caution}
                                </div>
                            )}
                        </dl>
                    )}

                    {Object.values(errors).map((message) => (
                        <p
                            key={message}
                            role="alert"
                            className="mt-2 text-xs text-red-700 dark:text-red-300"
                        >
                            {message}
                        </p>
                    ))}
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            className={primary}
                            disabled={busy}
                            onClick={() => send('approve')}
                        >
                            Approve
                        </button>
                        {editable && (
                            <button
                                type="button"
                                className={secondary}
                                disabled={busy}
                                onClick={() => send('save')}
                            >
                                Save edits
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            disabled={busy}
                            onClick={() => send('reject')}
                        >
                            {item.action === 'advice' ? 'Leave it' : 'Reject'}
                        </button>
                    </div>
                </>
            )}
            {!open && item.proposed?.statement && (
                <p className="mt-1 text-xs opacity-80">
                    {item.status === 'approved'
                        ? `Approved: ${item.proposed.statement}`
                        : 'Declined. Nothing changed.'}
                </p>
            )}
        </li>
    );
}

export function ConsolidationReview({ queue }: { queue: ConsolidationQueue }) {
    if (queue.items.length === 0) {
        return null;
    }

    const themes = [...new Set(queue.items.map((i) => i.theme))];

    return (
        <section id="evidence-consolidation">
            <h2 className="sr-only">Evidence consolidation review</h2>
            <p className="mt-1 text-xs text-neutral-500">
                {queue.counts.proposed} waiting, {queue.counts.approved}{' '}
                approved, {queue.counts.rejected} declined. Corrections to
                evidence you already approved, prepared from your own answers.
                Nothing changes until you approve an item, each is decided on
                its own, and every previous wording and your original answer are
                kept.
            </p>
            {themes.map((theme) => (
                <div key={theme} className="mt-4">
                    <h3 className="text-sm font-semibold">{theme}</h3>
                    <ul className="mt-2 space-y-3">
                        {queue.items
                            .filter((i) => i.theme === theme)
                            .map((item) => (
                                <ItemCard
                                    key={`${item.id}-${item.status}`}
                                    item={item}
                                    options={queue.options}
                                />
                            ))}
                    </ul>
                </div>
            ))}
        </section>
    );
}
