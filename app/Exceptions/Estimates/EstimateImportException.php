<?php

namespace App\Exceptions\Estimates;

use RuntimeException;

/**
 * A CSV import that cannot continue. The message is written for the business, so it is shown as it is.
 */
class EstimateImportException extends RuntimeException {}
