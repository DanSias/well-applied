<?php

namespace App\Support\JobDiscovery;

use InvalidArgumentException;

/**
 * Thrown when a profile in config/job_discovery.php's `search_profiles`
 * section is missing a field or has a malformed value. A configuration
 * error, not a provider failure (that is Providers\
 * DiscoveryProviderException) — and, like all discovery exceptions,
 * safe to surface directly to the operator: the message names the
 * profile identifier and the exact offending field, and carries no
 * credentials, payloads or other sensitive context.
 */
final class SearchProfileConfigurationException extends InvalidArgumentException {}
