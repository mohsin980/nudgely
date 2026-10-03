<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app reminder about a follow-up (database channel only).
 */
class FollowUpNotification extends Notification
{
    public function __construct(
        public readonly string $message,
        public readonly string $url,
        public readonly int $followUpId,
        public readonly string $kind,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message, 'url' => $this->url, 'follow_up_id' => $this->followUpId, 'kind' => $this->kind];
    }
}
