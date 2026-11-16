import { router } from '@inertiajs/react';
import { batchToast } from '@/lib/batch-toast';
import { decisionToast, type ToastContent } from '@/lib/decision-toast';
import { qualificationToast } from '@/lib/qualification';
import { runRequestToast, scheduleToast } from '@/lib/sources';
import { notify } from '@/lib/toast';
import { undo as decisionsUndo } from '@/routes/jobs/decisions';
import type {
    BatchResult,
    DecisionResult,
    QualificationFlash,
} from '@/types/job-posting';
import type { RunFlash, ScheduleFlash } from '@/types/sources';

/** One undo request at a time: a second click on Undo (or on a stale toast) while one is in flight is ignored. */
let undoing = false;

function undo(decisions: Record<string, number>) {
    if (undoing) {
        return;
    }

    undoing = true;
    router.post(
        decisionsUndo.url(),
        { decisions },
        {
            showProgress: false,
            preserveScroll: true,
            preserveState: true,
            onError: (errors) =>
                notify.error("Couldn't undo that decision", {
                    description: Object.values(errors)[0],
                }),
            onFinish: () => {
                undoing = false;
            },
        },
    );
}

function show(content: ToastContent) {
    notify[content.kind](content.title, {
        description: content.description,
        action: content.undo
            ? { label: 'Undo', onClick: () => undo(content.undo!) }
            : undefined,
    });
}

/**
 * Turn one-time flash data into a toast. Called once per server response that carries flash (Inertia fires its
 * `flash` event only for a response that has some, and clears it from history, so a redirect, a Back or a reload never
 * replays an old message). Nothing is shown until the server has answered, because the flash is the answer.
 */
export function showFlash(flash: {
    decision_result?: DecisionResult;
    batch_result?: BatchResult;
    qualification_result?: QualificationFlash;
    schedule_result?: ScheduleFlash;
    run_result?: RunFlash;
}) {
    if (flash.decision_result !== undefined) {
        show(decisionToast(flash.decision_result));
    }

    if (flash.batch_result !== undefined) {
        show(batchToast(flash.batch_result));
    }

    if (flash.qualification_result !== undefined) {
        show(qualificationToast(flash.qualification_result));
    }

    if (flash.run_result !== undefined) {
        const toast = runRequestToast(flash.run_result);

        notify[toast.kind](toast.title, { description: toast.description });
    }

    if (flash.schedule_result !== undefined) {
        const toast = scheduleToast(flash.schedule_result);

        notify.success(toast.title, { description: toast.description });
    }
}

/** Listen for flash for the life of the app. Returns the unsubscribe, for hot reloading. */
export function registerFlashToasts(): () => void {
    return router.on('flash', (event) => showFlash(event.detail.flash));
}
