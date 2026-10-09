<?php

namespace App\Services\Automation;

use App\Enums\Automation\AutomationActionRunStatus;

/**
 * The normalized outcome of one action. Messages are safe to show in automation logs.
 */
final readonly class AutomationActionResult
{
    /**
     * @param  array<string, mixed>  $data  Small, non-sensitive details (IDs, tag name, new status).
     */
    private function __construct(
        public bool $success,
        public AutomationActionRunStatus $status,
        public string $message,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function completed(string $message, array $data = []): self
    {
        return new self(true, AutomationActionRunStatus::Completed, $message, $data);
    }

    /**
     * Nothing needed doing (e.g. already done) or the action deliberately did not run. Not an error.
     *
     * @param  array<string, mixed>  $data
     */
    public static function skipped(string $message, array $data = []): self
    {
        return new self(true, AutomationActionRunStatus::Skipped, $message, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function failed(string $message, array $data = []): self
    {
        return new self(false, AutomationActionRunStatus::Failed, $message, $data);
    }

    /**
     * @return array{success: bool, status: string, message: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['success' => $this->success, 'status' => $this->status->value, 'message' => $this->message, 'data' => $this->data];
    }
}
