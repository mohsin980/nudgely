<?php

namespace App\Services\Team;

use App\Enums\Team\NotificationChannel;
use App\Enums\Team\NotificationType;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TeamNotification;
use App\Services\Email\EmailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Tells team members about something, in the app and/or by email, as each person's
 * preferences allow. Only active members of the organization are notified, never the person
 * who caused it. Idempotent per $key: a retried job or redelivered event notifies once.
 */
class TeamNotifier
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly EmailService $email,
    ) {}

    /**
     * @param  iterable<User>  $recipients
     * @param  array<string, int|string|null>  $data
     * @param  bool  $inApp  False when the caller already created its own in-app notification.
     * @return int How many people were notified.
     */
    public function notify(Organization $organization, NotificationType $type, iterable $recipients, string $message, ?string $url, string $key, ?User $actor = null, array $data = [], bool $inApp = true): int
    {
        $notified = 0;
        $seen = [];

        foreach ($recipients as $user) {
            if (isset($seen[$user->id]) || $user->id === $actor?->id || $user->organization_id !== $organization->id || ! $user->isActiveMember()) {
                continue;
            }

            $seen[$user->id] = true;
            $sent = false;

            if ($inApp && $this->preferences->enabled($user, $type, NotificationChannel::InApp, $organization)) {
                $sent = $this->inApp($user, $type, $message, $url, $key, $data) || $sent;
            }

            if ($this->preferences->enabled($user, $type, NotificationChannel::Email, $organization)) {
                $sent = $this->byEmail($organization, $user, $type, $message, $url, $key) || $sent;
            }

            $notified += $sent ? 1 : 0;
        }

        return $notified;
    }

    /**
     * @param  array<string, int|string|null>  $data
     */
    private function inApp(User $user, NotificationType $type, string $message, ?string $url, string $key, array $data): bool
    {
        $notification = new TeamNotification($type, $message, $url, $data);
        $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, "team|{$type->value}|{$key}|{$user->id}")->toString();

        if (DB::table('notifications')->where('id', $notification->id)->exists()) {
            return false;
        }

        try {
            DB::transaction(fn () => $user->notifyNow($notification));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * From the business's verified sender, through EmailService. Skipped (not failed) when the
     * business can't send email yet.
     */
    private function byEmail(Organization $organization, User $user, NotificationType $type, string $message, ?string $url, string $key): bool
    {
        $text = $message.($url ? "\n\nOpen in QuoteFollow: {$url}" : '')."\n\nYou can change which emails you get under Settings → Notifications.";

        try {
            $this->email->send($organization, $user->email, $type->label().': '.mb_substr($message, 0, 120), '<p>'.nl2br(e($text)).'</p>', $text,
                toName: $user->name, metadata: ['type' => 'team_notification', 'notification_type' => $type->value,
                    // Unique (messages_automation_key_unique): the same event emails each person once.
                    'automation_key' => hash('sha256', "team|{$type->value}|{$key}|{$user->id}")]);
        } catch (EmailSendingNotAllowedException $e) {
            Log::info('Team notification email skipped: business cannot send email.', ['organization_id' => $organization->id, 'type' => $type->value]);

            return false;
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
