import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { expand } from '@/routes/answers';
import {
    approve as approveClaim,
    merge as mergeClaim,
    reject as rejectClaim,
    update as updateClaim,
} from '@/routes/claim-groups';
import { ask as askFollowup } from '@/routes/expansions/followups';
import type { AnswerExpansion, ExpandedClaim } from '@/types/resume-profile';
import type {
    ClarificationAttributionOption,
    ClarificationScope,
} from '@/types/tailored-resume';

type Errors = Record<string, string>;

const field =
    'mt-1 w-full rounded-md border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-950';
const primary =
    'rounded-md bg-neutral-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900';
const secondary =
    'rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300';

const CONFIDENCE: Record<string, string> = {
    high: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    medium: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    low: 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
};

function ClaimCard({
    claim,
    others,
    options,
    scopes,
}: {
    claim: ExpandedClaim;
    others: ExpandedClaim[];
    options: ClarificationAttributionOption[];
    scopes: ClarificationScope[];
}) {
    const open = claim.members.filter((m) => m.status === 'proposed');
    const [statement, setStatement] = useState(claim.statement);
    const [picked, setPicked] = useState<string[]>(claim.scopes);
    const [targets, setTargets] = useState<string[]>(open.map((m) => m.target));
    const [visibility, setVisibility] = useState<string>(
        // The most restrictive suggestion among the places; the user still chooses.
        (['private', 'restricted', 'public'].find((v) =>
            open.some((m) => m.visibility === v),
        ) ?? 'restricted') as string,
    );
    const [replace, setReplace] = useState<number[]>([]);
    const [mergeInto, setMergeInto] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);

    const label = (key: string) =>
        options.find((o) => `${o.type}:${o.id}` === key)?.label ?? key;
    const dirty =
        statement !== claim.statement ||
        picked.slice().sort().join() !== claim.scopes.slice().sort().join() ||
        targets.slice().sort().join() !==
            open
                .map((m) => m.target)
                .sort()
                .join();

    const send = (
        method: 'patch' | 'post',
        url: string,
        data: RequestPayload,
    ) => {
        setBusy(true);
        router[method](url, data, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    if (!claim.pending) {
        return (
            <li className="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-800">
                <p>{claim.statement}</p>
                <ul className="mt-1 space-y-0.5 text-xs text-neutral-500">
                    {claim.members.map((m) => (
                        <li key={m.id}>
                            {m.attribution ?? m.target}:{' '}
                            {m.status === 'approved'
                                ? `approved as ${m.fact_key} (${m.visibility})`
                                : 'rejected'}
                        </li>
                    ))}
                </ul>
            </li>
        );
    }

    return (
        <li className="rounded-md border border-blue-300 bg-blue-50 p-3 text-sm text-blue-950 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs font-medium tracking-wide uppercase">
                    {claim.capability_label}
                </p>
                {claim.confidence && (
                    <span
                        className={`rounded px-2 py-0.5 text-xs ${CONFIDENCE[claim.confidence]}`}
                    >
                        {claim.confidence} confidence
                    </span>
                )}
            </div>

            {claim.source_quote && (
                <p className="mt-1 text-xs opacity-80">
                    From your answer: &ldquo;{claim.source_quote}&rdquo;
                </p>
            )}
            {claim.rationale && (
                <p className="text-xs opacity-80">
                    Why these places: {claim.rationale}
                </p>
            )}

            <label className="mt-2 block text-xs font-medium">
                Statement (edit to the wording you want on record)
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
                    {scopes.map((scope) => (
                        <label
                            key={scope.value}
                            className="flex items-center gap-1.5"
                        >
                            <input
                                type="checkbox"
                                checked={picked.includes(scope.value)}
                                onChange={() =>
                                    setPicked((c) =>
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

            <div className="mt-2">
                <p className="text-xs font-medium">
                    Applies to (one fact is recorded for each)
                </p>
                <ul className="mt-1 space-y-1">
                    {targets.map((key) => {
                        const member = open.find((m) => m.target === key);

                        return (
                            <li
                                key={key}
                                className="rounded bg-white/60 p-2 text-xs dark:bg-black/20"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {label(key)}
                                    </span>
                                    <button
                                        type="button"
                                        className="underline"
                                        disabled={targets.length === 1}
                                        onClick={() =>
                                            setTargets((c) =>
                                                c.filter((t) => t !== key),
                                            )
                                        }
                                    >
                                        Remove
                                    </button>
                                </div>
                                {member?.existing?.relation ===
                                    'same_topic' && (
                                    <label className="mt-1 flex items-start gap-1.5">
                                        <input
                                            type="checkbox"
                                            checked={replace.includes(
                                                member.id,
                                            )}
                                            onChange={() =>
                                                setReplace((c) =>
                                                    c.includes(member.id)
                                                        ? c.filter(
                                                              (i) =>
                                                                  i !==
                                                                  member.id,
                                                          )
                                                        : [...c, member.id],
                                                )
                                            }
                                        />
                                        <span>
                                            You already approved a fact on this
                                            topic here (
                                            {member.existing.fact_key}
                                            ): &ldquo;
                                            {member.existing.statement}
                                            &rdquo;. Replace it with this
                                            wording (its history is kept).
                                        </span>
                                    </label>
                                )}
                                {member?.existing?.relation ===
                                    'near_duplicate' && (
                                    <p className="mt-1 text-amber-800 dark:text-amber-200">
                                        Very similar to{' '}
                                        {member.existing.fact_key}: &ldquo;
                                        {member.existing.statement}&rdquo;.
                                        Check it is not a duplicate.
                                    </p>
                                )}
                                {!member && (
                                    <p className="mt-1 opacity-70">
                                        New place. Save your changes to add it.
                                    </p>
                                )}
                            </li>
                        );
                    })}
                </ul>
                <select
                    className={field}
                    value=""
                    onChange={(e) => {
                        if (
                            e.target.value &&
                            !targets.includes(e.target.value)
                        ) {
                            setTargets((c) => [...c, e.target.value]);
                        }
                    }}
                >
                    <option value="">Add another project or role...</option>
                    {options
                        .filter((o) => !targets.includes(`${o.type}:${o.id}`))
                        .map((o) => (
                            <option
                                key={`${o.type}:${o.id}`}
                                value={`${o.type}:${o.id}`}
                            >
                                {o.label}
                            </option>
                        ))}
                </select>
            </div>

            <label className="mt-2 block text-xs font-medium">
                Visibility (you choose; nothing is made public for you)
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

            {Object.values(errors).length > 0 && (
                <ul
                    role="alert"
                    className="mt-2 space-y-1 text-xs text-red-600"
                >
                    {Object.values(errors).map((m) => (
                        <li key={m}>{m}</li>
                    ))}
                </ul>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    className={secondary}
                    disabled={busy || !dirty}
                    onClick={() =>
                        send('patch', updateClaim.url({ group: claim.group }), {
                            statement,
                            scopes: picked,
                            attributions: targets,
                        })
                    }
                >
                    Save changes
                </button>
                <button
                    type="button"
                    className={primary}
                    disabled={busy || dirty}
                    title={dirty ? 'Save your changes first' : undefined}
                    onClick={() =>
                        send('post', approveClaim.url({ group: claim.group }), {
                            visibility,
                            supersede: replace,
                        })
                    }
                >
                    Approve{' '}
                    {open.length > 1 ? `all ${open.length} facts` : 'fact'}
                </button>
                <button
                    type="button"
                    className={secondary}
                    disabled={busy}
                    onClick={() =>
                        send(
                            'post',
                            rejectClaim.url({ group: claim.group }),
                            {},
                        )
                    }
                >
                    Reject
                </button>
                {others.length > 0 && (
                    <span className="flex items-center gap-1">
                        <select
                            className="rounded-md border border-neutral-300 bg-white px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                            value={mergeInto}
                            onChange={(e) => setMergeInto(e.target.value)}
                        >
                            <option value="">Merge into...</option>
                            {others.map((o) => (
                                <option key={o.group} value={o.group}>
                                    {o.capability_label}:{' '}
                                    {o.statement.slice(0, 40)}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            className={secondary}
                            disabled={busy || mergeInto === ''}
                            onClick={() =>
                                send(
                                    'post',
                                    mergeClaim.url({ group: claim.group }),
                                    {
                                        into: mergeInto,
                                    },
                                )
                            }
                        >
                            Merge
                        </button>
                    </span>
                )}
            </div>
            {dirty && (
                <p className="mt-1 text-xs opacity-80">
                    Save your changes before approving.
                </p>
            )}
        </li>
    );
}

/**
 * The optional "expand into separate facts" step for one answer. The single proposal the answer
 * already produced stays available above; these claims are an alternative the user reviews one by
 * one. Nothing here is approved automatically.
 */
export function ClaimExpansion({
    answerId,
    expansion,
    options,
    scopes,
}: {
    answerId: number;
    expansion: AnswerExpansion;
    options: ClarificationAttributionOption[];
    scopes: ClarificationScope[];
}) {
    const [busy, setBusy] = useState(false);

    if (!expansion.can_expand) {
        return null;
    }

    const run = (force: boolean) => {
        setBusy(true);
        router.post(
            expand.url({ answer: answerId }),
            { force },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const running = expansion.state === 'running';
    const claims = expansion.claims ?? [];
    const pending = claims.filter((c) => c.pending);

    return (
        <div className="mt-3 border-t border-neutral-200 pt-3 dark:border-neutral-800">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    className={secondary}
                    disabled={busy || running}
                    onClick={() => run(expansion.state === 'ready')}
                    title="Uses your local model once. Nothing is approved for you."
                >
                    {running
                        ? 'Reading your answer...'
                        : expansion.state === 'ready'
                          ? 'Read this answer again'
                          : 'Expand into separate facts'}
                </button>
                {expansion.state === 'ready' && (
                    <span className="text-xs text-neutral-500">
                        {claims.length} suggested{' '}
                        {claims.length === 1 ? 'claim' : 'claims'}
                        {expansion.model ? ` · ${expansion.model}` : ''}
                        {expansion.latency_ms
                            ? ` · ${Math.round(expansion.latency_ms / 1000)}s`
                            : ''}
                        {expansion.usage?.output_tokens
                            ? ` · ${expansion.usage.input_tokens} in / ${expansion.usage.output_tokens} out tokens`
                            : ''}
                    </span>
                )}
            </div>

            {expansion.state === 'failed' && (
                <p className="mt-2 text-xs text-red-600">
                    {expansion.failure ?? 'The expansion did not finish.'} You
                    can still approve the single proposal above.
                </p>
            )}

            {expansion.stale && claims.length > 0 && (
                <p className="mt-2 text-xs text-amber-700 dark:text-amber-300">
                    Your evidence has changed since these were suggested. Read
                    the answer again to compare against what is recorded now.
                </p>
            )}

            {claims.length > 0 && (
                <ul className="mt-3 space-y-3">
                    {claims.map((claim) => (
                        <ClaimCard
                            key={`${claim.group}-${claim.members.map((m) => `${m.id}${m.status}`).join()}`}
                            claim={claim}
                            others={pending.filter(
                                (c) => c.group !== claim.group,
                            )}
                            options={options}
                            scopes={scopes}
                        />
                    ))}
                </ul>
            )}

            {(expansion.dropped?.length ?? 0) > 0 && (
                <details className="mt-2 text-xs text-neutral-500">
                    <summary className="cursor-pointer">
                        {expansion.dropped!.length} suggestion
                        {expansion.dropped!.length === 1
                            ? ' was'
                            : 's were'}{' '}
                        dropped for going beyond your answer
                    </summary>
                    <ul className="mt-1 list-disc pl-4">
                        {expansion.dropped!.map((d, i) => (
                            <li key={i}>
                                {d.reason}: {d.statement}
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {(expansion.followups?.length ?? 0) > 0 && (
                <div className="mt-3 text-xs">
                    <p className="font-medium">Questions that could add more</p>
                    <ul className="mt-1 space-y-2">
                        {expansion.followups!.map((f) => (
                            <li key={f.index}>
                                {f.question}{' '}
                                {f.asked_question_id ? (
                                    <span className="text-neutral-500">
                                        (added to your interview,{' '}
                                        {f.asked_status})
                                    </span>
                                ) : (
                                    <button
                                        type="button"
                                        className="underline underline-offset-2"
                                        onClick={() =>
                                            router.post(
                                                askFollowup.url({
                                                    expansion:
                                                        expansion.expansion_id!,
                                                    index: f.index,
                                                }),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Ask this
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
