import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Drawer } from '@/components/ui/drawer';
import { FieldError, TextArea } from '@/components/ui/field';
import {
    createSubmitGuard,
    formatCalendarDate,
    newIdempotencyKey,
    type SubmissionRecord,
} from '@/lib/submission';
import {
    buildCorrectionPayload,
    validateCorrection,
    type CorrectionForm,
} from '@/lib/submission-update';
import { notify } from '@/lib/toast';
import { store as correctionStore } from '@/routes/submissions/correction';

/**
 * Mark an application as recorded in error: a correction for something that should never have been recorded as
 * submitted. Deliberately apart from the ordinary updates, explained, and behind an explicit confirmation. Nothing is
 * deleted: the record and its history stay (in the "Recorded in error" group), it stops counting as an application, and
 * nothing more can be added to it. The job itself is not changed. One correction, however many times it is sent.
 */
export function SubmissionCorrectionDrawer({
    record,
    jobLabel,
    onClose,
}: {
    record: SubmissionRecord;
    jobLabel: string;
    onClose: () => void;
}) {
    const [form, setForm] = useState<CorrectionForm>({
        confirmed: false,
        reason: '',
    });
    const [errors, setErrors] = useState<{
        confirmed?: string;
        reason?: string;
        general?: string;
    }>({});
    const [processing, setProcessing] = useState(false);
    const key = useRef(newIdempotencyKey());
    const guard = useRef(createSubmitGuard());

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!guard.current.tryStart()) {
            return;
        }

        const problems = validateCorrection(form);

        if (Object.keys(problems).length > 0) {
            setErrors(problems);
            guard.current.finish();

            return;
        }

        setErrors({});
        setProcessing(true);
        router.post(
            correctionStore.url({ submission: record.id }),
            buildCorrectionPayload(form, key.current),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onClose();
                    window.setTimeout(
                        () =>
                            notify.success('Marked as recorded in error', {
                                description:
                                    'It is kept for reference and no longer counts as an application.',
                            }),
                        80,
                    );
                },
                onError: (serverErrors) =>
                    setErrors({
                        confirmed: serverErrors.confirmed,
                        reason: serverErrors.reason,
                        general: serverErrors.submission,
                    }),
                onNetworkError: () => {
                    notify.error("Couldn't reach the server", {
                        description:
                            'Nothing was changed. You can try again; it will not duplicate.',
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
            title="Mark as recorded in error"
            subtitle={jobLabel}
            closeLabel="Close"
            onClose={onClose}
        >
            <form onSubmit={submit} noValidate className="space-y-5">
                <div className="text-fg-muted space-y-2 text-sm leading-relaxed">
                    <p>
                        Use this when the application recorded on{' '}
                        <span className="text-fg font-medium">
                            {formatCalendarDate(record.submitted_on)}
                        </span>{' '}
                        should never have been recorded as submitted.
                    </p>
                    <p>
                        Nothing is deleted. The record and its history stay for
                        reference, but it stops counting as an application and
                        nothing more can be added to it. The job is not changed.
                        This cannot be undone here.
                    </p>
                    <p>
                        To note something that really happened, such as a
                        rejection, add an update instead.
                    </p>
                </div>

                <div>
                    <label
                        htmlFor="correction-reason"
                        className="text-fg text-sm font-medium"
                    >
                        Reason
                    </label>
                    <p className="text-fg-subtle mt-0.5 text-xs">Optional.</p>
                    <TextArea
                        id="correction-reason"
                        rows={3}
                        value={form.reason}
                        onChange={(e) =>
                            setForm((f) => ({ ...f, reason: e.target.value }))
                        }
                        className="mt-1.5"
                    />
                    <FieldError>{errors.reason}</FieldError>
                </div>

                <div>
                    <label className="flex items-start gap-2 text-sm">
                        <Checkbox
                            checked={form.confirmed}
                            onChange={(e) =>
                                setForm((f) => ({
                                    ...f,
                                    confirmed: e.target.checked,
                                }))
                            }
                        />
                        <span>
                            This application should not have been recorded as
                            submitted.
                        </span>
                    </label>
                    <FieldError>{errors.confirmed}</FieldError>
                </div>

                {errors.general && (
                    <p role="alert" className="text-bad text-sm">
                        {errors.general}
                    </p>
                )}

                <div className="border-line flex flex-wrap items-center gap-2 border-t pt-4">
                    <Button
                        type="submit"
                        variant="danger"
                        disabled={processing}
                    >
                        {processing ? 'Marking…' : 'Mark as recorded in error'}
                    </Button>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Drawer>
    );
}
