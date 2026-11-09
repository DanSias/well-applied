import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { ReadinessReview } from '@/components/readiness-review';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, ButtonAnchor, ButtonLink } from '@/components/ui/button';
import { FieldError, TextArea, TextInput } from '@/components/ui/field';
import { Card, Disclosure, SectionTitle } from '@/components/ui/surface';
import { Tabs } from '@/components/ui/tabs';
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
    ReadinessTarget,
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

type Mode = 'edit' | 'readiness' | 'preview';

let counter = 0;
const uid = () => `b${++counter}`;

function when(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString() : '';
}

function period(role: { start_year: number; end_year: number | null }): string {
    return `${role.start_year} to ${role.end_year ?? 'present'}`;
}

/**
 * One consolidated summary of everything the workspace wants the user to
 * check. Collapsed to a single line; the detail is available on demand.
 */
function AttentionSummary({
    items,
    errors,
}: {
    items: WorkspaceAttention[];
    errors: [string, string][];
}) {
    const review = items.filter((i) => i.severity !== 'informational');
    const optional = items.length - review.length;

    return (
        <div className="space-y-3">
            {errors.length > 0 && (
                <Alert tone="bad" role="alert">
                    <ul className="space-y-1">
                        {errors.map(([key, message]) => (
                            <li key={key}>{message}</li>
                        ))}
                    </ul>
                </Alert>
            )}

            {items.length === 0 ? (
                <Alert tone="ok" role="status">
                    Nothing needs attention.
                </Alert>
            ) : (
                <Alert tone={review.length > 0 ? 'warn' : 'neutral'}>
                    <Disclosure
                        className="[&>div]:pl-0"
                        summary={
                            <span className="text-fg font-medium">
                                {review.length > 0
                                    ? `${review.length} ${review.length === 1 ? 'item' : 'items'} to check`
                                    : 'Optional improvements only'}
                                {optional > 0 && review.length > 0 && (
                                    <span className="text-fg-muted font-normal">
                                        {' '}
                                        · {optional} optional
                                    </span>
                                )}
                                <span className="text-fg-subtle ml-2 text-xs font-normal">
                                    Show details
                                </span>
                            </span>
                        }
                    >
                        <ul className="divide-line/70 mt-3 divide-y">
                            {items.map((item, i) => (
                                <li
                                    key={`${item.title}-${i}`}
                                    className="py-3 first:pt-0 last:pb-0"
                                >
                                    <p className="text-fg font-medium break-words">
                                        {item.title}
                                        {item.severity === 'informational' && (
                                            <Badge className="ml-2">
                                                optional
                                            </Badge>
                                        )}
                                    </p>
                                    {item.messages.map((m) => (
                                        <p
                                            key={m}
                                            className="text-fg-muted mt-0.5 break-words"
                                        >
                                            {m}
                                        </p>
                                    ))}
                                    {item.claim && (
                                        <p className="text-fg-muted mt-1 break-words italic">
                                            &ldquo;{item.claim}&rdquo;
                                        </p>
                                    )}
                                    {item.facts.length > 0 && (
                                        <Disclosure
                                            quiet
                                            className="mt-2"
                                            summary="Evidence involved"
                                        >
                                            <ul className="text-fg-subtle list-disc space-y-1 pl-4 text-xs break-words">
                                                {item.facts.map((f) => (
                                                    <li key={f.key}>
                                                        {f.statement}
                                                    </li>
                                                ))}
                                            </ul>
                                        </Disclosure>
                                    )}
                                    {item.available.length > 0 && (
                                        <Disclosure
                                            quiet
                                            className="mt-2"
                                            summary="Evidence you could use instead"
                                        >
                                            <ul className="text-fg-subtle list-disc space-y-1 pl-4 text-xs break-words">
                                                {item.available.map((f) => (
                                                    <li key={f.key}>
                                                        {f.statement}
                                                    </li>
                                                ))}
                                            </ul>
                                        </Disclosure>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Disclosure>
                </Alert>
            )}
        </div>
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
        <div className="rounded-card border-line-strong bg-raised shadow-float mt-3 border p-3">
            <div className="flex items-center gap-2">
                <TextInput
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search your accomplishments for this role"
                    aria-label="Search accomplishments"
                    autoFocus
                />
                <Button variant="ghost" size="sm" onClick={onClose}>
                    Close
                </Button>
            </div>
            <ul className="mt-3 max-h-80 space-y-2 overflow-y-auto pr-1">
                {facts === null && (
                    <li className="text-fg-subtle px-1 text-sm">Loading…</li>
                )}
                {facts?.length === 0 && (
                    <li className="text-fg-subtle px-1 text-sm">
                        No other accomplishment fits this role.
                    </li>
                )}
                {facts?.map((f) => (
                    <li
                        key={f.key}
                        className="rounded-control border-line bg-surface flex items-start justify-between gap-3 border p-3"
                    >
                        <div className="min-w-0">
                            {f.reason && <Badge tone="ok">{f.reason}</Badge>}
                            <p
                                className={`text-fg text-sm leading-relaxed break-words ${f.reason ? 'mt-1.5' : ''}`}
                            >
                                {f.statement}
                            </p>
                            <p className="text-fg-subtle mt-1 text-xs">
                                {f.project ?? 'Role'}
                                {f.visibility === 'restricted' &&
                                    ' · restricted: confirm the wording is general'}
                            </p>
                        </div>
                        <Button size="sm" onClick={() => onPick(f)}>
                            Use
                        </Button>
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
    readiness,
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

    const [tab, setTab] = useState<Mode>('edit');
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

    // Take an improvement to the place in the editor it concerns.
    const goTo = (target: ReadinessTarget) => {
        if (target === null) {
            return;
        }

        setTab('edit');

        if (target.type === 'role_fact') {
            setPicking(`${target.role_id}:new`);
        }

        setTimeout(() => {
            const id =
                target.type === 'summary'
                    ? 'summary-field'
                    : target.type === 'skills'
                      ? 'skills-section'
                      : target.type === 'bullet'
                        ? `bullet-${target.bullet_id}`
                        : `role-${target.role_id}`;
            const element = document.getElementById(id);

            element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            element?.focus?.();
        }, 50);
    };

    const pdfUrl = resumePdf.url({ resumeVariant: variant.id });
    const fieldErrors = Object.entries(errors).filter(
        ([k]) =>
            k === 'pdf' || k === 'resume' || k === 'roles' || k === 'skills',
    );

    const markFinal = () =>
        post(
            markPreferred.url({
                jobPosting: job.id,
                resumeVariant: variant.id,
            }),
            { preferred: !variant.preferred },
        );

    // Exactly one obvious next step for the user, in priority order.
    // Presentation only: it never decides anything the server enforces.
    const reviewed = readiness?.state === 'ready';
    const next: 'save' | 'current' | 'review' | 'final' | 'download' = dirty
        ? 'save'
        : !variant.current
          ? 'current'
          : !reviewed
            ? 'review'
            : !variant.preferred
              ? 'final'
              : 'download';

    const readinessBadge = (() => {
        if (!readiness) {
            return undefined;
        }

        if (readiness.state === 'ready' && readiness.result) {
            const missing = readiness.result.counts.missing;

            return missing > 0 ? (
                <Badge tone="bad">{missing} missing</Badge>
            ) : (
                <Badge tone="ok">Reviewed</Badge>
            );
        }

        if (readiness.state === 'stale') {
            return <Badge tone="warn">Out of date</Badge>;
        }

        if (readiness.state === 'queued' || readiness.state === 'running') {
            return <Badge tone="accent">Running</Badge>;
        }

        return undefined;
    })();

    return (
        <AppShell>
            <Head title={`Resume: ${job.title} at ${job.company}`} />

            <Link
                href={jobShow.url({ jobPosting: job.id })}
                className="rounded-control text-fg-muted hover:text-fg inline-flex max-w-full items-center gap-1 text-sm"
            >
                <span aria-hidden="true">←</span>
                <span className="truncate">
                    {job.title} at {job.company}
                </span>
            </Link>

            <header className="mt-4 flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
                <div className="min-w-0 flex-1 basis-80">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-fg text-2xl font-semibold tracking-tight break-words">
                            {job.title}
                        </h1>
                        {variant.current && (
                            <Badge tone="accent">Current</Badge>
                        )}
                        {variant.preferred && <Badge tone="ok">Final</Badge>}
                        {!variant.current && <Badge>Earlier version</Badge>}
                    </div>
                    <p className="text-fg-muted mt-1 text-base">
                        {job.company}
                    </p>
                    <p className="text-fg-subtle mt-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                        <span className="text-fg-muted font-medium">
                            {variant.label}
                        </span>
                        {variant.pages !== null && (
                            <>
                                <span aria-hidden="true">·</span>
                                <span>
                                    {variant.pages}{' '}
                                    {variant.pages === 1 ? 'page' : 'pages'}
                                </span>
                            </>
                        )}
                        <span aria-hidden="true">·</span>
                        <span>
                            {variant.kind === 'revision' ? 'Saved' : 'Written'}{' '}
                            {when(variant.generated_at)}
                        </span>
                        {variant.profile && (
                            <>
                                <span aria-hidden="true">·</span>
                                <span>
                                    {variant.profile.name} v
                                    {variant.profile.version}
                                </span>
                            </>
                        )}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <ButtonAnchor
                        href={pdfUrl}
                        variant={next === 'download' ? 'primary' : 'secondary'}
                    >
                        Download PDF
                    </ButtonAnchor>
                    <Button
                        variant={next === 'final' ? 'primary' : 'secondary'}
                        onClick={markFinal}
                    >
                        {variant.preferred ? 'Clear final' : 'Mark as final'}
                    </Button>
                </div>
            </header>

            {!variant.current && (
                <Alert tone="accent" className="mt-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <span>
                            This is an earlier version of the resume. Editing it
                            creates a new version from here.
                        </span>
                        <Button
                            variant={
                                next === 'current' ? 'primary' : 'secondary'
                            }
                            size="sm"
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
                        </Button>
                    </div>
                </Alert>
            )}

            <div className="mt-5">
                <AttentionSummary items={ws.attention} errors={fieldErrors} />
            </div>

            <div className="mt-8 flex flex-wrap items-center justify-between gap-3">
                <Tabs<Mode>
                    label="Resume workspace mode"
                    idPrefix="workspace"
                    value={tab}
                    onChange={setTab}
                    items={[
                        { id: 'edit', label: 'Edit' },
                        {
                            id: 'readiness',
                            label: 'Readiness',
                            badge: readinessBadge,
                        },
                        { id: 'preview', label: 'Preview' },
                    ]}
                />
                {next === 'review' && tab !== 'readiness' && (
                    <Button
                        variant="primary"
                        onClick={() => setTab('readiness')}
                    >
                        Review readiness
                    </Button>
                )}
            </div>

            <div
                role="tabpanel"
                id="workspace-panel"
                aria-labelledby={`workspace-${tab}`}
                className={`mt-5 ${dirty && tab === 'edit' ? 'pb-28' : ''}`}
            >
                {tab === 'preview' && (
                    <div>
                        {dirty && (
                            <Alert tone="warn" className="mb-3">
                                The preview shows the last saved resume. Save
                                your changes to see them here.
                            </Alert>
                        )}
                        <Card className="overflow-hidden p-1.5">
                            <iframe
                                src={`${pdfUrl}?inline=1`}
                                title="Resume PDF preview"
                                className="rounded-control h-[80vh] min-h-96 w-full bg-white"
                            />
                        </Card>
                    </div>
                )}

                {tab === 'readiness' && readiness && (
                    <div className="max-w-4xl">
                        <ReadinessReview
                            jobId={job.id}
                            variantId={variant.id}
                            readiness={readiness}
                            onOpen={goTo}
                        />
                    </div>
                )}

                {tab === 'edit' && (
                    <div className="max-w-4xl space-y-6">
                        <Card className="p-5">
                            <SectionTitle>
                                <label htmlFor="summary-field">Summary</label>
                            </SectionTitle>
                            <TextArea
                                value={summary}
                                onChange={(e) => setSummary(e.target.value)}
                                rows={5}
                                id="summary-field"
                                className="mt-3"
                                aria-invalid={errors.summary ? true : undefined}
                            />
                            <FieldError>{errors.summary}</FieldError>
                        </Card>

                        {resume.experience.map((role) => {
                            const draft = roles.find(
                                (r) => r.roleId === role.role_id,
                            );

                            if (!draft) {
                                return null;
                            }

                            return (
                                <Card
                                    key={role.role_id}
                                    id={`role-${role.role_id}`}
                                    tabIndex={-1}
                                    className="p-5"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                                        <h2 className="text-fg min-w-0 text-sm font-semibold tracking-tight break-words">
                                            {role.employer}
                                            <span className="text-fg-muted font-normal">
                                                {' · '}
                                                {role.display_title}
                                            </span>
                                        </h2>
                                        <span className="text-fg-subtle text-xs">
                                            {period(role)}
                                        </span>
                                    </div>
                                    <ul className="mt-4 space-y-4">
                                        {draft.bullets.map((b, i) => {
                                            const error =
                                                errors[
                                                    `roles.${role.role_id}.bullets.${i}`
                                                ] ??
                                                errors[
                                                    `roles.${role.role_id}.bullets.${i}.text`
                                                ];
                                            const pickerKey = `${role.role_id}:${b.uid}`;

                                            return (
                                                <li
                                                    key={b.uid}
                                                    className="rounded-control border-line bg-canvas/60 border p-3"
                                                >
                                                    {b.id === null && (
                                                        <div className="mb-2">
                                                            <Badge tone="ok">
                                                                New bullet
                                                            </Badge>
                                                        </div>
                                                    )}
                                                    <TextArea
                                                        value={b.text}
                                                        onChange={(e) =>
                                                            patchRole(
                                                                role.role_id,
                                                                (list) =>
                                                                    list.map(
                                                                        (
                                                                            x,
                                                                            j,
                                                                        ) =>
                                                                            j ===
                                                                            i
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
                                                        id={
                                                            b.id !== null
                                                                ? `bullet-${b.id}`
                                                                : undefined
                                                        }
                                                        aria-label={`Bullet ${i + 1} under ${role.employer}`}
                                                        aria-invalid={
                                                            error
                                                                ? true
                                                                : undefined
                                                        }
                                                    />
                                                    <FieldError>
                                                        {error}
                                                    </FieldError>
                                                    {b.answers.length > 0 && (
                                                        <p className="text-fg-subtle mt-2 text-xs">
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
                                                    <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                                        <Disclosure
                                                            quiet
                                                            summary="Evidence"
                                                        >
                                                            <ul className="text-fg-subtle list-disc space-y-1 pl-4 text-xs break-words">
                                                                {b.citations.map(
                                                                    (c) => (
                                                                        <li
                                                                            key={
                                                                                c.key
                                                                            }
                                                                        >
                                                                            {
                                                                                c.statement
                                                                            }
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </Disclosure>
                                                        <div className="flex flex-wrap items-center gap-1">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={
                                                                    i === 0
                                                                }
                                                                aria-label={`Move bullet ${i + 1} up`}
                                                                onClick={() =>
                                                                    move(
                                                                        role.role_id,
                                                                        i,
                                                                        -1,
                                                                    )
                                                                }
                                                            >
                                                                ↑ Up
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={
                                                                    i ===
                                                                    draft
                                                                        .bullets
                                                                        .length -
                                                                        1
                                                                }
                                                                aria-label={`Move bullet ${i + 1} down`}
                                                                onClick={() =>
                                                                    move(
                                                                        role.role_id,
                                                                        i,
                                                                        1,
                                                                    )
                                                                }
                                                            >
                                                                ↓ Down
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                aria-expanded={
                                                                    picking ===
                                                                    pickerKey
                                                                }
                                                                onClick={() =>
                                                                    setPicking(
                                                                        picking ===
                                                                            pickerKey
                                                                            ? null
                                                                            : pickerKey,
                                                                    )
                                                                }
                                                            >
                                                                Replace
                                                            </Button>
                                                            <Button
                                                                variant="danger"
                                                                size="sm"
                                                                disabled={
                                                                    draft
                                                                        .bullets
                                                                        .length ===
                                                                    1
                                                                }
                                                                aria-label={`Remove bullet ${i + 1}`}
                                                                onClick={() =>
                                                                    patchRole(
                                                                        role.role_id,
                                                                        (
                                                                            list,
                                                                        ) =>
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
                                                            </Button>
                                                        </div>
                                                    </div>
                                                    {picking === pickerKey && (
                                                        <Picker
                                                            jobId={job.id}
                                                            variantId={
                                                                variant.id
                                                            }
                                                            roleId={
                                                                role.role_id
                                                            }
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
                                    <FieldError>
                                        {errors[`roles.${role.role_id}`]}
                                    </FieldError>
                                    <Button
                                        className="mt-4"
                                        size="sm"
                                        aria-expanded={
                                            picking === `${role.role_id}:new`
                                        }
                                        onClick={() =>
                                            setPicking(
                                                picking ===
                                                    `${role.role_id}:new`
                                                    ? null
                                                    : `${role.role_id}:new`,
                                            )
                                        }
                                    >
                                        + Add a bullet
                                    </Button>
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
                                </Card>
                            );
                        })}

                        <Card id="skills-section" tabIndex={-1} className="p-5">
                            <SectionTitle aside="Select a skill to remove it">
                                Skills
                            </SectionTitle>
                            <ul className="mt-3 flex flex-wrap gap-2">
                                {skills.map((id) => {
                                    const skill = resume.skills.find(
                                        (s) => s.id === id,
                                    );

                                    return (
                                        <li key={id}>
                                            <button
                                                type="button"
                                                aria-label={`Remove skill ${skill?.name}`}
                                                onClick={() =>
                                                    setSkills((all) =>
                                                        all.filter(
                                                            (s) => s !== id,
                                                        ),
                                                    )
                                                }
                                                className="group bg-raised text-fg ring-line-strong hover:bg-bad-soft hover:text-bad hover:ring-bad-line inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm ring-1 transition-colors ring-inset"
                                            >
                                                {skill?.name}
                                                <span
                                                    aria-hidden="true"
                                                    className="text-fg-subtle group-hover:text-bad"
                                                >
                                                    ×
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </Card>
                    </div>
                )}
            </div>

            <div className="border-line mt-10 max-w-4xl space-y-3 border-t pt-6">
                <Card className="px-4 py-3">
                    <Disclosure
                        summary={`Version history (${ws.history.length})`}
                    >
                        <ul className="divide-line -mx-1 mt-1 divide-y">
                            {ws.history.map((h) => (
                                <li
                                    key={h.id}
                                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-1 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="flex flex-wrap items-center gap-2 text-sm">
                                            <span className="text-fg font-medium break-words">
                                                {h.label}
                                            </span>
                                            {h.current && (
                                                <Badge tone="accent">
                                                    Current
                                                </Badge>
                                            )}
                                            {h.preferred && (
                                                <Badge tone="ok">Final</Badge>
                                            )}
                                        </p>
                                        <p className="text-fg-subtle mt-0.5 text-xs break-words">
                                            {h.summary} · {when(h.created_at)}
                                        </p>
                                    </div>
                                    {h.id !== variant.id ? (
                                        <ButtonLink
                                            size="sm"
                                            href={workspaceShow.url({
                                                jobPosting: job.id,
                                                resumeVariant: h.id,
                                            })}
                                        >
                                            Open
                                        </ButtonLink>
                                    ) : (
                                        <span className="text-fg-subtle text-xs">
                                            Viewing
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Disclosure>
                </Card>

                <Card className="px-4 py-3">
                    <Disclosure summary="Technical details">
                        <dl className="text-fg-subtle grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                            <div>Resume: {variant.id}</div>
                            <div>
                                Made from:{' '}
                                {variant.parent_id ?? 'your approved profile'}
                            </div>
                            <div>
                                Selection:{' '}
                                {resume.selection_generated_by ?? 'n/a'}
                            </div>
                            <div>
                                Wording: {resume.wording_generated_by ?? 'n/a'}
                            </div>
                            <div>Schema: {resume.schema_version}</div>
                        </dl>
                        {ws.edit_log.length > 0 && (
                            <div className="mt-4">
                                <p className="text-fg-muted text-xs font-medium">
                                    Changes from the resume it was made from
                                </p>
                                <ul className="text-fg-subtle mt-1 list-disc space-y-0.5 pl-4 text-xs break-words">
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
                            <div className="mt-4">
                                <p className="text-fg-muted text-xs font-medium">
                                    Asked for by the job, not supported directly
                                    (not claimed)
                                </p>
                                <ul className="text-fg-subtle mt-1 list-disc space-y-0.5 pl-4 text-xs break-words">
                                    {tailoring.gaps.map((g) => (
                                        <li key={g.label}>
                                            {g.label}
                                            {g.status === 'limitation'
                                                ? ' (you confirmed no direct experience)'
                                                : ' (no approved evidence)'}
                                            {g.required
                                                ? ', required'
                                                : ', preferred'}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {tailoring && tailoring.added.length > 0 && (
                            <div className="mt-4">
                                <p className="text-fg-muted text-xs font-medium">
                                    Brought in because the job needs it
                                </p>
                                <ul className="text-fg-subtle mt-1 list-disc space-y-0.5 pl-4 text-xs break-words">
                                    {tailoring.added.map((f) => (
                                        <li key={f.key}>{f.statement}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {tailoring && tailoring.dropped.length > 0 && (
                            <div className="mt-4">
                                <p className="text-fg-muted text-xs font-medium">
                                    In the general resume, left out here
                                </p>
                                <ul className="text-fg-subtle mt-1 list-disc space-y-0.5 pl-4 text-xs break-words">
                                    {tailoring.dropped.map((f) => (
                                        <li key={f.key}>{f.statement}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </Disclosure>
                </Card>
            </div>

            {dirty && tab === 'edit' && (
                <div
                    role="region"
                    aria-label="Unsaved changes"
                    className="border-line-strong bg-raised/95 shadow-float fixed inset-x-0 bottom-0 z-10 border-t p-3 backdrop-blur-sm"
                >
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-1 sm:px-3">
                        <p className="text-fg-muted text-sm">
                            <span className="text-fg font-medium">
                                Unsaved changes.
                            </span>{' '}
                            Saving creates a new version; the current one is
                            kept.
                        </p>
                        <div className="flex gap-2">
                            <Button
                                variant="ghost"
                                onClick={discard}
                                disabled={saving}
                            >
                                Discard
                            </Button>
                            <Button
                                variant="primary"
                                onClick={save}
                                disabled={saving}
                            >
                                {saving ? 'Saving…' : 'Save revision'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
