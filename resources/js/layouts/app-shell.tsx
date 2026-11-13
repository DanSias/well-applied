import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ProfileMenu } from '@/components/profile-menu';
import { WorkerStatusBadge } from '@/components/worker-status-badge';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import { PRIMARY_NAV, activePrimary } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

/**
 * App shell: a sticky header with the product name, the job-search navigation (Home, Jobs, Opportunities, Applications)
 * and, at the right, the profile menu holding personal career information, over a single content column. The Browser Inspector status sits quietly in
 * the footer. Every screen renders inside it, so global chrome changes happen
 * here once.
 */
export default function AppShell({ children }: { children: ReactNode }) {
    const { url, props } = usePage<{ profile?: { name: string | null } }>();
    const active = activePrimary(url);
    const workerStatus = useWorkerStatusPolling();

    return (
        <div className="bg-canvas text-fg min-h-screen">
            <header className="border-line bg-canvas/90 sticky top-0 z-20 border-b backdrop-blur-sm">
                <div className="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 px-4 py-2 sm:gap-4 sm:px-6">
                    <div className="flex min-w-0 items-center justify-start">
                        <Link
                            href={home.url()}
                            className="text-fg rounded-control hover:bg-raised/60 focus-visible:ring-focus -mx-1.5 flex shrink-0 items-center gap-2 px-1.5 py-1 text-lg leading-none font-semibold tracking-tight whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span
                                aria-hidden="true"
                                className="bg-accent h-2.5 w-2.5 rounded-[3px]"
                            />
                            <span className="hidden sm:inline">
                                Well Applied
                            </span>
                            <span className="sr-only sm:hidden">
                                Well Applied, home
                            </span>
                        </Link>
                    </div>
                    <nav
                        aria-label="Primary"
                        className="flex items-center gap-0.5 text-sm sm:gap-1"
                    >
                        {PRIMARY_NAV.map((item) => {
                            const isActive = active === item.key;

                            return (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={cn(
                                        'rounded-control focus-visible:ring-focus shrink-0 px-2 py-1.5 font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:outline-none sm:px-2.5',
                                        isActive
                                            ? 'bg-raised text-fg'
                                            : 'text-fg-muted hover:bg-raised/60 hover:text-fg',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                    <div className="flex min-w-0 items-center justify-end">
                        <ProfileMenu name={props.profile?.name} url={url} />
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-6">
                {children}
            </main>
            <footer className="mx-auto max-w-6xl px-4 pb-5 sm:px-6">
                <WorkerStatusBadge status={workerStatus} />
            </footer>
        </div>
    );
}
