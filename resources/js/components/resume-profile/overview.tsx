import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, ButtonLink } from '@/components/ui/button';
import { Card, SectionTitle } from '@/components/ui/surface';
import { useSend } from '@/components/profile-synthesis';
import { index as jobsIndex } from '@/routes/jobs';
import { refreshEvidence } from '@/routes/profile-syntheses';
import type { ResumeProfilePage } from '@/types/resume-profile';
import { NEXT_LABEL, STATE_LABEL, type ProfileStatus } from './status';

export type ProfileTabId =
    | 'overview'
    | 'profile'
    | 'interview'
    | 'market'
    | 'history';

function Fact({
    label,
    children,
    hint,
}: {
    label: string;
    children: React.ReactNode;
    hint?: React.ReactNode;
}) {
    return (
        <div className="px-5 py-4">
            <p className="text-fg-subtle text-xs font-medium">{label}</p>
            <div className="text-fg mt-1 text-sm font-medium">{children}</div>
            {hint && (
                <p className="text-fg-subtle mt-1 text-xs leading-relaxed">
                    {hint}
                </p>
            )}
        </div>
    );
}

/**
 * Where this profile stands, in plain terms, with one next action. Reads
 * only what the page already carries; refreshing is the one action that
 * writes, and only when the user presses it.
 */
export function OverviewTab({
    page,
    status,
    onOpenTab,
}: {
    page: ResumeProfilePage;
    status: ProfileStatus;
    onOpenTab: (tab: ProfileTabId) => void;
}) {
    const { synthesis, interview } = page;
    const shown = synthesis.synthesis;
    const profile = shown?.profile;
    const { busy, send, errors } = useSend();
    const resolved = interview.progress.total - interview.progress.open;
    const percent =
        interview.progress.total === 0
            ? 0
            : Math.round((resolved / interview.progress.total) * 100);
    const topProjects =
        profile?.projects
            .filter((p) => !p.personal && p.included)
            .slice(0, 3) ?? [];
    const strengths =
        profile?.capabilities.direct.filter((c) => c.included).slice(0, 8) ??
        [];

    const refresh = () => {
        if (shown !== null) {
            send(refreshEvidence.post(shown.id), {});
        }
    };

    const primary = (() => {
        switch (status.next) {
            case 'wait':
                return (
                    <Button variant="primary" disabled>
                        {NEXT_LABEL.wait}
                    </Button>
                );
            case 'build_market':
                return (
                    <Button
                        variant="primary"
                        onClick={() => onOpenTab('market')}
                    >
                        {NEXT_LABEL.build_market}
                    </Button>
                );
            case 'interview':
                return (
                    <Button
                        variant="primary"
                        onClick={() => onOpenTab('interview')}
                    >
                        {NEXT_LABEL.interview}
                    </Button>
                );
            case 'build_profile':
            case 'review_draft':
                return (
                    <Button
                        variant="primary"
                        onClick={() => onOpenTab('profile')}
                    >
                        {NEXT_LABEL[status.next]}
                    </Button>
                );
            case 'refresh':
                return (
                    <Button variant="primary" disabled={busy} onClick={refresh}>
                        {busy ? 'Refreshing…' : NEXT_LABEL.refresh}
                    </Button>
                );
            case 'use':
                return (
                    <ButtonLink variant="primary" href={jobsIndex.url()}>
                        {NEXT_LABEL.use}
                    </ButtonLink>
                );
        }
    })();

    const stateTone =
        status.state === 'approved'
            ? 'ok'
            : status.state === 'draft'
              ? 'accent'
              : 'neutral';

    return (
        <div className="space-y-6">
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-4 p-5">
                    <div className="max-w-prose min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge tone={stateTone}>
                                {STATE_LABEL[status.state]}
                            </Badge>
                            {synthesis.approved_version !== null && (
                                <Badge>
                                    Version {synthesis.approved_version}
                                </Badge>
                            )}
                            {status.usable ? (
                                <Badge tone="ok">Ready for new resumes</Badge>
                            ) : (
                                <Badge tone="warn">
                                    Not ready for new resumes
                                </Badge>
                            )}
                        </div>
                        <h2 className="text-fg mt-3 text-lg font-semibold tracking-tight">
                            {profile?.presentation.headline ??
                                page.profile.name}
                        </h2>
                        <p className="text-fg-muted mt-1.5 text-sm leading-relaxed">
                            {profile
                                ? profile.presentation.summary
                                : (page.profile.purpose ?? '')}
                        </p>
                    </div>
                    <div className="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                        {primary}
                        {shown !== null && status.next !== 'wait' && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onOpenTab('profile')}
                            >
                                Open the profile
                            </Button>
                        )}
                    </div>
                </div>

                {status.freshness === 'stale' && (
                    <div className="px-5 pb-5">
                        <Alert tone="warn" role="status">
                            <p className="font-medium">
                                Your career evidence has changed since this
                                profile was approved.
                            </p>
                            <p className="text-fg-muted mt-1">
                                Refresh the profile&rsquo;s evidence before
                                using it for new resumes. Refreshing makes a new
                                draft from your current evidence (no model
                                call). Nothing changes until you review and
                                approve it, and resumes you already made are not
                                affected.
                            </p>
                        </Alert>
                    </div>
                )}
                {status.freshness === 'refresh_available' && (
                    <div className="px-5 pb-5">
                        <Alert tone="neutral" role="status">
                            <p className="font-medium">
                                A refresh is available.
                            </p>
                            <p className="text-fg-muted mt-1">
                                How your evidence is assembled has changed since
                                this version was built. The profile can still be
                                used. Refreshing makes a new draft for you to
                                review (no model call).
                            </p>
                            {shown !== null && (
                                <Button
                                    size="sm"
                                    className="mt-3"
                                    disabled={busy}
                                    onClick={refresh}
                                >
                                    {busy ? 'Refreshing…' : 'Refresh evidence'}
                                </Button>
                            )}
                        </Alert>
                    </div>
                )}
                {Object.keys(errors).length > 0 && (
                    <div className="px-5 pb-5">
                        <Alert tone="bad" role="alert">
                            {Object.values(errors).join(' ')}
                        </Alert>
                    </div>
                )}

                <dl className="divide-line border-line grid divide-y border-t sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    <Fact
                        label="Version"
                        hint={
                            synthesis.approved_version !== null &&
                            shown?.status === 'draft'
                                ? `Draft version ${shown.version} is waiting for approval.`
                                : undefined
                        }
                    >
                        {synthesis.approved_version !== null
                            ? `Version ${synthesis.approved_version} approved`
                            : shown
                              ? `Draft version ${shown.version}`
                              : 'No version yet'}
                    </Fact>
                    <Fact
                        label="Evidence"
                        hint={
                            status.freshness === 'none'
                                ? 'Appears once a profile is built.'
                                : undefined
                        }
                    >
                        {
                            {
                                none: 'Not built yet',
                                current: 'Up to date',
                                refresh_available: 'Refresh available',
                                stale: 'Changed since approval',
                            }[status.freshness]
                        }
                    </Fact>
                    <Fact
                        label="Interview"
                        hint={
                            interview.progress.total > 0
                                ? `${resolved} of ${interview.progress.total} topics handled`
                                : 'Starts after the market study.'
                        }
                    >
                        {interview.progress.total > 0 ? `${percent}%` : '—'}
                    </Fact>
                </dl>
            </Card>

            {profile && (
                <div className="grid gap-6 lg:grid-cols-2">
                    <section aria-label="Strongest capabilities">
                        <SectionTitle>Strongest capabilities</SectionTitle>
                        <Card className="mt-3 p-5">
                            {strengths.length === 0 ? (
                                <p className="text-fg-muted text-sm">
                                    None recorded yet.
                                </p>
                            ) : (
                                <ul className="flex flex-wrap gap-2">
                                    {strengths.map((c) => (
                                        <li key={c.id}>
                                            <Badge>{c.label}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </section>
                    <section aria-label="Lead projects">
                        <SectionTitle>Lead projects</SectionTitle>
                        <Card className="mt-3 p-5">
                            {topProjects.length === 0 ? (
                                <p className="text-fg-muted text-sm">
                                    None recorded yet.
                                </p>
                            ) : (
                                <ol className="space-y-3 text-sm">
                                    {topProjects.map((p, i) => (
                                        <li key={p.id}>
                                            <span className="text-fg font-medium">
                                                {i + 1}. {p.name}
                                            </span>
                                            {p.employer && (
                                                <span className="text-fg-muted">
                                                    {' '}
                                                    · {p.employer}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                            <button
                                type="button"
                                onClick={() => onOpenTab('profile')}
                                className="text-accent-text hover:text-fg mt-4 rounded-sm text-xs underline underline-offset-2"
                            >
                                See and edit the full profile
                            </button>
                        </Card>
                    </section>
                </div>
            )}
        </div>
    );
}
