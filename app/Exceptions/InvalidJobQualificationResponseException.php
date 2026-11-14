<?php

namespace App\Exceptions;

use RuntimeException;

/** The model answered, but not in the contract: wrong shape, an unknown value, an invented evidence id. */
class InvalidJobQualificationResponseException extends RuntimeException {}
