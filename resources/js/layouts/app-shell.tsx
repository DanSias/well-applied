import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { WorkerStatusBadge } from '@/components/worker-status-badge';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import { cn } from '@/lib/utils';
import { index as careerDataIndex } from '@/routes/career-data';
import { home } from '@/routes';
import { index as jobsIndex } from '@/routes/jobs';
import { index as resumeProfilesIndex } from '@/routes/resume-profiles';

const NAV_ITEMS = [
    { label: 'Home', href: home.url(), match: '/' },
    { label: 'Opportunities', href: jobsIndex.url(), match: '/jobs' },
    {
        label: 'Resume Profiles',
        href: resumeProfilesIndex.url(),
        match: '/resume-profiles',
    },
    {
        label: 'Career Data',
        href: careerDataIndex.url(),
        match: '/career-data',
    },
];

/**
 * App shell: a sticky header with the product name, primary navigation and
 * worker status, over a single content column. Every screen renders inside
 * it, so global chrome changes happen here once.
 */
export default function AppShell({ children }: { children: ReactNode }) {
    const { url } = usePage();
    const workerStatus = useWorkerStatusPolling();

    return (
        <div className="bg-canvas text-fg min-h-screen">
            <header className="border-line bg-canvas/90 sticky top-0 z-20 border-b backdrop-blur-sm">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-2.5 sm:px-6">
                    <div className="flex max-w-full min-w-0 items-center gap-4">
                        <Link
                            href={home.url()}
                            className="text-fg rounded-control flex shrink-0 items-center gap-2 text-sm font-semibold tracking-tight whitespace-nowrap"
                        >
                            <span
                                aria-hidden="true"
                                className="bg-accent h-2.5 w-2.5 rounded-[3px]"
                            />
                            Well Applied
                        </Link>
                        <nav
                            aria-label="Primary"
                            className="-mx-1 flex items-center gap-1 overflow-x-auto px-1 text-sm"
                        >
                            {NAV_ITEMS.map((item) => {
                                const isActive =
                                    item.match === '/'
                                        ? url === '/'
                                        : url.startsWith(item.match);

                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        aria-current={
                                            isActive ? 'page' : undefined
                                        }
                                        className={cn(
                                            'rounded-control px-2.5 py-1.5 font-medium whitespace-nowrap transition-colors',
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
                    </div>
                    <WorkerStatusBadge status={workerStatus} />
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
                {children}
            </main>
        </div>
    );
}
