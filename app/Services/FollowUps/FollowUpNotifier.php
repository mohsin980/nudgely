<?php

namespace App\Services\FollowUps;

use App\Enums\OrganizationRole;
use App\Models\FollowUp;
use App\Models\User;
use App\Notifications\FollowUpNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Tells the business about follow-ups that need a person: the assignee, or else the
 * organization's admins. One notification per follow-up, kind and due time.
 */
class FollowUpNotifier
{
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

        foreach ($this->recipients($followUp) as $user) {
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
     * @return Collection<int, User>
     */
    private function recipients(FollowUp $followUp): Collection
    {
        $query = User::query()->where('organization_id', $followUp->organization_id);

        if ($followUp->assigned_to !== null && ($assignee = (clone $query)->whereKey($followUp->assigned_to)->first()) !== null) {
            return collect([$assignee]);
        }

        return $query->where('role', OrganizationRole::Admin)->get();
    }
}
