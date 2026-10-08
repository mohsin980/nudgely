<?php

namespace App\Exceptions\Email;

use RuntimeException;

/**
 * A delivery event arrived before the send job recorded "sent". Retried later, not dropped.
 */
class MessageNotYetSentException extends RuntimeException {}
