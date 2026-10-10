<?php

namespace App\Services\FollowUps;

use App\Enums\ConversationStatus;
use App\Enums\EstimateStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Enums\MessageDirection;
use App\Events\FollowUpDue;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Jobs\ProcessFollowUpJob;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\User;
use App\Services\Automation\AutomatedEmailPolicy;
use App\Services\Automation\EmailTemplateRenderer;
use App\Services\Email\EmailService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Runs follow-ups when they are due.
 *
 * The scheduler flips due follow-ups from pending to due in one atomic UPDATE … RETURNING
 * and queues a job for each, so every follow-up is queued once. The job then works on the
 * row under a lock (SELECT … FOR UPDATE), so a duplicate or concurrent job waits and then
 * finds the follow-up already handled. A unique index on the email's automation_key is
 * the last line of defence against a second email.
 *
 * Manual follow-ups: the owner is notified. Automated follow-ups: stop conditions are
 * checked, then the email is sent only when the organization allows unattended email;
 * otherwise the follow-up stays due as "ready to send" and the owner is notified.
 */
class FollowUpProcessor
{
    public const SENT = 'sent';

    public const NOTIFIED = 'notified';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const RATE_LIMITED = 'rate_limited';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const ALREADY_PROCESSED = 'already_processed';

    public const MISSING = 'missing';

    public function __construct(
        private readonly FollowUpService $followUps,
        private readonly FollowUpNotifier $notifier,
        private readonly AutomatedEmailPolicy $policy,
        private readonly EmailService $email,
        private readonly EmailTemplateRenderer $templates,
    ) {}

    /**
     * Scheduler step: mark due follow-ups and queue one job each. Cheap; no checks here.
     */
    public function markDue(): int
    {
        $now = now();

        // Pick a bounded set under row locks, then flip exactly those rows. Both steps share a transaction, so the
        // batch size is a hard limit: an IN (subquery ... LIMIT ... FOR UPDATE) can be re-run per row by the planner.
        $rows = DB::transaction(function () use ($now) {
            $ids = collect(DB::select(
                'select id from follow_ups where status = ? and due_at <= ? order by due_at limit ? for update skip locked',
                [FollowUpStatus::Pending->value, $now, (int) config('follow_ups.batch_size')],
            ))->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($ids === []) {
                return [];
            }

            // The rows are locked above, so this update and the read below see exactly the batch.
            DB::table('follow_ups')
                ->whereIn('id', $ids)
                ->where('status', FollowUpStatus::Pending->value)
                ->update(['status' => FollowUpStatus::Due->value, 'updated_at' => $now]);

            return DB::table('follow_ups')
                ->whereIn('id', $ids)
                ->get(['id', 'organization_id', 'customer_id', 'conversation_id', 'estimate_id', 'due_at']);
        });

        foreach ($rows as $row) {
            ProcessFollowUpJob::dispatch((int) $row->id);

            if ($row->customer_id !== null) {
                FollowUpDue::dispatch((int) $row->organization_id, (int) $row->id, $row->conversation_id, (int) $row->customer_id, $row->estimate_id, CarbonImmutable::parse($row->due_at)->getTimestamp());
            }
        }

        return count($rows) + $this->requeueStranded($now);
    }

    /**
     * A follow-up marked due whose job was never queued (the queue was unreachable at that moment)
     * would wait forever. Queue those again. Re-queuing is safe: process() locks the row and skips
     * anything already handled. Touching updated_at leases them for the stranded window.
     */
    private function requeueStranded(CarbonInterface $now): int
    {
        $ids = FollowUp::query()
            ->where('status', FollowUpStatus::Due)
            ->whereNull('due_notified_at')
            ->whereNull('processed_at')
            ->where('updated_at', '<', $now->copy()->subMinutes((int) config('reliability.follow_ups.stranded_after_minutes')))
            ->orderBy('id')
            ->limit((int) config('follow_ups.batch_size'))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        FollowUp::query()->whereIn('id', $ids)->update(['updated_at' => $now]);

        foreach ($ids as $id) {
            ProcessFollowUpJob::dispatch((int) $id);
        }

        Log::warning('Stranded due follow-ups were queued again.', ['count' => $ids->count()]);

        return $ids->count();
    }

    /**
     * Process one due follow-up. Safe to call any number of times, concurrently.
     */
    public function process(int $followUpId): string
    {
        $outcome = DB::transaction(function () use ($followUpId) {
            $followUp = FollowUp::query()->lockForUpdate()->find($followUpId);

            if ($followUp === null) {
                return self::MISSING;
            }

            // Already completed/skipped/cancelled, rescheduled, or already handed to a person.
            if ($followUp->status !== FollowUpStatus::Due || $followUp->due_notified_at !== null) {
                return self::ALREADY_PROCESSED;
            }

            if ($followUp->type === FollowUpType::Manual) {
                $this->awaitPerson($followUp, FollowUpNotifier::DUE, 'Reminder sent to the team.');

                return self::NOTIFIED;
            }

            if (($reason = $this->stopReason($followUp)) !== null) {
                $this->followUps->skipLocked($followUp, $reason);

                return self::SKIPPED;
            }

            if (! $this->mayAutoSend($followUp)) {
                $this->awaitPerson($followUp, FollowUpNotifier::READY, 'Follow-up ready: waiting for someone to send it.');

                return self::AWAITING_APPROVAL;
            }

            if ($this->recentlyFollowedUp($followUp)) {
                $this->followUps->skipLocked($followUp, FollowUpSkipReason::RecentlyFollowedUp);

                return self::SKIPPED;
            }

            if ($this->rateLimited($followUp->organization_id)) {
                $this->awaitPerson($followUp, FollowUpNotifier::READY, 'Follow-up ready: the automatic follow-up email limit was reached.');

                return self::RATE_LIMITED;
            }

            return $this->deliver($followUp, null)[0];
        });

        Log::info('Follow-up processed.', ['follow_up_id' => $followUpId, 'outcome' => $outcome]);

        return $outcome;
    }

    /**
     * A person approves and sends a due automated follow-up ("Send Follow-Up").
     *
     * @return array{0: string, 1: string} Outcome code and a message to show.
     *
     * @throws InvalidFollowUpException
     */
    public function sendNow(FollowUp $followUp, User $actor): array
    {
        if ($actor->organization_id === null || $actor->organization_id !== $followUp->organization_id) {
            throw new InvalidFollowUpException('Follow-up not found.');
        }

        return DB::transaction(function () use ($followUp, $actor) {
            $locked = FollowUp::query()->lockForUpdate()->findOrFail($followUp->id);

            if ($locked->status !== FollowUpStatus::Due) {
                throw InvalidFollowUpException::transition(strtolower($locked->status->label()), 'sent');
            }

            if (! $locked->isAutomated() || ! $locked->hasEmail()) {
                throw new InvalidFollowUpException('This follow-up has no email to send.');
            }

            if (($reason = $this->stopReason($locked, requireActiveAutomation: false)) !== null) {
                $this->followUps->skipLocked($locked, $reason);

                return [self::SKIPPED, 'Follow-up skipped: '.$reason->label()];
            }

            return $this->deliver($locked, $actor);
        });
    }

    /**
     * Tell the owner about due follow-ups nobody has dealt with for a while.
     */
    public function notifyOverdue(): int
    {
        $ids = FollowUp::query()
            ->where('status', FollowUpStatus::Due)
            ->where('due_at', '<=', now()->subHours((int) config('follow_ups.overdue_after_hours')))
            ->whereNull('overdue_notified_at')
            ->orderBy('due_at')
            ->limit((int) config('follow_ups.batch_size'))
            ->pluck('id');

        return $ids->filter(fn (int $id) => DB::transaction(function () use ($id) {
            $followUp = FollowUp::query()->lockForUpdate()->find($id);

            if ($followUp === null || $followUp->status !== FollowUpStatus::Due || $followUp->overdue_notified_at !== null) {
                return false;
            }

            $followUp->forceFill(['overdue_notified_at' => now()])->save();
            $this->notifier->notify($followUp, FollowUpNotifier::OVERDUE);

            return true;
        }))->count();
    }

    /**
     * The job gave up (retries exhausted, timeout).
     */
    public function failAfterRetries(int $followUpId): void
    {
        DB::transaction(function () use ($followUpId) {
            $followUp = FollowUp::query()->lockForUpdate()->find($followUpId);

            if ($followUp !== null && $followUp->status === FollowUpStatus::Due && $followUp->due_notified_at === null) {
                $this->fail($followUp, 'Follow-up could not be processed.');
            }
        });
    }

    /**
     * Why an automated follow-up must not run, or null. Checked at due time and before a manual send.
     */
    public function stopReason(FollowUp $followUp, bool $requireActiveAutomation = true): ?FollowUpSkipReason
    {
        $organization = $followUp->organization;

        if ($requireActiveAutomation) {
            if (! config('automation.enabled') || ! $organization->automations_enabled) {
                return FollowUpSkipReason::AutomationsDisabled;
            }

            $automation = $followUp->automation_id === null ? null : Automation::query()->forOrganization($organization)->find($followUp->automation_id);

            if ($automation === null) {
                return FollowUpSkipReason::AutomationDeleted;
            }

            if (! $automation->isActive()) {
                return FollowUpSkipReason::AutomationInactive;
            }
        }

        $customer = $organization->customers()->find($followUp->customer_id);

        if ($customer === null || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            return FollowUpSkipReason::CustomerUnavailable;
        }

        $conversation = $followUp->conversation_id === null ? null : $organization->conversations()->find($followUp->conversation_id);

        if ($conversation === null || $conversation->customer_id !== $customer->id) {
            return FollowUpSkipReason::ConversationMissing;
        }

        if ($this->customerRepliedSince($followUp)) {
            return FollowUpSkipReason::CustomerReplied;
        }

        if ($followUp->estimate_id !== null && in_array(
            $organization->estimates()->whereKey($followUp->estimate_id)->toBase()->value('status'),
            [EstimateStatus::Accepted->value, EstimateStatus::Declined->value, EstimateStatus::Cancelled->value],
            true,
        )) {
            return FollowUpSkipReason::EstimateClosed;
        }

        if ($customer->hasOptedOutOfEmail()) {
            return FollowUpSkipReason::OptedOut;
        }

        if ($conversation->status === ConversationStatus::Closed) {
            return FollowUpSkipReason::ConversationClosed;
        }

        if ($this->anotherFollowUpCompleted($followUp)) {
            return FollowUpSkipReason::AnotherFollowUpCompleted;
        }

        return null;
    }

    /**
     * Send the follow-up email through EmailService and complete the follow-up.
     *
     * @return array{0: string, 1: string}
     */
    private function deliver(FollowUp $locked, ?User $actor): array
    {
        $organization = $locked->organization;
        $conversation = $organization->conversations()->findOrFail($locked->conversation_id);
        $customer = $organization->customers()->findOrFail($locked->customer_id);
        $key = hash('sha256', 'follow_up|'.$locked->id);

        $blocked = $this->policy->checkDelivery($organization, $conversation, $customer, $key);

        if ($blocked !== null) {
            return match ($blocked->data['reason'] ?? null) {
                'already_exists' => $this->completeSent($locked, (int) $blocked->data['message_id'], $actor, alreadySent: true),
                'opted_out' => $this->skipped($locked, FollowUpSkipReason::OptedOut),
                'rate_limited' => $actor !== null
                    ? [self::RATE_LIMITED, $blocked->message]
                    : [$this->awaitPerson($locked, FollowUpNotifier::READY, 'Follow-up ready: the automatic email limit was reached.'), $blocked->message],
                default => $this->failed($locked, $blocked->message, $actor),
            };
        }

        try {
            $estimate = $locked->estimate_id === null ? null : $organization->estimates()->find($locked->estimate_id);
            $text = $this->templates->render($locked->body, $customer, $organization, $estimate);
            $message = $this->email->sendToConversation(
                $conversation,
                $this->templates->render($locked->subject, $customer, $organization, $estimate),
                '<p>'.nl2br(e($text)).'</p>',
                $text,
                ['type' => 'follow_up', 'automation_key' => $key, 'follow_up_id' => (string) $locked->id],
            );
        } catch (InvalidEmailTemplateException|EmailSendingNotAllowedException $e) {
            return $this->failed($locked, $e->getMessage(), $actor);
        } catch (UniqueConstraintViolationException) {
            // Another worker's email for this follow-up is already stored.
            return $this->completeSent($locked, (int) $this->policy->sentMessageId($organization, $key), $actor, alreadySent: true);
        }

        $this->policy->recordSent($organization->id);
        RateLimiter::hit(self::hourKey($organization->id), 3600);
        RateLimiter::hit(self::dayKey($organization->id), 86400);

        return $this->completeSent($locked, $message->id, $actor);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function completeSent(FollowUp $locked, int $messageId, ?User $actor, bool $alreadySent = false): array
    {
        $outcome = $actor === null ? 'Follow-up email sent automatically.' : "Follow-up email sent by {$actor->name}.";

        $locked->forceFill([
            'status' => FollowUpStatus::Completed,
            'completed_at' => now(),
            'completed_by' => $actor?->id,
            'message_id' => $messageId,
            'outcome' => $outcome,
            'processed_at' => now(),
        ]);
        $this->followUps->record($locked, $this->followUps->event('email_sent', $actor, $outcome));
        $locked->save();

        Log::info('Follow-up email sent.', ['organization_id' => $locked->organization_id, 'follow_up_id' => $locked->id, 'message_id' => $messageId]);

        return $alreadySent ? [self::ALREADY_PROCESSED, 'This follow-up was already sent.'] : [self::SENT, $outcome];
    }

    /**
     * Leave the follow-up due for a person and notify them. Returns the outcome code.
     */
    private function awaitPerson(FollowUp $locked, string $kind, string $detail): string
    {
        $locked->forceFill(['due_notified_at' => now(), 'outcome' => $detail]);
        $this->followUps->record($locked, $this->followUps->event($kind === FollowUpNotifier::DUE ? 'due' : 'ready', null, $detail));
        $locked->save();

        $this->notifier->notify($locked, $kind);

        return $kind === FollowUpNotifier::DUE ? self::NOTIFIED : self::AWAITING_APPROVAL;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function skipped(FollowUp $locked, FollowUpSkipReason $reason): array
    {
        $this->followUps->skipLocked($locked, $reason);

        return [self::SKIPPED, 'Follow-up skipped: '.$reason->label()];
    }

    /**
     * A person's send attempt that can't go out leaves the follow-up due; an automatic one fails it.
     *
     * @return array{0: string, 1: string}
     */
    private function failed(FollowUp $locked, string $reason, ?User $actor): array
    {
        if ($actor === null) {
            $this->fail($locked, $reason);
        }

        return [self::FAILED, 'Follow-up not sent: '.$reason];
    }

    private function fail(FollowUp $locked, string $reason): void
    {
        $locked->forceFill(['status' => FollowUpStatus::Failed, 'outcome' => 'Follow-up not sent: '.$reason, 'processed_at' => now()]);
        $this->followUps->record($locked, $this->followUps->event('failed', null, $reason));
        $locked->save();

        $this->notifier->notify($locked, FollowUpNotifier::FAILED);
        Log::warning('Follow-up failed.', ['organization_id' => $locked->organization_id, 'follow_up_id' => $locked->id]);
    }

    /**
     * Unattended email: on for the organization, approval off, and not required by the action.
     */
    private function mayAutoSend(FollowUp $followUp): bool
    {
        if (! $followUp->organization->allowsUnattendedAutomatedEmail() || ! $followUp->hasEmail()) {
            return false;
        }

        $action = $followUp->automation_action_id === null ? null : AutomationAction::query()
            ->where('automation_id', $followUp->automation_id)
            ->find($followUp->automation_action_id);

        return ! ($action?->requires_approval ?? false);
    }

    /**
     * Any reply from this customer (in any of their conversations) after the follow-up was scheduled.
     */
    private function customerRepliedSince(FollowUp $followUp): bool
    {
        return Message::query()
            ->where('organization_id', $followUp->organization_id)
            ->where('direction', MessageDirection::Inbound)
            ->whereIn('conversation_id', fn ($query) => $query->select('id')->from('conversations')
                ->where('organization_id', $followUp->organization_id)
                ->where('customer_id', $followUp->customer_id))
            ->where('received_at', '>', $followUp->created_at)
            ->exists();
    }

    private function anotherFollowUpCompleted(FollowUp $followUp): bool
    {
        return FollowUp::query()
            ->where('organization_id', $followUp->organization_id)
            ->where('customer_id', $followUp->customer_id)
            ->whereKeyNot($followUp->id)
            ->where('status', FollowUpStatus::Completed)
            ->where('completed_at', '>', $followUp->created_at)
            ->exists();
    }

    /**
     * No more than one automated follow-up email to the same customer within the minimum interval.
     */
    private function recentlyFollowedUp(FollowUp $followUp): bool
    {
        return FollowUp::query()
            ->where('organization_id', $followUp->organization_id)
            ->where('customer_id', $followUp->customer_id)
            ->whereKeyNot($followUp->id)
            ->where('type', FollowUpType::Automated)
            ->whereNotNull('message_id')
            ->where('completed_at', '>=', now()->subHours((int) config('follow_ups.limits.min_interval_hours')))
            ->exists();
    }

    private function rateLimited(int $organizationId): bool
    {
        return RateLimiter::tooManyAttempts(self::hourKey($organizationId), (int) config('follow_ups.limits.max_emails_per_hour'))
            || RateLimiter::tooManyAttempts(self::dayKey($organizationId), (int) config('follow_ups.limits.max_emails_per_day'));
    }

    public static function hourKey(int $organizationId): string
    {
        return "follow-up-email-hour:{$organizationId}";
    }

    public static function dayKey(int $organizationId): string
    {
        return "follow-up-email-day:{$organizationId}";
    }
}
