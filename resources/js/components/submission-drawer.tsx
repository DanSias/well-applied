import { router } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Drawer } from '@/components/ui/drawer';
import { FieldError, TextArea, TextInput } from '@/components/ui/field';
import {
    browserTimezone,
    buildPayload,
    createSubmitGuard,
    formatMegabytes,
    initialForm,
    mapServerErrors,
    newIdempotencyKey,
    todayIn,
    validateSubmission,
    type ApplicationSection,
    type ResumeKind,
    type SubmissionErrors,
    type SubmissionForm,
} from '@/lib/submission';
import { notify } from '@/lib/toast';
import { store as submissionStore } from '@/routes/jobs/submissions';

const KIND_LABEL: Record<ResumeKind, string> = {
    final: 'The final Well Applied resume',
    variant: 'Another Well Applied resume',
    external: 'A PDF I upload',
    none: 'Something else, I am not sure, or no resume',
};

function Row({
    label,
    htmlFor,
    hint,
    error,
    children,
}: {
    label: string;
    htmlFor?: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label htmlFor={htmlFor} className="text-fg text-sm font-medium">
                {label}
            </label>
            {hint && <p className="text-fg-subtle mt-0.5 text-xs">{hint}</p>}
            <div className="mt-1.5">{children}</div>
            <FieldError>{error}</FieldError>
        </div>
    );
}

/**
 * Record that the person applied, in the shared drawer. Well Applied submits nothing: this only writes down what they did.
 *
 * The form never picks a resume for them. Choosing one of ours needs an explicit "this is what I submitted", and the copy
 * of its PDF is kept only if Well Applied still holds that exact file (nothing is rendered to manufacture one). The
 * idempotency key is made once when the drawer opens and reused for every retry, so a retry or a double press records once.
 * `another` is set when an application is already being tracked: recording a second then needs a separate confirmation.
 */
export function SubmissionDrawer({
    job,
    section,
    another,
    onClose,
}: {
    job: { id: number; title: string; company: string };
    section: ApplicationSection['form'];
    another: boolean;
    onClose: () => void;
}) {
    const timezone = useMemo(() => browserTimezone(), []);
    const [form, setForm] = useState<SubmissionForm>(() =>
        initialForm(section, timezone, another),
    );
    const [errors, setErrors] = useState<
        SubmissionErrors & { general?: string }
    >({});
    const [processing, setProcessing] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const idempotencyKey = useRef(newIdempotencyKey());
    const guard = useRef(createSubmitGuard());
    const resumes = section.resumes;
    const final = resumes.find((r) => r.is_final) ?? null;
    const selected = resumes.find((r) => r.id === form.resumeVariantId) ?? null;
    const set = (patch: Partial<SubmissionForm>) =>
        setForm((f) => ({ ...f, ...patch }));

    const chooseKind = (kind: ResumeKind) =>
        set({
            resumeKind: kind,
            resumeConfirmed: false,
            resumeVariantId:
                kind === 'final'
                    ? (final?.id ?? null)
                    : kind === 'variant'
                      ? (form.resumeVariantId ?? resumes[0]?.id ?? null)
                      : null,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!guard.current.tryStart()) {
            return;
        }

        const problems = validateSubmission(
            form,
            timezone,
            new Date(),
            section.max_pdf_bytes,
        );

        if (Object.keys(problems).length > 0) {
            setErrors(problems);
            guard.current.finish();

            return;
        }

        setErrors({});
        setProcessing(true);
        router.post(
            submissionStore.url({ jobPosting: job.id }),
            buildPayload(form, timezone, idempotencyKey.current, file),
            {
                preserveScroll: true,
                forceFormData: file !== null,
                onSuccess: () => {
                    onClose();
                    window.setTimeout(
                        () =>
                            notify.success('Application recorded', {
                                description:
                                    'Saved as something you submitted yourself.',
                            }),
                        80,
                    );
                },
                onError: (serverErrors) => {
                    const mapped = mapServerErrors(serverErrors);

                    // Someone (another tab, an earlier press) already recorded one: ask before recording another.
                    if (mapped.reason === 'active_submission_exists') {
                        if (!form.another) {
                            set({ another: true, anotherConfirmed: false });
                        }

                        // The note above already says it; the confirmation is the way forward.
                        delete mapped.general;
                    }

                    setErrors(mapped);
                },
                onNetworkError: () => {
                    notify.error("Couldn't reach the server", {
                        description:
                            'Nothing was recorded. You can press Record again; it will not duplicate.',
                    });
                },
                onFinish: () => {
                    guard.current.finish();
                    setProcessing(false);
                },
            },
        );
    };

    return (
        <Drawer
            title="Record that I applied"
            subtitle={`${job.title} at ${job.company}`}
            closeLabel="Close"
            onClose={onClose}
        >
            <form onSubmit={submit} noValidate className="space-y-5">
                <p className="text-fg-muted text-sm leading-relaxed">
                    This records an application you submitted yourself. Well
                    Applied will not submit anything.
                </p>

                {form.another && (
                    <div
                        role="note"
                        className="border-warn-line bg-warn-soft text-warn rounded-lg border px-3 py-2.5 text-sm"
                    >
                        <p>
                            An application to this job is already being tracked.
                            Record another only if you really applied again.
                        </p>
                        <label className="mt-2 flex items-start gap-2">
                            <Checkbox
                                checked={form.anotherConfirmed}
                                onChange={(e) =>
                                    set({ anotherConfirmed: e.target.checked })
                                }
                            />
                            <span>I applied again.</span>
                        </label>
                        <FieldError>{errors.another}</FieldError>
                    </div>
                )}

                <Row
                    label="Date you applied"
                    htmlFor="submitted-on"
                    hint={`Just the day, in your timezone (${timezone}). You can enter an earlier date.`}
                    error={errors.submittedOn}
                >
                    <TextInput
                        id="submitted-on"
                        type="date"
                        value={form.submittedOn}
                        max={todayIn(timezone)}
                        onChange={(e) => set({ submittedOn: e.target.value })}
                        className="max-w-48"
                    />
                </Row>

                <fieldset>
                    <legend className="text-fg text-sm font-medium">
                        Resume you used
                    </legend>
                    <p className="text-fg-subtle mt-0.5 text-xs">
                        Nothing is chosen for you.
                    </p>
                    <div className="mt-2 space-y-2">
                        {(
                            [
                                ...(final !== null ? (['final'] as const) : []),
                                ...(resumes.length > 0
                                    ? (['variant'] as const)
                                    : []),
                                'external',
                                'none',
                            ] as ResumeKind[]
                        ).map((kind) => (
                            <label
                                key={kind}
                                className="flex items-start gap-2 text-sm"
                            >
                                <input
                                    type="radio"
                                    name="resume-kind"
                                    checked={form.resumeKind === kind}
                                    onChange={() => chooseKind(kind)}
                                    className="accent-accent mt-0.5 h-4 w-4"
                                />
                                <span>
                                    {KIND_LABEL[kind]}
                                    {kind === 'final' && final !== null && (
                                        <span className="text-fg-subtle">
                                            {' '}
                                            ({final.label})
                                        </span>
                                    )}
                                </span>
                            </label>
                        ))}
                    </div>

                    {form.resumeKind === 'variant' && (
                        <select
                            aria-label="Which Well Applied resume"
                            value={form.resumeVariantId ?? ''}
                            onChange={(e) =>
                                set({
                                    resumeVariantId: Number(e.target.value),
                                    resumeConfirmed: false,
                                })
                            }
                            className="border-line-strong bg-sunken text-fg mt-2 block w-full max-w-full rounded-md border px-3 py-2 text-sm"
                        >
                            {resumes.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.label}
                                    {r.is_final ? ' (final)' : ''}
                                </option>
                            ))}
                        </select>
                    )}

                    {(form.resumeKind === 'final' ||
                        form.resumeKind === 'variant') &&
                        selected !== null && (
                            <div className="border-line mt-3 rounded-lg border px-3 py-2.5 text-sm">
                                <p className="text-fg-muted text-xs leading-relaxed">
                                    {selected.pdf_cached
                                        ? 'Well Applied still holds this resume’s PDF, so a copy is kept as the record of what you submitted.'
                                        : 'Well Applied no longer holds this resume’s PDF, so no copy can be kept. The application is still recorded, naming this resume.'}
                                </p>
                                <label className="mt-2 flex items-start gap-2">
                                    <Checkbox
                                        checked={form.resumeConfirmed}
                                        onChange={(e) =>
                                            set({
                                                resumeConfirmed:
                                                    e.target.checked,
                                            })
                                        }
                                    />
                                    <span>
                                        This is the document I actually
                                        submitted.
                                    </span>
                                </label>
                                <FieldError>
                                    {errors.resumeConfirmed}
                                </FieldError>
                            </div>
                        )}

                    {form.resumeKind === 'external' && (
                        <div className="mt-2">
                            <input
                                type="file"
                                accept="application/pdf,.pdf"
                                aria-label="The PDF you submitted"
                                onChange={(e) => {
                                    const chosen = e.target.files?.[0] ?? null;

                                    setFile(chosen);
                                    set({
                                        pdf:
                                            chosen === null
                                                ? null
                                                : {
                                                      name: chosen.name,
                                                      size: chosen.size,
                                                      type: chosen.type,
                                                  },
                                    });
                                }}
                                className="text-fg-muted block w-full max-w-full text-sm"
                            />
                            <p className="text-fg-subtle mt-1 text-xs">
                                PDF, up to{' '}
                                {formatMegabytes(section.max_pdf_bytes)}. Kept
                                privately on this computer as the record of what
                                you submitted.
                            </p>
                        </div>
                    )}
                    <FieldError>{errors.resume ?? errors.pdf}</FieldError>
                </fieldset>

                <Row
                    label="Application link"
                    htmlFor="application-url"
                    hint="Optional. Where you applied."
                    error={errors.applicationUrl}
                >
                    <TextInput
                        id="application-url"
                        type="url"
                        inputMode="url"
                        value={form.applicationUrl}
                        onChange={(e) =>
                            set({ applicationUrl: e.target.value })
                        }
                        placeholder="https://"
                    />
                </Row>

                <Row
                    label="Notes"
                    htmlFor="submission-notes"
                    hint="Optional."
                    error={errors.notes}
                >
                    <TextArea
                        id="submission-notes"
                        rows={3}
                        value={form.notes}
                        onChange={(e) => set({ notes: e.target.value })}
                    />
                </Row>

                {errors.general && (
                    <p role="alert" className="text-bad text-sm">
                        {errors.general}
                    </p>
                )}

                <div className="border-line flex flex-wrap items-center gap-2 border-t pt-4">
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={processing}
                    >
                        {processing ? 'Recording…' : 'Record application'}
                    </Button>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Drawer>
    );
}
