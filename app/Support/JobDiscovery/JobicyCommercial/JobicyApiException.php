<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

use RuntimeException;

/**
 * Why a Jobicy request did not produce a usable page. The message is always built from fixed text and the status code: never from the response body
 * or request headers, so a credential can not reach a log, a report or a stack trace through it. There is deliberately no `previous` exception.
 */
final class JobicyApiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const AUTH_FAILED = 'auth_failed';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const RATE_LIMITED = 'rate_limited';

    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    public const BAD_REQUEST = 'bad_request';

    public const CLIENT_ERROR = 'client_error';

    public const SERVER_ERROR = 'server_error';

    public const MALFORMED_RESPONSE = 'malformed_response';

    public const CONNECTION_FAILED = 'connection_failed';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('Jobicy request stopped: '.$reason.($status !== null ? " (HTTP {$status})" : '').($retryAfterSeconds !== null ? "; Retry-After {$retryAfterSeconds}s" : '').'.');
    }
}
