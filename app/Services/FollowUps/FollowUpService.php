<?php

namespace App\Services\FollowUps;

use App\Enums\FollowUpCancelReason;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Models\Automation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates follow-ups and moves them between statuses.
 *
 * Every change locks the follow-up row, checks FollowUpStatus::canTransitionTo() and
 * appends to the follow-up's history, so concurrent requests and workers can't both act.
 * The organization always comes from the acting user or the automation, never from input.
 */
class FollowUpService
{
    public const MAX_NOTES = 1000;

    /**
     * A person schedules a reminder for a customer (optionally on one of their conversations).
     *
     * @throws InvalidFollowUpException
     */
    public function scheduleManual(User $actor, Customer $customer, DateTimeInterface $dueAt, ?string $notes = null, ?Conversation $conversation = null, ?User $assignee = null, ?Estimate $estimate = null): FollowUp
    {
        $organizationId = $actor->organization_id ?? throw new InvalidFollowUpException('You are not part of an organization.');

        if ($customer->organization_id !== $organizationId) {
            throw new InvalidFollowUpException('Customer not found.');
        }

        if ($conversation !== null && ($conversation->organization_id !== $organizationId || $conversation->customer_id !== $customer->id)) {
            throw new InvalidFollowUpException('That conversation does not belong to this customer.');
        }

        if ($estimate !== null && ($estimate->organization_id !== $organizationId || $estimate->customer_id !== $customer->id)) {
            throw new InvalidFollowUpException('That estimate does not belong to this customer.');
        }

        $this->assertAssignable($assignee, $organizationId);

        $notes = $this->notes($notes);
        $dueAt = $this->validDueAt($dueAt);

        $followUp = new FollowUp;
        $followUp->forceFill([
            'organization_id' => $organizationId,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation?->id,
            'estimate_id' => $estimate?->id,
            'type' => FollowUpType::Manual,
            'status' => FollowUpStatus::Pending,
            'created_by' => $actor->id,
            'assigned_to' => $assignee?->id,
            'due_at' => $dueAt,
            'notes' => $notes,
            'metadata' => ['history' => [$this->event('scheduled', $actor)]],
        ])->save();

        Log::info('Follow-up scheduled.', ['organization_id' => $organizationId, 'follow_up_id' => $followUp->id, 'type' => 'manual', 'user_id' => $actor->id]);

        return $followUp;
    }

    /**
     * An automation schedules a follow-up email. Idempotent per key (one per action and event).
     *
     * @return array{0: FollowUp, 1: bool} The follow-up, and whether it was newly created.
     */
    public function scheduleAutomated(Automation $automation, Conversation $conversation, DateTimeInterface $dueAt, string $subject, string $body, ?string $idempotencyKey, array $attributes = []): array
    {
        if ($conversation->organization_id !== $automation->organization_id) {
            throw new InvalidFollowUpException('The conversation does not belong to this organization.');
        }

        if ($idempotencyKey !== null && ($existing = FollowUp::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return [$existing, false];
        }

        try {
            $followUp = DB::transaction(function () use ($automation, $conversation, $dueAt, $subject, $body, $idempotencyKey, $attributes) {
                $followUp = new FollowUp;
                $followUp->forceFill($attributes + [
                    'organization_id' => $automation->organization_id,
                    'customer_id' => $conversation->customer_id,
                    'conversation_id' => $conversation->id,
                    'automation_id' => $automation->id,
                    'type' => FollowUpType::Automated,
                    'status' => FollowUpStatus::Pending,
                    'due_at' => CarbonImmutable::instance($dueAt)->utc(),
                    'subject' => $subject,
                    'body' => $body,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => ['reason' => $automation->name, 'history' => [$this->event('scheduled', null, 'By automation “'.$automation->name.'”')]],
                ])->save();

                return $followUp;
            });
        } catch (UniqueConstraintViolationException) {
            return [FollowUp::query()->where('idempotency_key', $idempotencyKey)->firstOrFail(), false];
        }

        Log::info('Follow-up scheduled.', ['organization_id' => $followUp->organization_id, 'follow_up_id' => $followUp->id, 'type' => 'automated', 'automation_id' => $automation->id]);

        return [$followUp, true];
    }

    /**
     * An automation schedules a reminder for the team (a manual-type follow-up: the scheduler
     * notifies people when it is due; nothing is emailed). Idempotent per key.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: FollowUp, 1: bool} The follow-up, and whether it was newly created.
     */
    public function scheduleReminder(Automation $automation, Customer $customer, ?Conversation $conversation, ?Estimate $estimate, DateTimeInterface $dueAt, string $notes, ?string $idempotencyKey, array $attributes = []): array
    {
        if ($customer->organization_id !== $automation->organization_id
            || ($conversation !== null && ($conversation->organization_id !== $automation->organization_id || $conversation->customer_id !== $customer->id))
            || ($estimate !== null && ($estimate->organization_id !== $automation->organization_id || $estimate->customer_id !== $customer->id))) {
            throw new InvalidFollowUpException('The customer, conversation or estimate does not belong to this organization.');
        }

        if ($idempotencyKey !== null && ($existing = FollowUp::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return [$existing, false];
        }

        try {
            $followUp = DB::transaction(function () use ($automation, $customer, $conversation, $estimate, $dueAt, $notes, $idempotencyKey, $attributes) {
                $followUp = new FollowUp;
                $followUp->forceFill($attributes + [
                    'organization_id' => $automation->organization_id,
                    'customer_id' => $customer->id,
                    'conversation_id' => $conversation?->id,
                    'estimate_id' => $estimate?->id,
                    'automation_id' => $automation->id,
                    'type' => FollowUpType::Manual,
                    'status' => FollowUpStatus::Pending,
                    'created_by' => $automation->created_by,
                    'due_at' => CarbonImmutable::instance($dueAt)->utc(),
                    'notes' => $this->notes($notes),
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => ['reason' => $automation->name, 'history' => [$this->event('scheduled', null, 'By automation “'.$automation->name.'”')]],
                ])->save();

                return $followUp;
            });
        } catch (UniqueConstraintViolationException) {
            return [FollowUp::query()->where('idempotency_key', $idempotencyKey)->firstOrFail(), false];
        }

        Log::info('Follow-up scheduled.', ['organization_id' => $followUp->organization_id, 'follow_up_id' => $followUp->id, 'type' => 'reminder', 'automation_id' => $automation->id]);

        return [$followUp, true];
    }

    /**
     * An automation completes or cancels an open follow-up. Returns false when it was no longer open.
     */
    public function closeByAutomation(int $followUpId, FollowUpStatus $to, Automation $automation): bool
    {
        abort_unless(in_array($to, [FollowUpStatus::Completed, FollowUpStatus::Cancelled], true), 500);

        return DB::transaction(function () use ($followUpId, $to, $automation) {
            $locked = FollowUp::query()->where('organization_id', $automation->organization_id)->lockForUpdate()->find($followUpId);

            if ($locked === null || ! $locked->status->canTransitionTo($to)) {
                return false;
            }

            $detail = 'By automation “'.$automation->name.'”';
            $locked->forceFill($to === FollowUpStatus::Completed
                ? ['status' => $to, 'completed_at' => now(), 'completion_notes' => $detail, 'outcome' => 'Completed by an automation.']
                : ['status' => $to, 'cancelled_at' => now(), 'cancelled_reason' => FollowUpCancelReason::Automation, 'outcome' => 'Cancelled by an automation.']);
            $this->record($locked, $this->event($to === FollowUpStatus::Completed ? 'completed' : 'cancelled', null, $detail));
            $locked->save();

            Log::info('Follow-up '.$to->value.' by automation.', ['organization_id' => $locked->organization_id, 'follow_up_id' => $locked->id, 'automation_id' => $automation->id]);

            return true;
        });
    }

    /**
     * @throws InvalidFollowUpException
     */
    public function complete(FollowUp $followUp, User $actor, ?string $notes = null): FollowUp
    {
        $notes = $this->notes($notes);

        return $this->change($followUp, $actor, FollowUpStatus::Completed, 'completed', function (FollowUp $locked) use ($actor, $notes) {
            $locked->forceFill(['completed_at' => now(), 'completed_by' => $actor->id, 'completion_notes' => $notes]);

            return $notes;
        });
    }

    /**
     * Give an open follow-up to someone else on the team (or nobody).
     *
     * @throws InvalidFollowUpException
     */
    public function assign(FollowUp $followUp, User $actor, ?User $assignee): FollowUp
    {
        if ($followUp->organization_id !== $actor->organization_id) {
            throw new InvalidFollowUpException('Follow-up not found.');
        }

        if (! $followUp->status->isOpen()) {
            throw new InvalidFollowUpException('Only open follow-ups can be reassigned.');
        }

        $this->assertAssignable($assignee, $followUp->organization_id);
        $followUp->forceFill(['assigned_to' => $assignee?->id])->save();
        Log::info('Follow-up assigned.', ['organization_id' => $followUp->organization_id, 'follow_up_id' => $followUp->id, 'user_id' => $actor->id]);

        return $followUp;
    }

    /**
     * Only active members of the same organization can be given a follow-up.
     *
     * @throws InvalidFollowUpException
     */
    private function assertAssignable(?User $assignee, int $organizationId): void
    {
        if ($assignee !== null && $assignee->organization_id !== $organizationId) {
            throw new InvalidFollowUpException('Follow-ups can only be assigned to people in your organization.');
        }

        if ($assignee !== null && ! $assignee->isActiveMember()) {
            throw new InvalidFollowUpException('Follow-ups can only be assigned to active team members.');
        }
    }

    /**
     * Move the due time; a due follow-up goes back to pending. Same record, history kept.
     *
     * @throws InvalidFollowUpException
     */
    public function reschedule(FollowUp $followUp, User $actor, DateTimeInterface $dueAt): FollowUp
    {
        $dueAt = $this->validDueAt($dueAt);

        return $this->locked($followUp, $actor, function (FollowUp $locked) use ($actor, $dueAt) {
            if (! $locked->status->isOpen()) {
                throw InvalidFollowUpException::transition(strtolower($locked->status->label()), 'rescheduled');
            }

            $from = $locked->due_at;
            $locked->forceFill([
                'status' => FollowUpStatus::Pending,
                'due_at' => $dueAt,
                'due_notified_at' => null,
                'overdue_notified_at' => null,
            ]);
            $this->record($locked, $this->event('rescheduled', $actor, null, ['from' => $from->toIso8601String(), 'to' => $dueAt->toIso8601String()]));
            $locked->save();

            return $locked;
        });
    }

    /**
     * @throws InvalidFollowUpException
     */
    public function cancel(FollowUp $followUp, User $actor, FollowUpCancelReason $reason, ?string $note = null): FollowUp
    {
        $note = $this->notes($note);

        return $this->change($followUp, $actor, FollowUpStatus::Cancelled, 'cancelled', function (FollowUp $locked) use ($reason, $note) {
            $locked->forceFill(['cancelled_at' => now(), 'cancelled_reason' => $reason, 'outcome' => $note]);

            return $reason->label().($note ? ": {$note}" : '');
        });
    }

    /**
     * The system decides a follow-up should not run. Returns false if it was no longer open.
     */
    public function skip(int $followUpId, FollowUpSkipReason $reason): bool
    {
        return DB::transaction(function () use ($followUpId, $reason) {
            $locked = FollowUp::query()->lockForUpdate()->find($followUpId);

            if ($locked === null || ! $locked->status->canTransitionTo(FollowUpStatus::Skipped)) {
                return false;
            }

            $this->skipLocked($locked, $reason);

            return true;
        });
    }

    /**
     * Apply a skip to a follow-up the caller has already locked.
     */
    public function skipLocked(FollowUp $locked, FollowUpSkipReason $reason): void
    {
        $locked->forceFill([
            'status' => FollowUpStatus::Skipped,
            'skip_reason' => $reason,
            'outcome' => 'Follow-up skipped: '.$reason->label(),
            'processed_at' => now(),
        ]);
        $this->record($locked, $this->event('skipped', null, $reason->label()));
        $locked->save();

        Log::info('Follow-up skipped.', ['organization_id' => $locked->organization_id, 'follow_up_id' => $locked->id, 'reason' => $reason->value]);
    }

    /**
     * A customer reply makes the conversation's open automated follow-ups unnecessary.
     * Manual reminders stay: the person decides. Completed or cancelled ones are never touched.
     *
     * @return int How many were skipped.
     */
    public function skipForCustomerReply(int $organizationId, int $conversationId, DateTimeInterface $repliedAt): int
    {
        $ids = FollowUp::query()
            ->forOrganization($organizationId)
            ->where('conversation_id', $conversationId)
            ->where('type', FollowUpType::Automated)
            ->open()
            ->where('created_at', '<', $repliedAt)
            ->pluck('id');

        return $ids->filter(fn (int $id) => $this->skip($id, FollowUpSkipReason::CustomerReplied))->count();
    }

    /**
     * The estimate was accepted, declined or cancelled: its open automated follow-ups are no
     * longer needed. Manual reminders stay.
     *
     * @return int How many were skipped.
     */
    public function skipForEstimate(int $organizationId, int $estimateId): int
    {
        $ids = FollowUp::query()
            ->forOrganization($organizationId)
            ->where('estimate_id', $estimateId)
            ->where('type', FollowUpType::Automated)
            ->open()
            ->pluck('id');

        return $ids->filter(fn (int $id) => $this->skip($id, FollowUpSkipReason::EstimateClosed))->count();
    }

    /**
     * Counts for the dashboard, using the organization's "today".
     *
     * @return array{overdue: int, due_today: int, upcoming: int}
     */
    public function counts(Organization $organization): array
    {
        [$start, $end] = $this->today($organization);
        $open = fn () => FollowUp::query()->forOrganization($organization)->open();

        return [
            'overdue' => $open()->where('due_at', '<', $start)->count(),
            'due_today' => $open()->whereBetween('due_at', [$start, $end])->count(),
            'upcoming' => $open()->where('due_at', '>', $end)->count(),
        ];
    }

    /**
     * Start and end of today in the organization's timezone, as UTC instants.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function today(Organization $organization): array
    {
        $now = $organization->localNow();

        return [$now->startOfDay()->utc(), $now->endOfDay()->utc()];
    }

    /**
     * Turn a date and time typed in the organization's timezone into a UTC instant.
     *
     * @throws InvalidFollowUpException
     */
    public function parseLocal(Organization $organization, string $date, string $time): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new InvalidFollowUpException('Choose a valid date and time.');
        }

        $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $organization->timezone());

        if ($local === false || $local->format('Y-m-d H:i') !== "{$date} {$time}") {
            throw new InvalidFollowUpException('Choose a valid date and time.');
        }

        return $local->utc();
    }

    /**
     * Append an entry to the follow-up's history (caller saves).
     *
     * @param  array<string, mixed>  $entry
     */
    public function record(FollowUp $followUp, array $entry): void
    {
        $metadata = $followUp->metadata ?? [];
        $metadata['history'] = array_slice([...($metadata['history'] ?? []), $entry], -50);
        $followUp->metadata = $metadata;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function event(string $event, ?User $actor = null, ?string $detail = null, array $extra = []): array
    {
        return array_filter(['event' => $event, 'at' => now()->toIso8601String(), 'by' => $actor?->name, 'detail' => $detail] + $extra, fn ($value) => $value !== null);
    }

    /**
     * @param  Closure(FollowUp): ?string  $apply  Sets the new fields; returns a history detail.
     */
    private function change(FollowUp $followUp, User $actor, FollowUpStatus $to, string $verb, Closure $apply): FollowUp
    {
        return $this->locked($followUp, $actor, function (FollowUp $locked) use ($actor, $to, $verb, $apply) {
            if (! $locked->status->canTransitionTo($to)) {
                throw InvalidFollowUpException::transition(strtolower($locked->status->label()), $verb);
            }

            $detail = $apply($locked);
            $locked->status = $to;
            $this->record($locked, $this->event($verb, $actor, $detail));
            $locked->save();

            Log::info("Follow-up {$verb}.", ['organization_id' => $locked->organization_id, 'follow_up_id' => $locked->id, 'user_id' => $actor->id]);

            return $locked;
        });
    }

    /**
     * Run a change on the locked row, after checking the actor's organization.
     *
     * @param  Closure(FollowUp): FollowUp  $callback
     */
    private function locked(FollowUp $followUp, User $actor, Closure $callback): FollowUp
    {
        if ($actor->organization_id === null || $actor->organization_id !== $followUp->organization_id) {
            throw new InvalidFollowUpException('Follow-up not found.');
        }

        $result = DB::transaction(fn () => $callback(FollowUp::query()->lockForUpdate()->findOrFail($followUp->id)));

        $followUp->setRawAttributes($result->getAttributes(), sync: true);

        return $followUp;
    }

    private function validDueAt(DateTimeInterface $dueAt): CarbonImmutable
    {
        $dueAt = CarbonImmutable::instance($dueAt)->utc();

        if ($dueAt->lessThanOrEqualTo(now())) {
            throw new InvalidFollowUpException('Choose a time in the future.');
        }

        if ($dueAt->greaterThan(now()->addDays((int) config('follow_ups.max_days_ahead')))) {
            throw new InvalidFollowUpException('Choose a date within the next year.');
        }

        return $dueAt;
    }

    private function notes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        if (mb_strlen($notes) > self::MAX_NOTES) {
            throw new InvalidFollowUpException('Notes may be up to '.self::MAX_NOTES.' characters.');
        }

        return $notes === '' ? null : $notes;
    }
}
