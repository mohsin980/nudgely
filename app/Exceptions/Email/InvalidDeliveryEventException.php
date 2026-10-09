<?php

namespace App\Exceptions\Email;

use RuntimeException;

/**
 * A delivery event whose payload is not one we understand. It is never acted on.
 */
class InvalidDeliveryEventException extends RuntimeException {}
