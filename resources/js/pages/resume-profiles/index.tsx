import { Head, Link } from '@inertiajs/react';
import {
    NEXT_LABEL,
    profileStatus,
    STATE_LABEL,
    type NextAction,
} from '@/components/resume-profile/status';
import { Badge } from '@/components/ui/badge';
import { ButtonLink } from '@/components/ui/button';
import { Card } from '@/components/ui/surface';
import AppShell from '@/layouts/app-shell';
import { show } from '@/routes/resume-profiles';

type ProfileSummary = {
    slug: string;
    name: string;
    purpose: string | null;
    status_label: string;
    has_sample: boolean;
    headline: string | null;
    synthesis: {
        building: boolean;
        approved_version: number | null;
        approved_stale: boolean;
        outdated: boolean;
        draft_version: number | null;
        draft_stale: boolean;
    };
    interview: { total: number; open: number };
};

type Props = { profiles: ProfileSummary[] };

/** Which tab of the profile each next step lives on. */
const TAB_FOR: Record<NextAction, string | null> = {
    wait: null,
    build_market: 'market',
    interview: 'interview',
    build_profile: 'profile',
    review_draft: 'profile',
    refresh: null,
    use: null,
};

function ProfileCard({ profile }: { profile: ProfileSummary }) {
    const s = profile.synthesis;
    const status = profileStatus({
        building: s.building,
        hasSample: profile.has_sample,
        interview: profile.interview,
        approvedVersion: s.approved_version,
        approvedStale: s.approved_stale,
        outdated: s.outdated,
        draftVersion: s.draft_version,
        draftStale: s.draft_stale,
    });
    const tab = TAB_FOR[status.next];
    const resolved = profile.interview.total - profile.interview.open;
    const href = show.url(
        { resumeProfile: profile.slug },
        tab ? { query: { tab } } : undefined,
    );
    const label =
        status.next === 'use' ? 'Open profile' : NEXT_LABEL[status.next];

    return (
        <Card className="p-5">
            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
                <div className="max-w-prose min-w-0 flex-1 basis-80">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={show.url({ resumeProfile: profile.slug })}
                            className="text-fg rounded-sm text-base font-semibold tracking-tight hover:underline"
                        >
                            {profile.name}
                        </Link>
                        <Badge
                            tone={
                                status.state === 'approved'
                                    ? 'ok'
                                    : status.state === 'draft'
                                      ? 'accent'
                                      : 'neutral'
                            }
                        >
                            {STATE_LABEL[status.state]}
                            {s.approved_version !== null &&
                                ` · v${s.approved_version}`}
                        </Badge>
                        {status.state !== 'not_started' &&
                            (status.usable ? (
                                <Badge tone="ok">Ready for new resumes</Badge>
                            ) : (
                                <Badge tone="warn">
                                    Not ready for new resumes
                                </Badge>
                            ))}
                        {status.freshness === 'stale' && (
                            <Badge tone="warn">Evidence changed</Badge>
                        )}
                        {status.freshness === 'refresh_available' && (
                            <Badge>Refresh available</Badge>
                        )}
                    </div>
                    <p className="text-fg-muted mt-2 text-sm leading-relaxed">
                        {profile.headline ?? profile.purpose}
                    </p>
                    {profile.interview.total > 0 && (
                        <p className="text-fg-subtle mt-2 text-xs">
                            Interview: {resolved} of {profile.interview.total}{' '}
                            topics handled
                        </p>
                    )}
                </div>
                <div className="shrink-0">
                    {status.next === 'wait' ? (
                        <span className="text-fg-subtle text-sm">Working…</span>
                    ) : (
                        <ButtonLink variant="primary" href={href}>
                            {label}
                        </ButtonLink>
                    )}
                </div>
            </div>
        </Card>
    );
}

export default function ResumeProfilesIndex({ profiles }: Props) {
    return (
        <AppShell>
            <Head title="Resume Profiles" />
            <h1 className="text-fg text-2xl font-semibold tracking-tight">
                Resume Profiles
            </h1>
            <p className="text-fg-muted mt-1 max-w-2xl text-sm leading-relaxed">
                A profile studies real job postings for one kind of role, shows
                how your record compares, and asks about what is missing. What
                you confirm is added to your career data and improves every
                resume.
            </p>

            <ul className="mt-6 space-y-4">
                {profiles.map((profile) => (
                    <li key={profile.slug}>
                        <ProfileCard profile={profile} />
                    </li>
                ))}
            </ul>
        </AppShell>
    );
}
