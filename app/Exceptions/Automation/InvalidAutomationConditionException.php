<?php

namespace App\Exceptions\Automation;

use InvalidArgumentException;

/**
 * A condition that cannot be evaluated: unknown type, disallowed operator, invalid value,
 * or a condition on data the application does not have yet. The message is safe to show.
 */
class InvalidAutomationConditionException extends InvalidArgumentException {}
