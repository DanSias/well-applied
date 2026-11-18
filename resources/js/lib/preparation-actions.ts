import type { JsonReply } from '@/lib/http';
import type { PreparationState } from '@/types/application-preparation';

export type PreparationActionError = { code: string; message: string };

type Post = <T>(
    url: string,
    body?: Record<string, unknown>,
) => Promise<JsonReply<T>>;

type StartReply = {
    created?: boolean;
    preparation?: PreparationState;
    error?: string;
    reason?: string;
};

/**
 * Starting an attempt, from the server's answer alone: the page only becomes "queued" when the server returned the new
 * attempt. A refusal (no resume, an unsupported URL, a PDF that would not render, an expired session) is returned as an
 * actionable message and changes nothing.
 */
export async function startPreparation(
    startUrl: string,
    resumeVariantId: number | null,
    post: Post,
): Promise<
    | { ok: true; state: PreparationState }
    | { ok: false; error: PreparationActionError }
> {
    try {
        const reply = await post<StartReply>(
            startUrl,
            resumeVariantId === null
                ? {}
                : { resume_variant_id: resumeVariantId },
        );

        if (reply.ok && reply.data?.preparation) {
            return { ok: true, state: reply.data.preparation };
        }

        return {
            ok: false,
            error: {
                code: reply.data?.reason ?? 'unavailable',
                message:
                    reply.data?.error ??
                    (reply.status === 419
                        ? 'Your session expired. Reload the page and try again.'
                        : 'Preparation could not be started. Nothing was changed.'),
            },
        };
    } catch {
        return {
            ok: false,
            error: {
                code: 'network',
                message: 'Could not reach Well Applied. Nothing was started.',
            },
        };
    }
}

/**
 * Cancelling, again from the server's answer alone. "Cancelled" and "already cancelled" are the same fact; anything else
 * (a worker had claimed it first) is explained, and the caller fetches the real state either way.
 */
export async function cancelPreparation(
    cancelUrl: string,
    post: Post,
): Promise<{ done: boolean; error: PreparationActionError | null }> {
    try {
        const reply = await post<{ outcome?: string }>(cancelUrl);
        const done =
            reply.ok &&
            (reply.data?.outcome === 'cancelled' ||
                reply.data?.outcome === 'already_cancelled');

        return {
            done,
            error: done
                ? null
                : {
                      code: 'not_cancellable',
                      message:
                          'This attempt could not be cancelled: a worker had already started it. It will finish on its own.',
                  },
        };
    } catch {
        return {
            done: false,
            error: {
                code: 'network',
                message: 'Could not reach Well Applied. Nothing was cancelled.',
            },
        };
    }
}
