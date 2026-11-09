<?php

namespace App\Support\JobDiscovery\Providers\Eightfold;

use RuntimeException;

/**
 * One failed Eightfold request, classified so the provider can decide
 * whether it ends the tenant's run or only skips a single position.
 * Never carries a response body.
 */
final class EightfoldRequestException extends RuntimeException
{
    public const RATE_LIMITED = 'rate_limited';

    public const TENANT_DISABLED = 'tenant_disabled';

    public const FORBIDDEN = 'forbidden';

    public const DOMAIN_REJECTED = 'domain_rejected';

    public const NOT_FOUND = 'not_found';

    public const SERVER_ERROR = 'server_error';

    public const CONNECTION = 'connection';

    public const INVALID_RESPONSE = 'invalid_response';

    public const CLIENT_ERROR = 'client_error';

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Categories that mean "stop talking to this tenant": retrying other
     * positions would only add load or repeat the same failure.
     */
    public function endsTenantRun(): bool
    {
        return ! in_array($this->category, [self::NOT_FOUND, self::INVALID_RESPONSE, self::CLIENT_ERROR], true);
    }
}
