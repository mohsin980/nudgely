<?php

namespace App\Notifications;

use App\Enums\Team\NotificationType;
use Illuminate\Notifications\Notification;

/**
 * An in-app notification to a team member (database channel), sent by TeamNotifier only when
 * the person's preference for this type allows it.
 */
class TeamNotification extends Notification
{
    /**
     * @param  array<string, int|string|null>  $data
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $message,
        public readonly ?string $url,
        public readonly array $data = [],
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
        return ['message' => $this->message, 'url' => $this->url, 'kind' => $this->type->value] + $this->data;
    }
}
