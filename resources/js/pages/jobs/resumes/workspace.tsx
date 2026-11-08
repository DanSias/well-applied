import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AppShell from '@/layouts/app-shell';
import { show as jobShow } from '@/routes/jobs';
import {
    current as makeCurrent,
    preferred as markPreferred,
    replacementFacts,
    show as workspaceShow,
} from '@/routes/jobs/profile-resumes';
import { store as saveRevision } from '@/routes/jobs/profile-resumes/revisions';
import { pdf as resumePdf } from '@/routes/resume-variants';
import type {
    ReplacementFact,
    ResumeBullet,
    ResumeVariantShowProps,
    WorkspaceAttention,
} from '@/types/resume-variant';

type DraftBullet = {
    uid: string;
    id: number | null;
    factKey: string | null;
    replaces: number | null;
    text: string;
    original: string;
    citations: ResumeBullet['citations'];
    answers: string[];
};

type DraftRole = { roleId: number; bullets: DraftBullet[] };

const BUTTON =
    'rounded-md border border-neutral-300 px-2 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-40 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800';
const PRIMARY =
    'inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300';

let counter = 0;
const uid = () => `b${++counter}`;

function when(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString() : '';
}

function period(role: { start_year: number; end_year: number | null }): string {
    return `${role.start_year} to ${role.end_year ?? 'present'}`;
}

function Chip({
    children,
    tone = 'neutral',
}: {
    children: React.ReactNode;
    tone?: 'neutral' | 'green' | 'amber';
}) {
    const tones = {
        neutral:
            'border-neutral-300 bg-neutral-100 text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
        green: 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300',
        amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
    };

    return (
        <span
            className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${tones[tone]}`}
        >
            {children}
        </span>
    );
}

function NeedsAttention({ items }: { items: WorkspaceAttention[] }) {
    if (items.length === 0) {
        return (
            <p className="mt-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                Nothing needs attention.
            </p>
        );
    }

    const review = items.filter((i) => i.severity !== 'informational');

    return (
        <section
            aria-label="Needs attention"
            className="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
        >
            <h2 className="font-semibold">
                Needs attention
                <span className="ml-2 font-normal">
                    {review.length > 0
                        ? `${review.length} to check`
                        : 'optional improvements only'}
                    {items.length > review.length &&
                        review.length > 0 &&
                        `, ${items.length - review.length} optional`}
                </span>
            </h2>
            <ul className="mt-2 space-y-3">
                {items.map((item, i) => (
                    <li key={`${item.title}-${i}`}>
                        <p className="font-medium">
                            {item.title}
                            {item.severity === 'informational' && (
                                <span className="ml-2 text-xs font-normal opacity-75">
                                    optional
                                </span>
                            )}
                        </p>
                        {item.messages.map((m) => (
                            <p key={m}>{m}</p>
                        ))}
                        {item.claim && (
                            <p className="italic">&ldquo;{item.claim}&rdquo;</p>
                        )}
                        {item.facts.length > 0 && (
                            <details className="mt-1 text-xs">
                                <summary className="cursor-pointer">
                                    Evidence involved
                                </summary>
                                <ul className="list-disc pl-5">
                                    {item.facts.map((f) => (
                                        <li key={f.key}>{f.statement}</li>
                                    ))}
                                </ul>
                            </details>
                        )}
                        {item.available.length > 0 && (
                            <details className="mt-1 text-xs">
                                <summary className="cursor-pointer">
                                    Evidence you could use instead
                                </summary>
                                <ul className="list-disc pl-5">
                                    {item.available.map((f) => (
                                        <li key={f.key}>{f.statement}</li>
                                    ))}
                                </ul>
                            </details>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Picker({
    jobId,
    variantId,
    roleId,
    onPick,
    onClose,
}: {
    jobId: number;
    variantId: number;
    roleId: number;
    onPick: (fact: ReplacementFact) => void;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [facts, setFacts] = useState<ReplacementFact[] | null>(null);

    useEffect(() => {
        const handle = setTimeout(() => {
            const url = replacementFacts.url(
                { jobPosting: jobId, resumeVariant: variantId },
                { query: { role_id: roleId, q: query } },
            );

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((body: { facts: ReplacementFact[] }) =>
                    setFacts(body.facts),
                )
                .catch(() => setFacts([]));
        }, 200);

        return () => clearTimeout(handle);
    }, [jobId, variantId, roleId, query]);

    return (
        <div className="mt-2 rounded-md border border-neutral-300 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-950">
            <div className="flex items-center gap-2">
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search your accomplishments for this role"
                    aria-label="Search accomplishments"
                    className="w-full rounded-md border border-neutral-300 bg-white px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                />
                <button type="button" className={BUTTON} onClick={onClose}>
                    Close
                </button>
            </div>
            <ul className="mt-2 max-h-80 space-y-2 overflow-y-auto">
                {facts === null && (
                    <li className="text-xs text-neutral-500">Loading…</li>
                )}
                {facts?.length === 0 && (
                    <li className="text-xs text-neutral-500">
                        No other accomplishment fits this role.
                    </li>
                )}
                {facts?.map((f) => (
                    <li
                        key={f.key}
                        className="flex items-start justify-between gap-3 rounded-md border border-neutral-200 bg-white p-2 text-sm dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div>
                            {f.reason && <Chip tone="green">{f.reason}</Chip>}
                            <p className="mt-1 text-neutral-800 dark:text-neutral-200">
                                {f.statement}
                            </p>
                            <p className="mt-0.5 text-xs text-neutral-500">
                                {f.project ?? 'Role'}
                                {f.visibility === 'restricted' &&
                                    ' · restricted: confirm the wording is general'}
                            </p>
                        </div>
                        <button
                            type="button"
                            className={BUTTON}
                            onClick={() => onPick(f)}
                        >
                            Use
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function ResumeWorkspace({
    job,
    resume,
    workspace,
    tailoring = null,
}: ResumeVariantShowProps) {
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const ws = workspace!;
    const variant = ws.variant;

    const initialRoles = useMemo<DraftRole[]>(
        () =>
            resume.experience.map((r) => ({
                roleId: r.role_id,
                bullets: r.bullets.map((b) => ({
                    uid: uid(),
                    id: b.id,
                    factKey: null,
                    replaces: null,
                    text: b.text,
                    original: b.text,
                    citations: b.citations,
                    answers: b.answers ?? [],
                })),
            })),
        [resume.experience],
    );
    const initialSkills = useMemo(
        () => resume.skills.map((s) => s.id),
        [resume.skills],
    );

    const [tab, setTab] = useState<'edit' | 'preview'>('edit');
    const [summary, setSummary] = useState(resume.summary ?? '');
    const [roles, setRoles] = useState<DraftRole[]>(initialRoles);
    const [skills, setSkills] = useState<number[]>(initialSkills);
    const [picking, setPicking] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // A different resume (history link, save) starts a fresh draft.
    useEffect(() => {
        setSummary(resume.summary ?? '');
        setRoles(initialRoles);
        setSkills(initialSkills);
        setPicking(null);
    }, [variant.id, resume.summary, initialRoles, initialSkills]);

    const payload = {
        summary,
        roles: roles.map((r) => ({
            role_id: r.roleId,
            bullets: r.bullets.map((b) =>
                b.id !== null
                    ? { id: b.id, text: b.text }
                    : {
                          fact_key: b.factKey,
                          replaces: b.replaces,
                          text: b.text,
                      },
            ),
        })),
        skills,
    };
    const dirty =
        JSON.stringify(payload) !==
        JSON.stringify({
            summary: resume.summary ?? '',
            roles: initialRoles.map((r) => ({
                role_id: r.roleId,
                bullets: r.bullets.map((b) => ({ id: b.id, text: b.original })),
            })),
            skills: initialSkills,
        });

    const patchRole = (
        roleId: number,
        fn: (bullets: DraftBullet[]) => DraftBullet[],
    ) =>
        setRoles((all) =>
            all.map((r) =>
                r.roleId === roleId ? { ...r, bullets: fn(r.bullets) } : r,
            ),
        );

    const move = (roleId: number, index: number, by: number) =>
        patchRole(roleId, (bullets) => {
            const next = [...bullets];
            const target = index + by;

            if (target < 0 || target >= next.length) {
                return next;
            }

            [next[index], next[target]] = [next[target], next[index]];

            return next;
        });

    const pick = (
        roleId: number,
        at: number | 'new',
        fact: ReplacementFact,
    ) => {
        const entry = (replaces: number | null): DraftBullet => ({
            uid: uid(),
            id: null,
            factKey: fact.key,
            replaces,
            text: fact.statement,
            original: '',
            citations: [
                {
                    key: fact.key,
                    statement: fact.statement,
                    visibility: fact.visibility,
                    attribution: {
                        employer: null,
                        role: null,
                        project: fact.project,
                    },
                },
            ],
            answers: [],
        });

        patchRole(roleId, (bullets) => {
            if (at === 'new') {
                return [...bullets, entry(null)];
            }

            return bullets.map((b, i) =>
                i === at ? entry(b.id ?? b.replaces) : b,
            );
        });
        setPicking(null);
    };

    const save = () => {
        setSaving(true);
        router.post(
            saveRevision.url({
                jobPosting: job.id,
                resumeVariant: variant.id,
            }),
            payload,
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    const discard = () => {
        setSummary(resume.summary ?? '');
        setRoles(initialRoles);
        setSkills(initialSkills);
        setPicking(null);
    };

    const post = (
        url: string,
        data: Record<string, string | number | boolean | null> = {},
    ) => router.post(url, data, { preserveScroll: true });

    const pdfUrl = resumePdf.url({ resumeVariant: variant.id });
    const fieldErrors = Object.entries(errors).filter(
        ([k]) =>
            k === 'pdf' || k === 'resume' || k === 'roles' || k === 'skills',
    );

    return (
        <AppShell>
            <Head title={`Resume: ${job.title} at ${job.company}`} />

            <Link
                href={jobShow.url({ jobPosting: job.id })}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← {job.title} at {job.company}
            </Link>

            <div className="mt-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        Resume for {job.title}
                    </h1>
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        {job.company}
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <Chip>{variant.label}</Chip>
                        {variant.current && <Chip tone="green">Current</Chip>}
                        {variant.preferred && <Chip tone="amber">Final</Chip>}
                        {variant.profile && (
                            <span className="text-xs text-neutral-500 dark:text-neutral-400">
                                Profile: {variant.profile.name}, version{' '}
                                {variant.profile.version}
                            </span>
                        )}
                        {variant.pages !== null && (
                            <span className="text-xs text-neutral-500 dark:text-neutral-400">
                                {variant.pages}{' '}
                                {variant.pages === 1 ? 'page' : 'pages'}
                            </span>
                        )}
                        <span className="text-xs text-neutral-500 dark:text-neutral-400">
                            {variant.kind === 'revision' ? 'Saved' : 'Written'}{' '}
                            {when(variant.generated_at)}
                        </span>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <a href={pdfUrl} className={PRIMARY}>
                        Download PDF
                    </a>
                    <button
                        type="button"
                        className={BUTTON}
                        onClick={() =>
                            post(
                                markPreferred.url({
                                    jobPosting: job.id,
                                    resumeVariant: variant.id,
                                }),
                                { preferred: !variant.preferred },
                            )
                        }
                    >
                        {variant.preferred ? 'Clear final' : 'Mark as final'}
                    </button>
                </div>
            </div>

            {!variant.current && (
                <p className="mt-3 rounded-md border border-neutral-300 bg-neutral-50 p-3 text-sm text-neutral-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    This is an earlier resume.{' '}
                    <button
                        type="button"
                        className="font-medium underline underline-offset-2"
                        onClick={() =>
                            post(
                                makeCurrent.url({
                                    jobPosting: job.id,
                                    resumeVariant: variant.id,
                                }),
                            )
                        }
                    >
                        Make it the current resume
                    </button>
                </p>
            )}

            <NeedsAttention items={ws.attention} />

            {fieldErrors.map(([key, message]) => (
                <p
                    key={key}
                    role="alert"
                    className="mt-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
                >
                    {message}
                </p>
            ))}

            <div
                role="tablist"
                className="mt-6 flex gap-1 border-b border-neutral-200 dark:border-neutral-800"
            >
                {(['edit', 'preview'] as const).map((t) => (
                    <button
                        key={t}
                        role="tab"
                        type="button"
                        aria-selected={tab === t}
                        onClick={() => setTab(t)}
                        className={`px-3 py-2 text-sm font-medium ${
                            tab === t
                                ? 'border-b-2 border-neutral-900 text-neutral-900 dark:border-neutral-100 dark:text-neutral-100'
                                : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400'
                        }`}
                    >
                        {t === 'edit' ? 'Edit' : 'Preview PDF'}
                    </button>
                ))}
            </div>

            {tab === 'preview' && (
                <div className="mt-4">
                    {dirty && (
                        <p className="mb-2 text-xs text-neutral-500 dark:text-neutral-400">
                            The preview shows the last saved resume. Save your
                            changes to see them here.
                        </p>
                    )}
                    <iframe
                        src={`${pdfUrl}?inline=1`}
                        title="Resume PDF preview"
                        className="h-[80vh] w-full rounded border border-neutral-300 bg-white dark:border-neutral-700"
                    />
                </div>
            )}

            {tab === 'edit' && (
                <div className={`mt-4 ${dirty ? 'pb-24' : ''}`}>
                    <section>
                        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            Summary
                        </h2>
                        <textarea
                            value={summary}
                            onChange={(e) => setSummary(e.target.value)}
                            rows={5}
                            aria-label="Summary"
                            className="mt-2 w-full rounded-md border border-neutral-300 bg-white p-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                        />
                        {errors.summary && (
                            <p
                                role="alert"
                                className="text-sm text-red-600 dark:text-red-400"
                            >
                                {errors.summary}
                            </p>
                        )}
                    </section>

                    {resume.experience.map((role) => {
                        const draft = roles.find(
                            (r) => r.roleId === role.role_id,
                        );

                        if (!draft) {
                            return null;
                        }

                        return (
                            <section key={role.role_id} className="mt-6">
                                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                    {role.employer}
                                    <span className="font-normal text-neutral-500 dark:text-neutral-400">
                                        {' · '}
                                        {role.display_title}
                                        {' · '}
                                        {period(role)}
                                    </span>
                                </h2>
                                <ul className="mt-2 space-y-3">
                                    {draft.bullets.map((b, i) => {
                                        const error =
                                            errors[
                                                `roles.${role.role_id}.bullets.${i}`
                                            ] ??
                                            errors[
                                                `roles.${role.role_id}.bullets.${i}.text`
                                            ];

                                        return (
                                            <li
                                                key={b.uid}
                                                className="rounded-md border border-neutral-200 p-3 dark:border-neutral-800"
                                            >
                                                {b.id === null && (
                                                    <div className="mb-1">
                                                        <Chip tone="green">
                                                            New bullet
                                                        </Chip>
                                                    </div>
                                                )}
                                                <textarea
                                                    value={b.text}
                                                    onChange={(e) =>
                                                        patchRole(
                                                            role.role_id,
                                                            (list) =>
                                                                list.map(
                                                                    (x, j) =>
                                                                        j === i
                                                                            ? {
                                                                                  ...x,
                                                                                  text: e
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : x,
                                                                ),
                                                        )
                                                    }
                                                    rows={3}
                                                    aria-label={`Bullet ${i + 1} under ${role.employer}`}
                                                    className="w-full rounded-md border border-neutral-300 bg-white p-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                                />
                                                {error && (
                                                    <p
                                                        role="alert"
                                                        className="mt-1 text-sm text-red-600 dark:text-red-400"
                                                    >
                                                        {error}
                                                    </p>
                                                )}
                                                {b.answers.length > 0 && (
                                                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                                        Answers:{' '}
                                                        {b.answers
                                                            .map((a) =>
                                                                a.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                ),
                                                            )
                                                            .join(', ')}
                                                    </p>
                                                )}
                                                <details className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                                    <summary className="cursor-pointer">
                                                        Evidence
                                                    </summary>
                                                    <ul className="list-disc pl-5">
                                                        {b.citations.map(
                                                            (c) => (
                                                                <li key={c.key}>
                                                                    {
                                                                        c.statement
                                                                    }
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </details>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        className={BUTTON}
                                                        disabled={i === 0}
                                                        onClick={() =>
                                                            move(
                                                                role.role_id,
                                                                i,
                                                                -1,
                                                            )
                                                        }
                                                    >
                                                        Move up
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className={BUTTON}
                                                        disabled={
                                                            i ===
                                                            draft.bullets
                                                                .length -
                                                                1
                                                        }
                                                        onClick={() =>
                                                            move(
                                                                role.role_id,
                                                                i,
                                                                1,
                                                            )
                                                        }
                                                    >
                                                        Move down
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className={BUTTON}
                                                        onClick={() =>
                                                            setPicking(
                                                                picking ===
                                                                    `${role.role_id}:${b.uid}`
                                                                    ? null
                                                                    : `${role.role_id}:${b.uid}`,
                                                            )
                                                        }
                                                    >
                                                        Replace
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className={BUTTON}
                                                        disabled={
                                                            draft.bullets
                                                                .length === 1
                                                        }
                                                        onClick={() =>
                                                            patchRole(
                                                                role.role_id,
                                                                (list) =>
                                                                    list.filter(
                                                                        (
                                                                            _,
                                                                            j,
                                                                        ) =>
                                                                            j !==
                                                                            i,
                                                                    ),
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </button>
                                                </div>
                                                {picking ===
                                                    `${role.role_id}:${b.uid}` && (
                                                    <Picker
                                                        jobId={job.id}
                                                        variantId={variant.id}
                                                        roleId={role.role_id}
                                                        onPick={(f) =>
                                                            pick(
                                                                role.role_id,
                                                                i,
                                                                f,
                                                            )
                                                        }
                                                        onClose={() =>
                                                            setPicking(null)
                                                        }
                                                    />
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                                {errors[`roles.${role.role_id}`] && (
                                    <p
                                        role="alert"
                                        className="mt-1 text-sm text-red-600 dark:text-red-400"
                                    >
                                        {errors[`roles.${role.role_id}`]}
                                    </p>
                                )}
                                <button
                                    type="button"
                                    className={`${BUTTON} mt-2`}
                                    onClick={() =>
                                        setPicking(
                                            picking === `${role.role_id}:new`
                                                ? null
                                                : `${role.role_id}:new`,
                                        )
                                    }
                                >
                                    Add a bullet
                                </button>
                                {picking === `${role.role_id}:new` && (
                                    <Picker
                                        jobId={job.id}
                                        variantId={variant.id}
                                        roleId={role.role_id}
                                        onPick={(f) =>
                                            pick(role.role_id, 'new', f)
                                        }
                                        onClose={() => setPicking(null)}
                                    />
                                )}
                            </section>
                        );
                    })}

                    <section className="mt-6">
                        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            Skills
                        </h2>
                        <ul className="mt-2 flex flex-wrap gap-2">
                            {skills.map((id) => {
                                const skill = resume.skills.find(
                                    (s) => s.id === id,
                                );

                                return (
                                    <li key={id}>
                                        <button
                                            type="button"
                                            title="Remove this skill"
                                            onClick={() =>
                                                setSkills((all) =>
                                                    all.filter((s) => s !== id),
                                                )
                                            }
                                            className="rounded-full border border-neutral-300 px-2 py-0.5 text-xs text-neutral-700 hover:bg-red-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-red-950"
                                        >
                                            {skill?.name} ×
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                </div>
            )}

            <section className="mt-8">
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    History
                </h2>
                <ul className="mt-2 divide-y divide-neutral-200 rounded-md border border-neutral-200 text-sm dark:divide-neutral-800 dark:border-neutral-800">
                    {ws.history.map((h) => (
                        <li
                            key={h.id}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <div>
                                <span className="font-medium text-neutral-900 dark:text-neutral-100">
                                    {h.label}
                                </span>{' '}
                                {h.current && <Chip tone="green">Current</Chip>}{' '}
                                {h.preferred && <Chip tone="amber">Final</Chip>}
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                    {h.summary} · {when(h.created_at)}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                {h.id !== variant.id ? (
                                    <Link
                                        href={workspaceShow.url({
                                            jobPosting: job.id,
                                            resumeVariant: h.id,
                                        })}
                                        className={BUTTON}
                                    >
                                        Open
                                    </Link>
                                ) : (
                                    <span className="text-xs text-neutral-500">
                                        Viewing
                                    </span>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </section>

            <details className="mt-6 rounded-lg border border-neutral-200 p-4 text-sm dark:border-neutral-800">
                <summary className="cursor-pointer font-semibold text-neutral-900 dark:text-neutral-100">
                    Details
                </summary>
                <dl className="mt-3 grid gap-x-6 gap-y-1 text-xs text-neutral-500 sm:grid-cols-2 dark:text-neutral-400">
                    <div>Resume: {variant.id}</div>
                    <div>
                        Made from:{' '}
                        {variant.parent_id ?? 'your approved profile'}
                    </div>
                    <div>
                        Selection: {resume.selection_generated_by ?? 'n/a'}
                    </div>
                    <div>Wording: {resume.wording_generated_by ?? 'n/a'}</div>
                    <div>Schema: {resume.schema_version}</div>
                </dl>
                {ws.edit_log.length > 0 && (
                    <div className="mt-3">
                        <p className="font-medium text-neutral-800 dark:text-neutral-200">
                            Changes from the resume it was made from
                        </p>
                        <ul className="mt-1 list-disc pl-5 text-xs text-neutral-600 dark:text-neutral-400">
                            {ws.edit_log.map((e, i) => (
                                <li key={i}>
                                    {e.label}
                                    {e.note && ` (${e.note})`}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {tailoring && tailoring.gaps.length > 0 && (
                    <div className="mt-3">
                        <p className="font-medium text-neutral-800 dark:text-neutral-200">
                            Asked for by the job, not supported directly (not
                            claimed)
                        </p>
                        <ul className="mt-1 list-disc pl-5 text-xs text-neutral-600 dark:text-neutral-400">
                            {tailoring.gaps.map((g) => (
                                <li key={g.label}>
                                    {g.label}
                                    {g.status === 'limitation'
                                        ? ' (you confirmed no direct experience)'
                                        : ' (no approved evidence)'}
                                    {g.required ? ', required' : ', preferred'}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {tailoring && tailoring.added.length > 0 && (
                    <div className="mt-3">
                        <p className="font-medium text-neutral-800 dark:text-neutral-200">
                            Brought in because the job needs it
                        </p>
                        <ul className="mt-1 list-disc pl-5 text-xs text-neutral-600 dark:text-neutral-400">
                            {tailoring.added.map((f) => (
                                <li key={f.key}>{f.statement}</li>
                            ))}
                        </ul>
                    </div>
                )}
                {tailoring && tailoring.dropped.length > 0 && (
                    <div className="mt-3">
                        <p className="font-medium text-neutral-800 dark:text-neutral-200">
                            In the general resume, left out here
                        </p>
                        <ul className="mt-1 list-disc pl-5 text-xs text-neutral-600 dark:text-neutral-400">
                            {tailoring.dropped.map((f) => (
                                <li key={f.key}>{f.statement}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </details>

            {dirty && tab === 'edit' && (
                <div className="fixed inset-x-0 bottom-0 z-10 border-t border-neutral-300 bg-white p-3 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                    <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4">
                        <p className="text-sm text-neutral-700 dark:text-neutral-300">
                            Unsaved changes. Saving creates a new version; the
                            current one is kept.
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className={BUTTON}
                                onClick={discard}
                                disabled={saving}
                            >
                                Discard
                            </button>
                            <button
                                type="button"
                                className={PRIMARY}
                                onClick={save}
                                disabled={saving}
                            >
                                {saving ? 'Saving…' : 'Save revision'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
