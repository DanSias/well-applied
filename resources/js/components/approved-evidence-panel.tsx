import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { revise } from '@/routes/career-facts';
import type {
    ApprovedEvidence,
    ApprovedEvidenceFact,
} from '@/types/tailored-resume';

type Errors = Record<string, string>;

const field =
    'mt-1 w-full rounded-md border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-950';
const primary =
    'rounded-md bg-neutral-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900';
const secondary =
    'rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300';

function RevisionForm({
    fact,
    evidence,
    onDone,
}: {
    fact: ApprovedEvidenceFact;
    evidence: ApprovedEvidence;
    onDone: () => void;
}) {
    const [statement, setStatement] = useState(fact.statement);
    const [scopes, setScopes] = useState<string[]>(fact.scopes);
    const [attribution, setAttribution] = useState(
        `${fact.attributable_type}:${fact.attributable_id}`,
    );
    const [visibility, setVisibility] = useState<string>(fact.visibility);
    const [verification, setVerification] = useState<string>(fact.verification);
    const [acknowledge, setAcknowledge] = useState(false);
    const [thisOnly, setThisOnly] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);

    const save = () => {
        const [type, id] = attribution.split(':');
        const payload: RequestPayload = {
            statement,
            scopes,
            attributable_type: type,
            attributable_id: Number(id),
            visibility,
            verification,
            acknowledge_metric: acknowledge,
            this_place_only: thisOnly,
        };

        setBusy(true);
        router.patch(revise(fact.fact_key).url, payload, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => {
                setErrors({});
                onDone();
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="mt-2 rounded-md border border-blue-300 bg-blue-50 p-3 text-sm text-blue-950 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100">
            <p className="text-xs">
                Saving creates revision {fact.revision + 1}. The earlier wording
                and your original answer stay on record, and the next resume
                re-checks matching against the change.
            </p>

            {fact.shared_with.length > 0 && (
                <div className="mt-2 rounded bg-white/60 p-2 text-xs dark:bg-black/20">
                    <p>
                        This is one claim recorded for several places. It is
                        also attached to:{' '}
                        {fact.shared_with.map((s) => s.place).join('; ')}.
                    </p>
                    <label className="mt-1 flex items-center gap-1.5">
                        <input
                            type="checkbox"
                            checked={thisOnly}
                            onChange={(e) => setThisOnly(e.target.checked)}
                        />
                        Change this place only (it stops being the same claim)
                    </label>
                    {!thisOnly && (
                        <p className="mt-1 opacity-80">
                            A change to the wording, scope or verification is
                            applied to every place, so they never disagree.
                        </p>
                    )}
                </div>
            )}

            <label className="mt-2 block text-xs font-medium">
                Statement
                <textarea
                    className={field}
                    rows={3}
                    value={statement}
                    onChange={(e) => setStatement(e.target.value)}
                />
            </label>

            <fieldset className="mt-2">
                <legend className="text-xs font-medium">
                    Responsibility scope
                </legend>
                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    {evidence.scopes.map((scope) => (
                        <label
                            key={scope.value}
                            className="flex items-center gap-1.5"
                        >
                            <input
                                type="checkbox"
                                checked={scopes.includes(scope.value)}
                                onChange={() =>
                                    setScopes((c) =>
                                        c.includes(scope.value)
                                            ? c.filter((s) => s !== scope.value)
                                            : [...c, scope.value],
                                    )
                                }
                            />
                            {scope.label}
                        </label>
                    ))}
                </div>
            </fieldset>

            <div className="mt-2 grid gap-3 sm:grid-cols-3">
                <label className="text-xs font-medium sm:col-span-3">
                    Belongs to
                    <select
                        className={field}
                        value={attribution}
                        onChange={(e) => setAttribution(e.target.value)}
                    >
                        {evidence.attribution_options.map((o) => (
                            <option
                                key={`${o.type}:${o.id}`}
                                value={`${o.type}:${o.id}`}
                            >
                                {o.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-xs font-medium">
                    Visibility
                    <select
                        className={field}
                        value={visibility}
                        onChange={(e) => setVisibility(e.target.value)}
                    >
                        <option value="public">Public</option>
                        <option value="restricted">Restricted</option>
                        <option value="private">Private</option>
                    </select>
                </label>
                <label className="text-xs font-medium sm:col-span-2">
                    Verification
                    <select
                        className={field}
                        value={verification}
                        onChange={(e) => setVerification(e.target.value)}
                    >
                        <option value="strongly_supported">
                            Strongly supported (your own account)
                        </option>
                        <option value="verified">
                            Verified (you can substantiate it)
                        </option>
                        <option value="needs_confirmation">
                            Needs confirmation
                        </option>
                    </select>
                </label>
            </div>

            {verification === 'verified' && (
                <label className="mt-2 flex items-center gap-1.5 text-xs">
                    <input
                        type="checkbox"
                        checked={acknowledge}
                        onChange={(e) => setAcknowledge(e.target.checked)}
                    />
                    I can substantiate any figures in this statement
                </label>
            )}

            {Object.values(errors).length > 0 && (
                <ul
                    role="alert"
                    className="mt-2 space-y-1 text-xs text-red-600"
                >
                    {Object.values(errors).map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            )}

            <div className="mt-3 flex gap-2">
                <button
                    type="button"
                    className={primary}
                    disabled={busy}
                    onClick={save}
                >
                    Save as new revision
                </button>
                <button
                    type="button"
                    className={secondary}
                    disabled={busy}
                    onClick={onDone}
                >
                    Cancel
                </button>
            </div>
        </div>
    );
}

function FactCard({
    fact,
    evidence,
}: {
    fact: ApprovedEvidenceFact;
    evidence: ApprovedEvidence;
}) {
    const [editing, setEditing] = useState(false);
    const scopeLabels = fact.scopes.map(
        (s) => evidence.scopes.find((o) => o.value === s)?.label ?? s,
    );

    return (
        <li className="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-800">
            <p>{fact.statement}</p>
            <p className="mt-1 text-xs text-neutral-500">
                {fact.attribution_label ?? 'Unattributed'} · {fact.visibility} ·{' '}
                {fact.verification.replace('_', ' ')} · revision {fact.revision}{' '}
                · {fact.fact_key}
            </p>
            <p className="mt-1 text-xs text-neutral-500">
                Scope you confirmed: {scopeLabels.join('; ')}
            </p>
            {fact.original_answer && (
                <p className="mt-1 text-xs text-neutral-500">
                    Your original answer: &ldquo;{fact.original_answer}&rdquo;
                </p>
            )}

            {fact.revisions.length > 1 && (
                <details className="mt-1 text-xs text-neutral-500">
                    <summary className="cursor-pointer">
                        History ({fact.revisions.length} revisions)
                    </summary>
                    <ol className="mt-1 space-y-1">
                        {fact.revisions.map((r) => (
                            <li key={r.revision}>
                                Revision {r.revision}
                                {r.current ? ' (current)' : ''}
                                {r.changed.length > 0 &&
                                    `, changed ${r.changed.join(', ')}`}
                                : {r.statement} ({r.visibility})
                            </li>
                        ))}
                    </ol>
                </details>
            )}

            {editing ? (
                <RevisionForm
                    fact={fact}
                    evidence={evidence}
                    onDone={() => setEditing(false)}
                />
            ) : (
                <button
                    type="button"
                    className={`${secondary} mt-2`}
                    onClick={() => setEditing(true)}
                >
                    Revise
                </button>
            )}
        </li>
    );
}

/**
 * The evidence you confirmed that relates to this job: what is on record,
 * exactly what you said, and how it has changed. Revising never overwrites;
 * it adds a revision.
 */
export function ApprovedEvidencePanel({
    evidence,
}: {
    evidence: ApprovedEvidence;
}) {
    if (
        evidence.facts.length === 0 &&
        evidence.limitations.length === 0 &&
        evidence.preferences.length === 0
    ) {
        return null;
    }

    return (
        <section className="mt-4">
            <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                Evidence you confirmed
            </h2>
            <p className="mt-1 text-xs text-neutral-500">
                Used in matching and in every future resume, at the visibility
                shown. Private evidence is never sent to a model or shown on a
                resume.
            </p>

            {evidence.facts.length > 0 && (
                <ul className="mt-2 space-y-2">
                    {evidence.facts.map((fact) => (
                        <FactCard
                            key={`${fact.fact_id}-${fact.revision}`}
                            fact={fact}
                            evidence={evidence}
                        />
                    ))}
                </ul>
            )}

            {evidence.limitations.map((l) => (
                <p key={l.id} className="mt-2 text-xs text-neutral-500">
                    Known limitation: {l.topic} ({l.status.replaceAll('_', ' ')}
                    ), confirmed {l.confirmed_at}. Resumes will not claim it.
                </p>
            ))}
            {evidence.preferences.map((p) => (
                <p key={p.id} className="mt-2 text-xs text-neutral-500">
                    Presentation guidance: {p.guidance}
                </p>
            ))}
        </section>
    );
}
