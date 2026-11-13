import { router } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { FieldError, TextArea, TextInput } from '@/components/ui/field';
import {
    createSubmitGuard,
    newIdempotencyKey,
    todayIn,
    type SubmissionRecord,
} from '@/lib/submission';
import {
    browserTimezone,
    buildUpdatePayload,
    dateLabel,
    initialUpdateForm,
    mapUpdateErrors,
    validateUpdate,
    type UpdateErrors,
    type UpdateForm,
} from '@/lib/submission-update';
import { notify } from '@/lib/toast';
import { store as eventStore } from '@/routes/submissions/events';

/**
 * Add an update to a recorded application, in the shared drawer: what happened, the day it happened, and optionally a
 * note and a link. These are the person's own records, not verified employer messages: nothing is checked against an
 * email or a site. The idempotency key is made once when the drawer opens and reused for every retry, so a retry or a
 * double press records once. Only the updates the server offers for the application's current state are listed.
 */
export function SubmissionUpdateDrawer({
    record,
    jobLabel,
    onClose,
}: {
    record: SubmissionRecord;
    jobLabel: string;
    onClose: () => void;
}) {
    const timezone = useMemo(() => browserTimezone(), []);
    const [form, setForm] = useState<UpdateForm>(() =>
        initialUpdateForm(record.allowed_updates, timezone),
    );
    const [errors, setErrors] = useState<UpdateErrors & { general?: string }>(
        {},
    );
    const [processing, setProcessing] = useState(false);
    const key = useRef(newIdempotencyKey());
    const guard = useRef(createSubmitGuard());
    const set = (patch: Partial<UpdateForm>) =>
        setForm((f) => ({ ...f, ...patch }));
    const when = dateLabel(form.type);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!guard.current.tryStart()) {
            return;
        }

        const problems = validateUpdate(
            form,
            record.allowed_updates,
            record.submitted_on,
            timezone,
        );

        if (Object.keys(problems).length > 0) {
            setErrors(problems);
            guard.current.finish();

            return;
        }

        setErrors({});
        setProcessing(true);
        router.post(
            eventStore.url({ submission: record.id }),
            buildUpdatePayload(form, timezone, key.current),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onClose();
                    window.setTimeout(
                        () =>
                            notify.success('Update recorded', {
                                description:
                                    'Saved as something you recorded yourself.',
                            }),
                        80,
                    );
                },
                onError: (serverErrors) =>
                    setErrors(mapUpdateErrors(serverErrors)),
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
            title="Add an update"
            subtitle={jobLabel}
            closeLabel="Close"
            onClose={onClose}
        >
            <form onSubmit={submit} noValidate className="space-y-5">
                <p className="text-fg-muted text-sm leading-relaxed">
                    This is a note you make yourself about what happened. Well
                    Applied does not check it with the employer.
                </p>

                <fieldset>
                    <legend className="text-fg text-sm font-medium">
                        What happened
                    </legend>
                    <div className="mt-2 space-y-2">
                        {record.allowed_updates.map((option) => (
                            <label
                                key={option.value}
                                className="flex items-start gap-2 text-sm"
                            >
                                <input
                                    type="radio"
                                    name="update-type"
                                    checked={form.type === option.value}
                                    onChange={() => set({ type: option.value })}
                                    className="accent-accent mt-0.5 h-4 w-4"
                                />
                                <span>{option.label}</span>
                            </label>
                        ))}
                    </div>
                    <FieldError>{errors.type}</FieldError>
                </fieldset>

                <div>
                    <label
                        htmlFor="update-date"
                        className="text-fg text-sm font-medium"
                    >
                        {when.label}
                    </label>
                    <p className="text-fg-subtle mt-0.5 text-xs">{when.hint}</p>
                    <TextInput
                        id="update-date"
                        type="date"
                        value={form.occurredOn}
                        min={record.submitted_on}
                        max={
                            form.type === 'interview'
                                ? undefined
                                : todayIn(timezone)
                        }
                        onChange={(e) => set({ occurredOn: e.target.value })}
                        className="mt-1.5 max-w-48"
                    />
                    <FieldError>{errors.occurredOn}</FieldError>
                </div>

                <div>
                    <label
                        htmlFor="update-note"
                        className="text-fg text-sm font-medium"
                    >
                        Note
                    </label>
                    <p className="text-fg-subtle mt-0.5 text-xs">Optional.</p>
                    <TextArea
                        id="update-note"
                        rows={3}
                        value={form.note}
                        onChange={(e) => set({ note: e.target.value })}
                        className="mt-1.5"
                    />
                    <FieldError>{errors.note}</FieldError>
                </div>

                <div>
                    <label
                        htmlFor="update-url"
                        className="text-fg text-sm font-medium"
                    >
                        Link
                    </label>
                    <p className="text-fg-subtle mt-0.5 text-xs">
                        Optional. A page that shows it, if you have one.
                    </p>
                    <TextInput
                        id="update-url"
                        type="url"
                        inputMode="url"
                        value={form.url}
                        onChange={(e) => set({ url: e.target.value })}
                        placeholder="https://"
                        className="mt-1.5"
                    />
                    <FieldError>{errors.url}</FieldError>
                </div>

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
                        {processing ? 'Recording…' : 'Record update'}
                    </Button>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Drawer>
    );
}
