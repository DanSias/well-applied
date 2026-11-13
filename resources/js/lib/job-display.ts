import type { JobPostingDiscoveryMetadata } from '@/types/job-posting';

/**
 * "USD 120,000–150,000", or null when the posting states no pay. Non-breaking spaces keep the currency and the range
 * on one line when a row wraps.
 */
export function formatCompensation(
    discovery: Pick<
        JobPostingDiscoveryMetadata,
        'compensation_min' | 'compensation_max' | 'compensation_currency'
    >,
): string | null {
    if (
        discovery.compensation_min === null &&
        discovery.compensation_max === null
    ) {
        return null;
    }

    const currency = discovery.compensation_currency ?? '';
    const min = discovery.compensation_min?.toLocaleString();
    const max = discovery.compensation_max?.toLocaleString();

    if (min && max) {
        return `${currency} ${min}⁠–⁠${max}`.trim();
    }

    return `${currency} ${min ?? max}`.trim();
}
