import { Head, Link } from '@inertiajs/react';
import AppShell from '@/layouts/app-shell';
import { show } from '@/routes/resume-profiles';

type Props = {
    profiles: {
        slug: string;
        name: string;
        purpose: string | null;
        status_label: string;
    }[];
};

export default function ResumeProfilesIndex({ profiles }: Props) {
    return (
        <AppShell>
            <Head title="Resume Profiles" />
            <h1 className="text-xl font-semibold">Resume Profiles</h1>
            <p className="mt-1 max-w-2xl text-sm text-neutral-600 dark:text-neutral-400">
                A profile studies real job postings for one kind of role, shows
                how your record compares, and asks about what is missing. What
                you confirm is added to your career data and improves every
                resume.
            </p>

            <ul className="mt-6 space-y-3">
                {profiles.map((profile) => (
                    <li
                        key={profile.slug}
                        className="rounded-md border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <Link
                            href={show.url(profile.slug)}
                            className="font-medium underline-offset-2 hover:underline"
                        >
                            {profile.name}
                        </Link>
                        <p className="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                            {profile.purpose}
                        </p>
                        <p className="mt-2 text-xs text-neutral-500">
                            {profile.status_label}
                        </p>
                    </li>
                ))}
            </ul>
        </AppShell>
    );
}
