import { relativeTimeFromNow } from '@/lib/relative-time';
import type { WorkerStatus } from '@/types/worker-status';

/**
 * "Browser Inspector ● Online / Last seen 3 seconds ago" — Well Applied
 * only ever OBSERVES worker presence here, never
 * starts/stops/restarts it. See
 * App\Support\ApplicationInspection\PresentWorkerAvailability.
 */
export function WorkerStatusBadge({ status }: { status: WorkerStatus }) {
    return (
        <div className="flex items-center gap-1.5 text-xs">
            <span
                className={
                    status.online
                        ? 'bg-ok inline-block h-1.5 w-1.5 rounded-full'
                        : 'bg-fg-subtle inline-block h-1.5 w-1.5 rounded-full'
                }
                aria-hidden="true"
            />
            <span
                className={status.online ? 'text-fg-muted' : 'text-fg-subtle'}
            >
                Browser Inspector {status.online ? 'online' : 'offline'}
                {status.last_seen_at && (
                    <span className="text-fg-subtle">
                        {' '}
                        · last seen {relativeTimeFromNow(status.last_seen_at)}
                    </span>
                )}
            </span>
        </div>
    );
}

/**
 * The explicit "nothing is wrong, it just hasn't reconnected yet"
 * explanation for a queued/running inspection while the worker is
 * offline — see PRODUCT GOAL: the user should never be left wondering
 * why nothing is happening.
 */
export function WaitingForWorkerNotice({ status }: { status: WorkerStatus }) {
    if (status.online) {
        return null;
    }

    return (
        <p className="text-warn mt-2 text-xs">
            Browser worker offline. Inspection will remain queued until it
            reconnects.
        </p>
    );
}
