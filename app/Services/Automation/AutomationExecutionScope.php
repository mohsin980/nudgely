<?php

namespace App\Services\Automation;

use Closure;

/**
 * Remembers the chain depth while an automation action runs, so any automation event the
 * action raises is evaluated one level deeper. This is what stops automation loops.
 */
class AutomationExecutionScope
{
    private ?int $depth = null;

    /**
     * The depth of the action currently executing, or null outside automation execution.
     */
    public function currentDepth(): ?int
    {
        return $this->depth;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAt(int $depth, Closure $callback): mixed
    {
        $previous = $this->depth;
        $this->depth = $depth;

        try {
            return $callback();
        } finally {
            $this->depth = $previous;
        }
    }
}
