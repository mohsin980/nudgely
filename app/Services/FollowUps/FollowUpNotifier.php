<?php

namespace App\Services\FollowUps;

use App\Enums\Team\NotificationChannel;
use App\Enums\Team\NotificationType;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\FollowUpNotification;
use App\Services\Team\NotificationPreferences;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Tells the business about follow-ups that need a person: the assignee, or else the
 * organization's admins. One notification per follow-up, kind and due time.
 */
class FollowUpNotifier
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public const DUE = 'due';

    public const READY = 'ready';

    public const OVERDUE = 'overdue';

    public const FAILED = 'failed';

    public function notify(FollowUp $followUp, string $kind): int
    {
        $name = $followUp->customer?->name ?? 'A customer';

        $message = match ($kind) {
            self::DUE => "{$name}'s follow-up is due today.",
            self::READY => "{$name}'s follow-up is ready to send.",
            self::OVERDUE => "{$name}'s follow-up is overdue.",
            self::FAILED => "{$name}'s follow-up email could not be sent.",
        };

        $sent = 0;
        $recipients = $this->recipients($followUp);
        $organization = Organization::findOrFail($followUp->organization_id);

        // Email, for people who asked for follow-up emails (once per follow-up, kind and due time).
        app(TeamNotifier::class)->notify($organization, NotificationType::FollowUpDue, $recipients, $message, route('follow-ups.index'),
            "follow-up:{$followUp->id}:{$kind}:{$followUp->due_at->timestamp}", inApp: false);

        foreach ($recipients as $user) {
            if (! $this->preferences->enabled($user, NotificationType::FollowUpDue, NotificationChannel::InApp, $organization)) {
                continue;
            }

            $notification = new FollowUpNotification($message, route('follow-ups.index'), $followUp->id, $kind);
            // Deterministic: a retried job can't notify twice, but a rescheduled follow-up can notify again.
            $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, "follow-up|{$followUp->id}|{$kind}|{$followUp->due_at->timestamp}|{$user->id}")->toString();

            if (DB::table('notifications')->where('id', $notification->id)->exists()) {
                continue;
            }

            $user->notifyNow($notification);
            $sent++;
        }

        return $sent;
    }

    /**
     * The follow-up was dealt with (completed, cancelled, skipped, failed or rescheduled):
     * its unread reminders no longer need anyone's attention.
     */
    public function resolve(FollowUp $followUp): int
    {
        return DB::table('notifications')
            ->where('type', FollowUpNotification::class)
            ->whereNull('read_at')
            ->whereRaw("(data::jsonb ->> 'follow_up_id') = ?", [(string) $followUp->id])
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(FollowUp $followUp): Collection
    {
        $team = app(TeamDirectory::class);
        $assignee = $team->activeMember($followUp->organization_id, $followUp->assigned_to);

        // The assignee while active, else the owner and managers.
        return $assignee !== null ? collect([$assignee]) : $team->leaders($followUp->organization_id);
    }
}
