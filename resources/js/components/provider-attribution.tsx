import type { ProviderAttribution } from '@/types/job-posting';

/**
 * What a stored provider link is CALLED, and the credit its provider's terms require beside it. The link's URL is never changed:
 * for Jobicy it is the canonical Jobicy listing ("View on Jobicy"), for Himalayas it is the API's applicationLink, which every Apply
 * action must use unchanged ("Apply on Himalayas"). A source with no attribution rule keeps the generic wording.
 */
export const GENERIC_SOURCE_LABEL = 'Original posting ↗';

export function sourceLinkLabel(
    attribution: ProviderAttribution | null | undefined,
): string {
    return attribution?.link_label ?? GENERIC_SOURCE_LABEL;
}

/** "Listing provided by Jobicy" with a visible link to the provider, or nothing for a source without an attribution rule. */
export function ProviderCredit({
    attribution,
    className,
}: {
    attribution: ProviderAttribution | null | undefined;
    className?: string;
}) {
    if (!attribution) return null;

    return (
        <span
            className={className ?? 'text-muted-foreground text-xs'}
            data-testid="provider-credit"
        >
            {attribution.notice}{' '}
            <a
                href={attribution.home_url}
                target="_blank"
                rel="noopener noreferrer"
                className="underline underline-offset-2"
            >
                {attribution.name}
            </a>
        </span>
    );
}
