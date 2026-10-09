<?php

namespace App\Exceptions\Webhooks;

use RuntimeException;

/**
 * Safe to show an operator: never includes the payload or a provider response.
 */
class WebhookReplayException extends RuntimeException {}
