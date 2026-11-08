<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A readiness review whose model answer cannot be used (nothing to assess).
 */
final class ReadinessReviewException extends RuntimeException {}
