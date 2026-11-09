import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    approve,
    evidenceCandidates,
    newVersion,
    refreshEvidence,
    referenceResume,
    update,
} from '@/routes/profile-syntheses';
import { GenerationStatus } from '@/components/generation-status';
import { store as buildSynthesis } from '@/routes/profile-syntheses';
import type {
    ReferenceState,
    SynthesisCandidate,
    SynthesisFact,
    SynthesisProfile,
    SynthesisState,
} from '@/types/resume-profile';
import { buttonClass } from '@/components/ui/button';
import { fieldClass } from '@/components/ui/field';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Disclosure } from '@/components/ui/surface';
import { cn } from '@/lib/utils';

type Errors = Record<string, string>;

const field = cn(fieldClass, 'mt-1 w-full text-sm');
const primary = buttonClass('primary', 'sm');
const secondary = buttonClass('secondary', 'sm');

export function useSend() {
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);
    const send = (
        route: { url: string; method: 'post' | 'patch' | 'get' },
        data: RequestPayload = {},
    ) => {
        setBusy(true);
        router[route.method === 'get' ? 'get' : route.method](route.url, data, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    return { errors, busy, send };
}

function Errors({ errors }: { errors: Errors }) {
    return (
        <>
            {Object.entries(errors).map(([key, message]) => (
                <p
                    key={key + message}
                    role="alert"
                    className="mt-2 text-xs text-red-700 dark:text-red-300"
                >
                    {message}
                </p>
            ))}
        </>
    );
}

function Evidence({
    keys,
    facts,
}: {
    keys: string[];
    facts: Record<string, SynthesisFact>;
}) {
    if (keys.length === 0) {
        return null;
    }

    return (
        <details className="mt-1 text-xs text-neutral-500">
            <summary className="cursor-pointer">
                Evidence ({keys.length})
            </summary>
            <ul className="mt-1 space-y-1">
                {keys.map((key) => {
                    const f = facts[key];

                    return (
                        <li key={key}>
                            <span className="font-mono">{key}</span>
                            {f && (
                                <>
                                    {' '}
                                    <span className="rounded bg-neutral-100 px-1 dark:bg-neutral-800">
                                        {f.visibility}
                                    </span>
                                    {f.personal && (
                                        <span className="ml-1 rounded bg-amber-100 px-1 text-amber-900">
                                            {f.whole_career
                                                ? 'whole career'
                                                : 'personal'}
                                        </span>
                                    )}
                                    <br />
                                    {f.statement}
                                    {f.shared_with.length > 0 &&
                                        ` (also: ${f.shared_with.join(', ')})`}
                                </>
                            )}
                        </li>
                    );
                })}
            </ul>
        </details>
    );
}

function Row({
    id,
    included,
    pinned,
    editable,
    ids,
    sid,
    children,
}: {
    id: string;
    included: boolean;
    pinned: boolean;
    editable: boolean;
    ids: string[];
    sid: number;
    children: React.ReactNode;
}) {
    const { busy, send, errors } = useSend();
    const move = (by: number) => {
        const next = [...ids];
        const at = next.indexOf(id);
        const to = at + by;

        if (to < 0 || to >= next.length) {
            return;
        }

        [next[at], next[to]] = [next[to], next[at]];
        send(update.patch(sid), { reorder: next });
    };

    return (
        <li
            className={cn(
                'rounded-control border p-3 text-sm',
                included
                    ? 'border-line bg-canvas/50'
                    : 'border-line-strong border-dashed opacity-60',
            )}
        >
            <div className="flex flex-wrap gap-x-3 gap-y-2">
                <div className="min-w-0 flex-1 basis-64">{children}</div>
                {editable && (
                    <div className="flex shrink-0 flex-wrap items-start gap-1">
                        <button
                            type="button"
                            className={secondary}
                            disabled={busy}
                            onClick={() =>
                                send(update.patch(sid), {
                                    id,
                                    include: !included,
                                })
                            }
                        >
                            {included ? 'Exclude' : 'Include'}
                        </button>
                        <button
                            type="button"
                            className={secondary}
                            disabled={busy}
                            onClick={() =>
                                send(update.patch(sid), { id, pinned: !pinned })
                            }
                        >
                            {pinned ? 'Unpin' : 'Pin'}
                        </button>
                        <div className="flex gap-1">
                            <button
                                type="button"
                                className={secondary}
                                disabled={busy}
                                aria-label="Move up"
                                onClick={() => move(-1)}
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                className={secondary}
                                disabled={busy}
                                aria-label="Move down"
                                onClick={() => move(1)}
                            >
                                ↓
                            </button>
                        </div>
                    </div>
                )}
            </div>
            <Errors errors={errors} />
        </li>
    );
}

type Gap = {
    kind: string;
    level: string;
    sentence: string;
    claim: string;
    message: string;
    candidates: string[];
    repaired: string | null;
};

type Resolution = {
    status: 'uncertain' | 'unsupported';
    gaps: Gap[];
    alternative: string | null;
};

function readResolution(errors: Errors): Resolution | null {
    try {
        return errors.resolution
            ? (JSON.parse(errors.resolution) as Resolution)
            : null;
    } catch {
        return null;
    }
}

function factLabel(f: SynthesisFact | undefined): string {
    if (!f) {
        return 'A career fact';
    }

    const place = f.project ?? f.role ?? (f.whole_career ? 'Whole career' : '');

    return `${f.statement.length > 150 ? `${f.statement.slice(0, 150)}…` : f.statement}${place ? ` — ${place}` : ''}`;
}

function EditableText({
    id,
    value,
    original,
    sid,
    editable,
    rows = 3,
    keys,
    facts = {},
}: {
    id: string;
    value: string;
    original: string;
    sid: number;
    editable: boolean;
    rows?: number;
    keys?: string[];
    facts?: Record<string, SynthesisFact>;
}) {
    const [text, setText] = useState(value);
    const [advanced, setAdvanced] = useState(false);
    const [candidates, setCandidates] = useState<SynthesisCandidate[] | null>(
        null,
    );
    const [cited, setCited] = useState<string[]>(keys ?? []);
    const [adding, setAdding] = useState('');
    const { busy, send, errors } = useSend();
    const resolution = readResolution(errors);
    const shown = Object.entries(errors).filter(([k]) => k !== 'resolution');
    const keysChanged =
        keys !== undefined &&
        (cited.length !== keys.length || cited.some((k) => !keys.includes(k)));

    useEffect(() => setText(value), [value]);
    useEffect(() => setCited(keys ?? []), [keys?.join('|')]);

    if (!editable) {
        return <p className="text-sm">{value}</p>;
    }

    const open = async () => {
        setAdvanced(true);

        if (candidates === null) {
            const res = await fetch(evidenceCandidates.url(sid), {
                headers: { Accept: 'application/json' },
            });
            setCandidates(
                ((await res.json()) as { candidates: SynthesisCandidate[] })
                    .candidates,
            );
        }
    };
    const save = (wording: string, extra: Record<string, unknown> = {}) =>
        send(update.patch(sid), { id, text: wording, ...extra });
    const pick = (gap: Gap, key: string) => {
        // Choosing a fact for an uncertain sentence is the manual path: it is still judged with the wording.
        save(text, { fact_keys: [...new Set([...(keys ?? []), key])] });
        void gap;
    };
    const available = (candidates ?? []).filter((c) => !cited.includes(c.key));

    return (
        <div>
            <textarea
                className={field}
                rows={rows}
                value={text}
                onChange={(e) => setText(e.target.value)}
            />
            {keys !== undefined && (
                <div className="mt-1 text-xs text-neutral-500">
                    <p>
                        {keys.length === 0
                            ? 'Not tied to specific career facts.'
                            : `Supported by ${keys.length} career fact${keys.length === 1 ? '' : 's'}`}
                    </p>
                    {keys.length > 0 && (
                        <details className="mt-1">
                            <summary className="cursor-pointer">
                                View supporting evidence
                            </summary>
                            <ul className="mt-1 space-y-1">
                                {keys.map((key) => (
                                    <li key={key}>
                                        {factLabel(facts[key])}{' '}
                                        <span className="rounded bg-neutral-100 px-1 dark:bg-neutral-800">
                                            {facts[key]?.support_only
                                                ? 'support only'
                                                : facts[key]?.visibility}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </div>
            )}
            {resolution && (
                <div
                    role="alert"
                    className="mt-2 rounded border border-amber-300 p-2 text-xs dark:border-amber-700"
                >
                    <p className="font-medium">
                        {resolution.status === 'uncertain'
                            ? 'Not saved: support is uncertain.'
                            : 'Not saved: part of this is not supported by your career facts.'}
                    </p>
                    <ul className="mt-1 space-y-2">
                        {resolution.gaps.slice(0, 4).map((gap, i) => (
                            <li key={i}>
                                <p>{gap.message}</p>
                                {gap.sentence && gap.sentence !== text && (
                                    <p className="text-neutral-500">
                                        “{gap.sentence}”
                                    </p>
                                )}
                                {gap.candidates.slice(0, 3).map((key) => (
                                    <button
                                        key={key}
                                        type="button"
                                        className={`${secondary} mt-1 block text-left`}
                                        disabled={busy}
                                        onClick={() => pick(gap, key)}
                                    >
                                        Use: {factLabel(facts[key])}
                                    </button>
                                ))}
                            </li>
                        ))}
                    </ul>
                    <div className="mt-2 flex gap-2">
                        {resolution.alternative && (
                            <button
                                type="button"
                                className={secondary}
                                disabled={busy}
                                onClick={() => {
                                    setText(resolution.alternative ?? text);
                                    save(resolution.alternative ?? text);
                                }}
                            >
                                Remove the unsupported sentence
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => setText(value)}
                        >
                            Return to editing
                        </button>
                    </div>
                </div>
            )}
            <div className="mt-1 flex gap-2">
                <button
                    type="button"
                    className={secondary}
                    disabled={busy || (text === value && !keysChanged)}
                    onClick={() =>
                        save(text, keysChanged ? { fact_keys: cited } : {})
                    }
                >
                    Save wording{keysChanged ? ' and evidence' : ''}
                </button>
                {value !== original && (
                    <button
                        type="button"
                        className={secondary}
                        disabled={busy}
                        onClick={() => save(original)}
                    >
                        Restore original wording
                    </button>
                )}
                {keys !== undefined && !advanced && (
                    <button
                        type="button"
                        className={secondary}
                        onClick={() => void open()}
                    >
                        Advanced evidence editor
                    </button>
                )}
            </div>
            {advanced && keys !== undefined && (
                <div className="mt-2 text-xs">
                    <p className="font-medium text-neutral-500">
                        Advanced evidence editor. Everything the wording says
                        must be stated by the facts you choose.
                    </p>
                    <ul className="mt-1 space-y-1">
                        {cited.map((key) => (
                            <li
                                key={key}
                                className="flex items-start justify-between gap-2 rounded bg-neutral-50 px-2 py-1 dark:bg-neutral-900"
                            >
                                <span>{factLabel(facts[key])}</span>
                                <button
                                    type="button"
                                    className={secondary}
                                    disabled={cited.length <= 1}
                                    onClick={() =>
                                        setCited(cited.filter((k) => k !== key))
                                    }
                                    aria-label="Remove this fact"
                                >
                                    Remove
                                </button>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-1 flex gap-2">
                        <select
                            className={field}
                            value={adding}
                            onChange={(e) => setAdding(e.target.value)}
                            aria-label="Add a supporting fact"
                        >
                            <option value="">
                                {candidates === null
                                    ? 'Loading…'
                                    : 'Add a supporting fact…'}
                            </option>
                            {[...new Set(available.map((c) => c.place))].map(
                                (place) => (
                                    <optgroup key={place} label={place}>
                                        {available
                                            .filter((c) => c.place === place)
                                            .map((c) => (
                                                <option
                                                    key={c.key}
                                                    value={c.key}
                                                >
                                                    {c.statement}
                                                </option>
                                            ))}
                                    </optgroup>
                                ),
                            )}
                        </select>
                        <button
                            type="button"
                            className={secondary}
                            disabled={adding === ''}
                            onClick={() => {
                                setCited([...cited, adding]);
                                setAdding('');
                            }}
                        >
                            Add
                        </button>
                    </div>
                </div>
            )}
            <Errors errors={Object.fromEntries(shown)} />
        </div>
    );
}

function Signals({ signals }: { signals: Record<string, number> }) {
    return (
        <span className="text-xs text-neutral-500">
            {Object.entries(signals)
                .map(([k, v]) => `${k} ${v.toFixed(2)}`)
                .join(' · ')}
        </span>
    );
}

function ReferencePanel({
    reference,
    sid,
}: {
    reference: ReferenceState;
    sid: number;
}) {
    const { busy, send, errors } = useSend();
    const active = reference.running || reference.state === 'running';
    const stage = (phase: string | null) =>
        ({
            selection: 'selecting evidence',
            wording: 'writing wording',
            saving: 'saving variant',
            pdf: 'rendering PDF',
        })[phase ?? ''] ?? 'starting';

    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['resume_profile'] }),
            4000,
        );

        return () => clearInterval(timer);
    }, [active]);

    return (
        <section className="rounded-card border-line bg-surface border p-5">
            <h4 className="text-sm font-semibold">Reference resume</h4>
            {reference.stale && (
                <p className="mt-1 text-xs text-amber-700">
                    Your approved evidence changed after this profile was
                    approved. Build and approve a new version first.
                </p>
            )}
            {active && (
                <div className="mt-1">
                    <GenerationStatus
                        active
                        label={`Generating reference resume · ${stage(reference.phase)}`}
                        queued={reference.state !== 'running'}
                        queuedLabel="Queued · waiting for the worker to start…"
                        startedAt={
                            reference.state === 'running'
                                ? reference.started_at
                                : null
                        }
                    />
                    <p className="mt-1 text-xs text-neutral-500">
                        Stages: selection → wording (local model) → saving →
                        PDF.
                    </p>
                </div>
            )}
            {reference.state === 'failed' && (
                <p role="alert" className="mt-1 text-xs text-red-700">
                    Generation failed
                    {reference.finished_at &&
                        ` after ${Math.round((new Date(reference.finished_at).getTime() - new Date(reference.started_at ?? reference.finished_at).getTime()) / 1000)}s`}
                    : {reference.error}
                </p>
            )}
            {reference.state === 'ready' && reference.variant_id && (
                <div className="mt-1 text-xs">
                    <p className="font-medium text-green-700 dark:text-green-400">
                        Reference resume ready
                    </p>
                    <a
                        className="underline"
                        href={reference.preview_url ?? '#'}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Preview
                    </a>{' '}
                    ·{' '}
                    <a
                        className="underline"
                        href={reference.download_url ?? '#'}
                    >
                        Download PDF
                    </a>{' '}
                    · {reference.pages} page(s) ·{' '}
                    {Math.round((reference.duration_ms ?? 0) / 1000)}s total ·{' '}
                    {reference.model_calls} wording call(s)
                </div>
            )}
            <div className="mt-2">
                <button
                    type="button"
                    className={primary}
                    disabled={busy || active || reference.stale}
                    onClick={() => send(referenceResume.post(sid), {})}
                >
                    {reference.state === 'ready'
                        ? 'Generate again'
                        : 'Generate reference resume'}
                </button>
            </div>
            <Errors errors={errors} />
        </section>
    );
}

function Profile({
    p,
    sid,
    editable,
}: {
    p: SynthesisProfile;
    sid: number;
    editable: boolean;
}) {
    const pres = p.presentation;
    const projects = p.projects.filter((x) => !x.personal);
    const personal = p.projects.filter((x) => x.personal);
    const sample = p.provenance.sample;

    return (
        <div className="[&>section]:rounded-card [&>section]:border-line [&>section]:bg-surface space-y-5 [&>section]:border [&>section]:p-5">
            <Disclosure quiet summary="Where this profile came from">
                <div className="text-fg-subtle space-y-1 text-xs leading-relaxed">
                    {sample ? (
                        <p>
                            Market basis:{' '}
                            <strong>
                                {sample.pilot ? 'pilot sample' : 'sample'} of{' '}
                                {sample.postings} postings
                            </strong>{' '}
                            ({sample.companies} companies,{' '}
                            {sample.providers.join(', ')}, {sample.oldest} to{' '}
                            {sample.newest}).{' '}
                            {sample.pilot &&
                                'This is a small pilot, not a full market study; priorities below are indicative.'}
                        </p>
                    ) : (
                        <p>No market sample: ranking uses the evidence only.</p>
                    )}
                    <p>
                        Built from approved evidence revision{' '}
                        {p.provenance.evidence_revision}. {p.private_omitted}{' '}
                        Private fact(s) were left out entirely.
                        {p.excluded.length > 0 &&
                            ` ${p.excluded.length} fact(s) were not used as written: ${p.excluded.map((e) => e.fact_key).join(', ')} (first-person wording).`}
                    </p>
                </div>
            </Disclosure>

            <section>
                <h4 className="text-sm font-semibold">Positioning</h4>
                <p className="mt-2 text-xs font-medium text-neutral-500">
                    Headline
                </p>
                <EditableText
                    id="headline"
                    value={pres.headline}
                    original={pres.headline_original}
                    sid={sid}
                    editable={editable}
                    rows={1}
                />
                <p className="mt-2 text-xs font-medium text-neutral-500">
                    Summary
                </p>
                <EditableText
                    id="summary"
                    value={pres.summary}
                    original={pres.summary_original}
                    sid={sid}
                    editable={editable}
                    rows={5}
                    keys={pres.summary_fact_keys}
                    facts={p.facts}
                />
                {!editable && (
                    <Evidence keys={pres.summary_fact_keys} facts={p.facts} />
                )}
            </section>

            <section>
                <h4 className="text-sm font-semibold">
                    Technical differentiators
                </h4>
                <ul className="mt-2 space-y-2">
                    {pres.differentiators.map((d) => (
                        <Row
                            key={d.id}
                            id={d.id}
                            included={d.included}
                            pinned={d.pinned}
                            editable={editable}
                            ids={pres.differentiators.map((x) => x.id)}
                            sid={sid}
                        >
                            <EditableText
                                id={d.id}
                                value={d.statement}
                                original={d.original}
                                sid={sid}
                                editable={editable}
                                rows={2}
                                keys={d.fact_keys}
                                facts={p.facts}
                            />
                            {!editable && (
                                <Evidence keys={d.fact_keys} facts={p.facts} />
                            )}
                        </Row>
                    ))}
                </ul>
            </section>

            <section>
                <h4 className="text-sm font-semibold">Projects (employment)</h4>
                <p className="text-xs text-neutral-500">
                    In the order your profile uses them: your own order first,
                    then pinned and suggested placement. Each project also shows
                    the rank the system suggested, for comparison.
                </p>
                <ul className="mt-2 space-y-2">
                    {projects.map((x, i) => {
                        const note = pres.project_notes.find(
                            (n) => n.project_id === x.project_id,
                        );

                        return (
                            <Row
                                key={x.id}
                                id={x.id}
                                included={x.included}
                                pinned={x.pinned}
                                editable={editable}
                                ids={projects.map((y) => y.id)}
                                sid={sid}
                            >
                                <p className="font-medium">
                                    {i + 1}. {x.name}{' '}
                                    <span className="text-xs font-normal text-neutral-500">
                                        {x.employer} · Suggested rank {x.rank}
                                    </span>
                                </p>
                                <p className="text-xs">{x.reason}</p>
                                <Disclosure
                                    quiet
                                    className="mt-1"
                                    summary="Scoring details"
                                >
                                    <p className="text-fg-subtle text-xs">
                                        Score {x.score.toFixed(2)}
                                    </p>
                                    <Signals signals={x.signals} />
                                </Disclosure>
                                {note && (
                                    <div className="mt-1">
                                        <EditableText
                                            id={note.id}
                                            value={note.note}
                                            original={note.original}
                                            sid={sid}
                                            editable={editable}
                                            rows={2}
                                            keys={note.fact_keys}
                                            facts={p.facts}
                                        />
                                    </div>
                                )}
                                <Evidence keys={x.fact_keys} facts={p.facts} />
                            </Row>
                        );
                    })}
                </ul>
                {personal.length > 0 && (
                    <>
                        <h4 className="mt-4 text-sm font-semibold">
                            Personal projects
                        </h4>
                        <p className="text-xs text-neutral-500">
                            Shown only under Selected Projects, never as
                            employment.
                        </p>
                        <ul className="mt-2 space-y-2">
                            {personal.map((x) => (
                                <Row
                                    key={x.id}
                                    id={x.id}
                                    included={x.included}
                                    pinned={x.pinned}
                                    editable={editable}
                                    ids={personal.map((y) => y.id)}
                                    sid={sid}
                                >
                                    <p className="font-medium">{x.name}</p>
                                    <p className="text-xs">{x.reason}</p>
                                    <Evidence
                                        keys={x.fact_keys}
                                        facts={p.facts}
                                    />
                                </Row>
                            ))}
                        </ul>
                    </>
                )}
            </section>

            <section>
                <Disclosure
                    summary={`Capabilities: direct experience (${p.capabilities.direct.length})`}
                >
                    <ul className="mt-2 space-y-2">
                        {p.capabilities.direct.map((c) => (
                            <Row
                                key={c.id}
                                id={c.id}
                                included={c.included}
                                pinned={c.pinned}
                                editable={editable}
                                ids={p.capabilities.direct.map((y) => y.id)}
                                sid={sid}
                            >
                                <p>
                                    <span className="font-medium">
                                        {c.label}
                                    </span>{' '}
                                    <span className="text-xs text-neutral-500">
                                        {c.depth ?? '-'} depth · asked for in{' '}
                                        {c.market_postings} posting(s)
                                    </span>
                                </p>
                                <Evidence keys={c.fact_keys} facts={p.facts} />
                            </Row>
                        ))}
                    </ul>
                    <h4 className="mt-4 text-sm font-semibold">
                        Related, not claimed as experience
                    </h4>
                    <p className="text-xs text-neutral-500">
                        {p.capabilities.transferable
                            .map((c) => c.label)
                            .join(', ') || 'None'}
                        . Only a related capability is documented for these.
                    </p>
                    {p.limitations.length > 0 && (
                        <p className="mt-1 text-xs text-neutral-500">
                            Confirmed no direct experience (never claimed):{' '}
                            {p.limitations.join(', ')}.
                        </p>
                    )}
                </Disclosure>
            </section>

            <section>
                <Disclosure
                    summary={`Accomplishments the resume can draw on (${p.accomplishments.length})`}
                >
                    <ul className="mt-2 space-y-2">
                        {p.accomplishments.map((a, i) => (
                            <Row
                                key={a.id}
                                id={a.id}
                                included={a.included}
                                pinned={a.pinned}
                                editable={editable}
                                ids={p.accomplishments.map((y) => y.id)}
                                sid={sid}
                            >
                                <p className="text-sm">
                                    {p.facts[a.fact_key]?.statement}
                                </p>
                                <p className="text-xs text-neutral-500">
                                    {i + 1}. {a.kind} · {a.visibility} ·
                                    Suggested rank {a.rank} ·{' '}
                                    {p.facts[a.fact_key]?.project ??
                                        p.facts[a.fact_key]?.role}
                                    {a.shared_with.length > 0 &&
                                        ` · also: ${a.shared_with.join(', ')}`}
                                </p>
                            </Row>
                        ))}
                    </ul>
                </Disclosure>
            </section>
        </div>
    );
}

export function ProfileTab({
    state,
    slug,
}: {
    state: SynthesisState;
    slug: string;
}) {
    const { busy, send, errors } = useSend();
    const s = state.synthesis;
    const editable = s?.status === 'draft';
    const building = state.building;

    useEffect(() => {
        if (!building) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['resume_profile'] }),
            5000,
        );

        return () => clearInterval(timer);
    }, [building]);

    return (
        <div className="space-y-5" id="profile-synthesis">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    {s ? (
                        <>
                            <Badge
                                tone={s.status === 'approved' ? 'ok' : 'accent'}
                            >
                                {s.status === 'approved' ? 'Approved' : 'Draft'}{' '}
                                · version {s.version}
                            </Badge>
                            {s.stale && (
                                <Badge tone="warn">Evidence changed</Badge>
                            )}
                            {!s.stale && s.outdated && (
                                <Badge>Refresh available</Badge>
                            )}
                            {s.pilot && <Badge>Pilot market sample</Badge>}
                        </>
                    ) : (
                        <span className="text-fg-muted text-sm">
                            No profile has been built yet.
                        </span>
                    )}
                </div>
                <button
                    type="button"
                    className={s === null ? primary : secondary}
                    disabled={busy || building}
                    onClick={() => {
                        if (
                            s === null ||
                            window.confirm(
                                'Build a new version? This calls the local model again.',
                            )
                        ) {
                            send(buildSynthesis.post(slug), {
                                force: s !== null,
                            });
                        }
                    }}
                >
                    {building
                        ? 'Building…'
                        : s === null
                          ? 'Build the profile'
                          : 'Build a new version'}
                </button>
            </div>
            <p className="text-fg-subtle max-w-prose text-xs leading-relaxed">
                Built from your approved evidence and the market study. Nothing
                is approved until you approve it, and the reference resume is
                generated only from an approved profile.
            </p>
            {state.error && (
                <Alert tone="bad" role="alert">
                    {state.error}
                </Alert>
            )}
            <Errors errors={errors} />
            {s && (
                <>
                    {s.notes.length > 0 && (
                        <Alert tone="warn">
                            <Disclosure
                                quiet
                                summary={`${s.notes.length} validation note(s) to read`}
                            >
                                <ul className="list-disc space-y-1 pl-4 text-xs">
                                    {s.notes.map((n) => (
                                        <li key={n}>{n}</li>
                                    ))}
                                </ul>
                            </Disclosure>
                        </Alert>
                    )}
                    <Profile p={s.profile} sid={s.id} editable={editable} />
                    <ApprovalBar s={s} slug={slug} />
                </>
            )}
            {state.reference && s?.status !== 'draft' && (
                <ReferencePanel
                    reference={state.reference}
                    sid={state.reference.synthesis_id}
                />
            )}
        </div>
    );
}

function ApprovalBar({
    s,
}: {
    s: NonNullable<SynthesisState['synthesis']>;
    slug: string;
}) {
    const { busy, send, errors } = useSend();

    return (
        <div className="rounded-card border-line bg-surface border p-5">
            {s.status === 'draft' ? (
                <>
                    <p className="text-xs">
                        Review the positioning, projects, capabilities and
                        accomplishments above. Approving locks this version;
                        only then can a reference resume be generated.
                    </p>
                    <button
                        type="button"
                        className={`${primary} mt-2`}
                        disabled={busy || s.stale}
                        onClick={() => send(approve.post(s.id), {})}
                    >
                        Approve this profile
                    </button>
                    {(s.stale || s.outdated) && (
                        <button
                            type="button"
                            className={`${secondary} mt-2 ml-2`}
                            disabled={busy}
                            onClick={() => send(refreshEvidence.post(s.id), {})}
                        >
                            Refresh evidence (no model call)
                        </button>
                    )}
                </>
            ) : (
                <>
                    <p className="text-xs">
                        Approved. To change it, start a new draft from this
                        version (no model call).
                    </p>
                    <button
                        type="button"
                        className={`${secondary} mt-2`}
                        disabled={busy}
                        onClick={() => send(newVersion.post(s.id), {})}
                    >
                        Edit as a new draft
                    </button>
                    {(s.stale || s.outdated) && (
                        <button
                            type="button"
                            className={`${secondary} mt-2 ml-2`}
                            disabled={busy}
                            onClick={() => send(refreshEvidence.post(s.id), {})}
                        >
                            Refresh evidence (no model call)
                        </button>
                    )}
                </>
            )}
            <Errors errors={errors} />
        </div>
    );
}
