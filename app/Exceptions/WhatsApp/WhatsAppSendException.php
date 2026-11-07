<?php

namespace App\Exceptions\WhatsApp;

use RuntimeException;

/**
 * A WhatsApp message could not be sent. The message is safe to show to the business.
 */
class WhatsAppSendException extends RuntimeException {}
