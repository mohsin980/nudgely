<?php

namespace App\Exceptions\Email;

use RuntimeException;

/**
 * A webhook payload that cannot be understood as an inbound email.
 */
class InvalidInboundEmailException extends RuntimeException {}
