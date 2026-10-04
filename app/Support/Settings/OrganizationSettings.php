<?php

namespace App\Support\Settings;

use App\Enums\TaskPriority;
use App\Enums\Team\NotificationChannel;
use App\Enums\Team\NotificationType;

/**
 * The organization's business defaults (organizations.settings, jsonb), read with built-in
 * fallbacks so a missing or old value never breaks anything. Every key here is used by the app:
 *
 * - estimate_valid_days, estimate_notes, estimate_tax_rate: pre-fill new estimates
 * - follow_up_delay_days, follow_up_time: pre-fill new follow-ups (manual and automation builder)
 * - task_priority: pre-fills new tasks
 * - automation_owner_id: stands in for an automation's creator who is no longer active
 * - task_assignee_id: assignee of automation tasks that don't name one
 * - notify_user_id: who "Notify the business" automation actions notify (null = owner and managers)
 * - business_hours: stored for scheduling (see BusinessHours)
 * - notifications: organization defaults per notification type and channel (people can override)
 */
final class OrganizationSettings
{
    public const KEYS = [
        'estimate_valid_days', 'estimate_notes', 'estimate_tax_rate',
        'follow_up_delay_days', 'follow_up_time', 'task_priority',
        'automation_owner_id', 'task_assignee_id', 'notify_user_id',
        'business_hours', 'notifications',
    ];

    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private readonly array $values) {}

    /**
     * @param  array<string, mixed>|null  $values
     */
    public static function fromArray(?array $values): self
    {
        return new self(array_intersect_key($values ?? [], array_flip(self::KEYS)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->values, $changes));
    }

    public function estimateValidDays(): int
    {
        return $this->int('estimate_valid_days', 1, 365) ?? (int) config('estimates.default_valid_days', 30);
    }

    public function estimateNotes(): ?string
    {
        $notes = $this->values['estimate_notes'] ?? null;

        return is_string($notes) && trim($notes) !== '' ? $notes : null;
    }

    /**
     * Percentage as a decimal string (e.g. "8.25"), or null for no default tax.
     */
    public function estimateTaxRate(): ?string
    {
        $rate = $this->values['estimate_tax_rate'] ?? null;

        return is_string($rate) && preg_match('/^\d{1,3}(\.\d{1,3})?$/', $rate) && (float) $rate <= 100 ? $rate : null;
    }

    public function followUpDelayDays(): int
    {
        return $this->int('follow_up_delay_days', 1, 60) ?? 3;
    }

    /**
     * Local time of day new follow-ups are due, "HH:MM".
     */
    public function followUpTime(): string
    {
        $time = $this->values['follow_up_time'] ?? null;

        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '10:00';
    }

    public function taskPriority(): TaskPriority
    {
        return TaskPriority::tryFrom((string) ($this->values['task_priority'] ?? '')) ?? TaskPriority::Medium;
    }

    public function automationOwnerId(): ?int
    {
        return $this->int('automation_owner_id', 1, PHP_INT_MAX);
    }

    public function taskAssigneeId(): ?int
    {
        return $this->int('task_assignee_id', 1, PHP_INT_MAX);
    }

    public function notifyUserId(): ?int
    {
        return $this->int('notify_user_id', 1, PHP_INT_MAX);
    }

    public function businessHours(): BusinessHours
    {
        return BusinessHours::fromArray(is_array($this->values['business_hours'] ?? null) ? $this->values['business_hours'] : null);
    }

    /**
     * The organization's default for a notification, or the built-in one.
     */
    public function notificationDefault(NotificationType $type, NotificationChannel $channel): bool
    {
        $value = $this->values['notifications'][$type->value][$channel->value] ?? null;

        return is_bool($value) ? $value : $type->defaultFor($channel);
    }

    private function int(string $key, int $min, int $max): ?int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }
}
