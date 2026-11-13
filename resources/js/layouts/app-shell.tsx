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
    { label: 'Overview', href: home.url(), match: '/' },
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
 * App shell: a sticky header with the product name and primary navigation,
 * over a single content column. The Browser Inspector status sits quietly in
 * the footer. Every screen renders inside it, so global chrome changes happen
 * here once.
 */
export default function AppShell({ children }: { children: ReactNode }) {
    const { url } = usePage();
    const workerStatus = useWorkerStatusPolling();

    return (
        <div className="bg-canvas text-fg min-h-screen">
            <header className="border-line bg-canvas/90 sticky top-0 z-20 border-b backdrop-blur-sm">
                <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-2 sm:gap-4 sm:px-6">
                    <Link
                        href={home.url()}
                        className="text-fg rounded-control hover:bg-raised/60 focus-visible:ring-focus -mx-1.5 flex shrink-0 items-center gap-2 px-1.5 py-1 text-sm font-semibold tracking-tight whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <span
                            aria-hidden="true"
                            className="bg-accent h-2.5 w-2.5 rounded-[3px]"
                        />
                        Well Applied
                    </Link>
                    <nav
                        aria-label="Primary"
                        className="-mx-1 flex min-w-0 items-center gap-0.5 overflow-x-auto px-1 text-sm sm:gap-1"
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
                                    aria-current={isActive ? 'page' : undefined}
                                    className={cn(
                                        'rounded-control px-2 py-1.5 font-medium whitespace-nowrap transition-colors sm:px-2.5',
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
