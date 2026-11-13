<?php

namespace App\Support\JobDecisions;

use RuntimeException;

/** A decision the rules do not allow (bad transition, a rule overriding a person, a stale undo). The message is safe to show. */
final class JobDecisionException extends RuntimeException {}
