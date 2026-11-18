// Keeps a claimed run's lease alive while the browser runs, and says plainly when it is lost. Renewal is a heartbeat
// every `intervalMs`. The lease is lost when Laravel rejects a heartbeat (403/404/409: not ours, finished, or lapsed) or
// when we can no longer prove it is live: the last confirmed lease end, measured from when we SENT the confirming
// request (so it can only be early, never late), has passed. Transient network errors and 5xx are tolerated until then.
import type { PreparerClient } from './client.ts';
import type { Log } from './log.ts';

export type LeaseLoss = 'rejected' | 'lapsed';

export interface LeaseKeeperOptions {
    client: PreparerClient;
    runId: number;
    intervalMs: number;
    leaseSeconds: number;
    /** When the claim was made (ms epoch, local clock). */
    claimedAt: number;
    onLost: (why: LeaseLoss) => void;
    log: Log;
    now?: () => number;
}

export class LeaseKeeper {
    private leaseUntil: number;
    private phase = 'filling';
    private timer: NodeJS.Timeout | null = null;
    private inFlight: Promise<void> | null = null;
    private stopped = false;
    private lostWhy: LeaseLoss | null = null;
    private readonly now: () => number;

    private readonly o: LeaseKeeperOptions;

    constructor(o: LeaseKeeperOptions) {
        this.o = o;
        this.now = o.now ?? Date.now;
        this.leaseUntil = o.claimedAt + o.leaseSeconds * 1000;
    }

    get lost(): LeaseLoss | null {
        return this.lostWhy;
    }

    setPhase(phase: string): void {
        this.phase = phase;
    }

    start(): void {
        this.schedule();
    }

    async stop(): Promise<void> {
        this.stopped = true;
        if (this.timer) clearTimeout(this.timer);
        await this.inFlight;
    }

    private schedule(): void {
        if (this.stopped || this.lostWhy) return;
        this.timer = setTimeout(() => {
            this.inFlight = this.beat().finally(() => {
                this.inFlight = null;
                this.schedule();
            });
        }, this.o.intervalMs);
    }

    private async beat(): Promise<void> {
        const sentAt = this.now();
        try {
            const r = await this.o.client.heartbeat(this.o.runId, this.phase);
            if (r.status === 200 && r.body?.accepted) {
                this.leaseUntil = sentAt + this.o.leaseSeconds * 1000;
                return;
            }
            if (r.status === 403 || r.status === 404 || r.status === 409) {
                this.lose('rejected');
                return;
            }
            this.o.log('heartbeat_unconfirmed', { status: r.status });
        } catch {
            this.o.log('heartbeat_network_error');
        }
        if (this.now() >= this.leaseUntil) this.lose('lapsed');
    }

    private lose(why: LeaseLoss): void {
        if (this.lostWhy || this.stopped) return;
        this.lostWhy = why;
        this.o.log('lease_lost', { why });
        this.o.onLost(why);
    }
}
