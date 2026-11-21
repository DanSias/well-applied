<?php

namespace App\Support\JobFreshness\Pilot;

use RuntimeException;

/** A request could not be completed (connection failure, timeout). Never retried. */
final class PilotTransportException extends RuntimeException {}
