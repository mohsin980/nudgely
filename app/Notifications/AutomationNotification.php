<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notification created by an automation (database channel only).
 */
class AutomationNotification extends Notification
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $url,
        public readonly ?int $conversationId,
        public readonly int $automationId,
        public readonly int $automationActionId,
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
        return [
            'message' => $this->message,
            'url' => $this->url,
            'conversation_id' => $this->conversationId,
            'automation_id' => $this->automationId,
            'automation_action_id' => $this->automationActionId,
        ];
    }
}
