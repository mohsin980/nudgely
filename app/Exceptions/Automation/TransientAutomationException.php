<?php

namespace App\Exceptions\Automation;

use RuntimeException;

/**
 * A temporary problem while running an action (e.g. a provider timeout). The action is retried.
 */
class TransientAutomationException extends RuntimeException {}
