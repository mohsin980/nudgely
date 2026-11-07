<?php

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * A model answered, but not usefully (bad JSON, refused, cut off). The chain then asks the next model.
 */
class InvalidModelOutputException extends RuntimeException {}
