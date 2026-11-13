import type { TailoredResumeStatus } from '@/types/tailored-resume';

/**
 * The one "generate" button: when it shows, what it says, and whether it can be pressed. Only a run that is really in
 * progress (`running`) disables it. An interrupted run (its worker stopped or restarted) is not progressing, so it
 * offers to continue from where it stopped, and a failed one to retry; both pick up the finished stages. Kept apart from
 * the component so the rule can be tested.
 */
export function generateAction(
    status: Pick<TailoredResumeStatus, 'state'>,
    processing: boolean,
): { show: boolean; label: string; disabled: boolean } {
    switch (status.state) {
        case 'ready':
        case 'needs_clarification':
            return { show: false, label: '', disabled: true };
        case 'running':
            return { show: true, label: 'Generating…', disabled: true };
        case 'interrupted':
            return {
                show: true,
                label: processing ? 'Continuing…' : 'Continue generating',
                disabled: processing,
            };
        case 'failed':
            return { show: true, label: 'Retry', disabled: processing };
        default:
            return {
                show: true,
                label: 'Generate Tailored Resume',
                disabled: processing,
            };
    }
}
