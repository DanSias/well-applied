import { Link } from '@inertiajs/react';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, SectionTitle } from '@/components/ui/surface';
import { ageLabel, discoveryHeadline, providerBadge } from '@/lib/discovery';
import { relativeTimeFromNow } from '@/lib/relative-time';
import { index as rejectionsIndex } from '@/routes/discovery/rejections';
import type { DiscoveryProviderHealth, HomeDiscovery } from '@/types/home';

function Count({ label, value }: { label: string; value: number | null }) {
    return (
        <div>
            <dt className="text-fg-subtle text-xs">{label}</dt>
            <dd className="text-fg text-sm font-medium tabular-nums">
                {value ?? '—'}
            </dd>
        </div>
    );
}

function ProviderRow({ provider }: { provider: DiscoveryProviderHealth }) {
    const badge = providerBadge(provider);
    const success = provider.last_success;
    const failedSince =
        provider.latest !== null &&
        !provider.latest.is_last_success &&
        provider.latest.status !== 'succeeded';

    return (
        <li className="px-5 py-3.5">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <p className="text-fg text-sm font-medium">
                    {provider.label}
                    {!provider.scheduled && (
                        <span className="text-fg-subtle font-normal">
                            {' '}
                            · not scheduled
                        </span>
                    )}
                </p>
                <p className="text-fg-subtle flex flex-wrap items-center gap-2 text-xs">
                    <Badge tone={badge.tone}>{badge.text}</Badge>
                    {success?.at && (
                        <span title={success.at}>
                            {success.status === 'partial'
                                ? 'Last completed (partial)'
                                : 'Last succeeded'}{' '}
                            {ageLabel(success.age_hours)}
                            <span className="sr-only">
                                {' '}
                                ({relativeTimeFromNow(success.at)})
                            </span>
                        </span>
                    )}
                </p>
            </div>

            {success && (
                <dl className="mt-2 grid grid-cols-3 gap-x-4 gap-y-2 sm:grid-cols-5">
                    <Count
                        label={
                            success.retrieved_basis === 'after_prefilter'
                                ? 'Retrieved (after prefilter)'
                                : 'Retrieved'
                        }
                        value={success.retrieved}
                    />
                    <Count label="Accepted" value={success.accepted} />
                    <Count label="Created" value={success.created} />
                    <Count label="Updated" value={success.updated} />
                    <Count label="Filtered" value={success.filtered} />
                </dl>
            )}

            {success && success.filtered_by_reason.length > 0 && (
                <p className="text-fg-subtle mt-2 text-xs break-words">
                    Filtered:{' '}
                    {success.filtered_by_reason
                        .map((r) => `${r.label} ${r.count}`)
                        .join(' · ')}
                </p>
            )}

            {success?.status === 'partial' && success.partial_summary && (
                <p className="text-fg-subtle mt-2 text-xs break-words">
                    Partial: {success.partial_summary}
                </p>
            )}

            {failedSince && provider.latest && (
                <p className="text-bad mt-2 text-xs break-words">
                    Latest attempt {provider.latest.status}
                    {provider.latest.failure
                        ? `: ${provider.latest.failure}`
                        : ''}
                </p>
            )}
        </li>
    );
}

/**
 * Read-only discovery health for Overview: each provider's last successful attempt, how old it is, and what that attempt
 * retrieved, accepted, created, updated and filtered. A recent-attempt view, not a running total.
 */
export function DiscoveryHealth({ discovery }: { discovery: HomeDiscovery }) {
    const headline = discoveryHeadline(discovery);

    return (
        <section aria-label="Discovery">
            <SectionTitle
                aside={
                    <Link
                        href={rejectionsIndex.url()}
                        className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                    >
                        Filtered candidates ({discovery.active_rejections})
                    </Link>
                }
            >
                Discovery
            </SectionTitle>

            <Alert
                tone={headline.tone === 'ok' ? 'ok' : 'warn'}
                className="mt-3"
                role="status"
            >
                {headline.text}
            </Alert>
            <p className="text-fg-subtle mt-2 text-xs leading-relaxed">
                Discovery only runs while the scheduler process is running (
                <code>php artisan dev</code> or <code>schedule:work</code>), or
                when you run <code>php artisan discovery:run</code>. Counts are
                from each provider&rsquo;s most recent successful attempt and
                are not comparable across providers.
            </p>

            {discovery.providers.length > 0 && (
                <Card className="mt-3">
                    <ul className="divide-line divide-y">
                        {discovery.providers.map((provider) => (
                            <ProviderRow
                                key={provider.provider}
                                provider={provider}
                            />
                        ))}
                    </ul>
                </Card>
            )}
        </section>
    );
}
