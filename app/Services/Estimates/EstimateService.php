<?php

namespace App\Services\Estimates;

use App\Enums\Billing\LimitKey;
use App\Enums\ConversationStatus;
use App\Enums\DiscountType;
use App\Enums\EstimateDeclineReason;
use App\Enums\EstimateStatus;
use App\Enums\MessageStatus;
use App\Events\EstimateAccepted;
use App\Events\EstimateCreated;
use App\Events\EstimateDeclined;
use App\Events\EstimateExpired;
use App\Events\EstimateSent;
use App\Events\EstimateViewed;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\Estimates\EstimateException;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Everything that changes an estimate: drafts, sending, revisions, the customer's view,
 * acceptance, decline, cancellation and expiry.
 *
 * - The organization always comes from the acting user (or, for the customer, from the
 *   estimate the secure link resolves to); customer and conversation IDs are re-checked.
 * - Amounts are recalculated by EstimateCalculator on every save and before sending.
 * - Status changes lock the estimate row, so a customer accepting while the business acts
 *   can't produce an impossible state (the database also enforces this with CHECK constraints).
 * - Events are dispatched after commit, once per change.
 */
class EstimateService
{
    public const TOKEN_LENGTH = 48;

    public function __construct(
        private readonly EstimateCalculator $calculator,
        private readonly EstimateEmailComposer $composer,
        private readonly EmailService $email,
        private readonly FollowUpService $followUps,
    ) {}

    // Drafts

    /**
     * @param  array<string, mixed>  $input  customer_id, conversation_id, title, notes, valid_until, discount_type, discount_value, tax_rate, items[{description, quantity, unit_price}]
     *
     * @throws EstimateException
     */
    public function create(User $actor, array $input): Estimate
    {
        $organization = $this->organizationOf($actor);
        $data = $this->validated($organization, $input);

        try {
            app(EntitlementService::class)->assertAllows($organization, LimitKey::Estimates);
        } catch (PlanLimitException $e) {
            throw new EstimateException($e->getMessage());
        }

        $estimate = DB::transaction(function () use ($actor, $organization, $data) {
            $estimate = new Estimate;
            $estimate->forceFill([
                'organization_id' => $organization->id,
                'estimate_number' => $this->nextNumber($organization),
                'revision' => 1,
                'status' => EstimateStatus::Draft,
                'currency' => $organization->currencyCode(),
                'created_by' => $actor->id,
            ]);
            $this->fill($estimate, $data);
            $estimate->save();
            $this->saveItems($estimate, $data['lines'], $data['totals']);

            ConversationEvent::recordForEstimate($estimate, 'estimate_created', $actor, ['total' => $estimate->money('total')]);

            return $estimate;
        });

        EstimateCreated::dispatch($organization->id, $estimate->id, $estimate->customer_id, $estimate->conversation_id);
        Log::info('Estimate created.', ['organization_id' => $organization->id, 'estimate_id' => $estimate->id, 'user_id' => $actor->id]);

        return $estimate;
    }

    /**
     * Only drafts can be edited; a sent estimate is revised instead.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws EstimateException
     */
    public function update(User $actor, Estimate $estimate, array $input): Estimate
    {
        $organization = $this->organizationOf($actor, $estimate);
        $data = $this->validated($organization, $input);

        return DB::transaction(function () use ($estimate, $data) {
            $locked = $this->lock($estimate);

            if (! $locked->isDraft()) {
                throw new EstimateException('This estimate was already sent, so it can\'t be changed. Create a revision instead.');
            }

            if ($this->isSending($locked)) {
                throw new EstimateException('This estimate is being sent. Wait a moment and try again.');
            }

            $this->fill($locked, $data);
            $locked->save();
            $this->saveItems($locked, $data['lines'], $data['totals']);

            return $locked;
        });
    }

    /**
     * Totals for the form while the user types. Invalid rows are left out; nothing is saved.
     *
     * @param  array<string, mixed>  $input
     */
    public function preview(array $input): EstimateTotals
    {
        $lines = [];

        foreach ((array) ($input['items'] ?? []) as $item) {
            $quantity = Money::tryParse($item['quantity'] ?? null);
            $price = Money::tryParse($item['unit_price'] ?? null);

            if ($quantity && $quantity <= EstimateCalculator::MAX_QUANTITY && $price !== null && $price <= EstimateCalculator::MAX_UNIT_PRICE) {
                $lines[] = ['quantity' => $quantity, 'unit_price' => $price];
            }
        }

        try {
            return $this->calculator->calculate($lines, ...$this->rates($input));
        } catch (InvalidArgumentException) {
            try {
                return $this->calculator->calculate($lines);
            } catch (InvalidArgumentException) {
                return new EstimateTotals([], 0, 0, 0, 0);
            }
        }
    }

    // Sending

    /**
     * Queue the estimate email through EmailService. The estimate becomes "sent" only when the
     * email is delivered (deliveryUpdated); a failed email leaves it a draft that can be re-sent.
     *
     * @throws EstimateException
     */
    public function send(User $actor, Estimate $estimate): Message
    {
        $organization = $this->organizationOf($actor, $estimate);

        try {
            return DB::transaction(function () use ($actor, $organization, $estimate) {
                $locked = $this->lock($estimate);

                if (! $locked->isDraft()) {
                    throw new EstimateException($locked->status === EstimateStatus::Cancelled ? 'This estimate was cancelled.' : 'This estimate was already sent.');
                }

                if ($this->isSending($locked)) {
                    throw new EstimateException('This estimate is already being sent.');
                }

                $items = $locked->items()->get();

                if ($items->isEmpty()) {
                    throw EstimateException::invalid(['items' => 'Add at least one item before sending this estimate.']);
                }

                // Recalculate from the stored items: what is sent is always consistent.
                $totals = $this->calculator->calculate(
                    $items->map(fn ($item) => ['quantity' => Money::parse($item->getAttributes()['quantity']), 'unit_price' => Money::parse($item->getAttributes()['unit_price'])])->all(),
                    $locked->discount_type,
                    $locked->discount_value === null ? null : Money::parse($locked->getAttributes()['discount_value']),
                    $locked->tax_rate === null ? null : Money::parse($locked->getAttributes()['tax_rate'], 3),
                );

                if ($totals->total <= 0) {
                    throw EstimateException::invalid(['items' => 'The estimate total must be more than '.Money::format(0, $locked->currency).'.']);
                }

                if ($locked->isPastValidUntil($organization)) {
                    throw EstimateException::invalid(['valid_until' => 'The "valid until" date has passed. Choose a new date before sending.']);
                }

                $customer = $organization->customers()->find($locked->customer_id) ?? throw new EstimateException('Customer not found.');

                if (! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                    throw new EstimateException('This customer has no valid email address.');
                }

                if ($customer->hasOptedOutOfEmail()) {
                    throw new EstimateException('Unable to send estimate: this customer has opted out of email.');
                }

                if ($this->versions($locked)->contains(fn (Estimate $v) => $v->status === EstimateStatus::Accepted)) {
                    throw new EstimateException('The customer already accepted another version of this estimate.');
                }

                $conversation = $this->conversationFor($locked, $customer, $organization);
                $token = $locked->public_token ?: Str::random(self::TOKEN_LENGTH);

                $locked->forceFill([
                    'conversation_id' => $conversation->id,
                    'public_token' => $token,
                    'public_token_hash' => hash('sha256', $token),
                ] + $totals->toColumns())->save();

                [$subject, $html, $text] = $this->composer->compose($locked, $customer, $organization);

                $message = $this->email->sendToConversation($conversation, $subject, $html, $text, [
                    'type' => 'estimate',
                    'estimate_id' => (string) $locked->id,
                    'sent_by' => (string) $actor->id,
                ]);

                $locked->forceFill(['send_message_id' => $message->id])->save();
                $estimate->setRawAttributes($locked->getAttributes(), sync: true);

                Log::info('Estimate email queued.', ['organization_id' => $organization->id, 'estimate_id' => $locked->id, 'message_id' => $message->id, 'user_id' => $actor->id]);

                return $message;
            });
        } catch (EmailSendingNotAllowedException $e) {
            // Safe, user-facing reasons only (e.g. "verify your domain"); never provider details.
            throw new EstimateException('Unable to send estimate. '.$e->getMessage());
        } catch (InvalidEmailTemplateException|InvalidArgumentException $e) {
            Log::warning('Estimate could not be sent.', ['organization_id' => $organization->id, 'estimate_id' => $estimate->id]);

            throw new EstimateException('Unable to send estimate. Please try again.');
        }
    }

    /**
     * Called when an estimate email's status changes: delivered → the estimate is sent;
     * failed → it stays a draft and the failure is recorded.
     */
    public function deliveryUpdated(Message $message): void
    {
        $estimateId = (int) ($message->metadata['estimate_id'] ?? 0);

        if ($estimateId === 0 || ! in_array($message->status, [MessageStatus::Sent, MessageStatus::Failed], true)) {
            return;
        }

        $sent = DB::transaction(function () use ($message, $estimateId) {
            $locked = Estimate::query()
                ->where('organization_id', $message->organization_id)
                ->where('send_message_id', $message->id)
                ->lockForUpdate()
                ->find($estimateId);

            if ($locked === null || ! $locked->isDraft()) {
                return null;
            }

            if ($message->status === MessageStatus::Failed) {
                ConversationEvent::recordForEstimate($locked, 'estimate_send_failed');
                Log::warning('Estimate email failed.', ['organization_id' => $locked->organization_id, 'estimate_id' => $locked->id, 'message_id' => $message->id]);

                return null;
            }

            $locked->forceFill(['status' => EstimateStatus::Sent, 'sent_at' => $message->sent_at ?? now()])->save();
            ConversationEvent::recordForEstimate($locked, 'estimate_sent', null, [
                'to' => $message->to_address,
                'from' => $message->from_address,
                'total' => $locked->money('total'),
            ]);

            // A revision replaces the versions the customer had: their links stop working.
            foreach ($this->versions($locked)->filter(fn (Estimate $v) => $v->id !== $locked->id && $v->status->isAwaitingCustomer()) as $previous) {
                $this->cancelLocked($this->lock($previous), null, 'replaced', $locked->displayNumber());
            }

            return $locked;
        });

        if ($sent !== null) {
            EstimateSent::dispatch($sent->organization_id, $sent->id, $sent->customer_id, $sent->conversation_id);
            Log::info('Estimate sent.', ['organization_id' => $sent->organization_id, 'estimate_id' => $sent->id]);
        }
    }

    /**
     * The send that failed last, if the estimate is still a draft because of it.
     */
    public function failedSend(Estimate $estimate): ?Message
    {
        $message = $estimate->isDraft() && $estimate->send_message_id ? $estimate->sendMessage : null;

        return $message?->status === MessageStatus::Failed ? $message : null;
    }

    public function isSending(Estimate $estimate): bool
    {
        return $estimate->send_message_id !== null
            && in_array(Message::query()->whereKey($estimate->send_message_id)->toBase()->value('status'), [MessageStatus::Queued->value, MessageStatus::Sending->value], true);
    }

    // Revisions and cancellation

    /**
     * Copy a sent estimate into a new draft version (same number, next revision). The sent
     * version stays exactly as the customer received it, and stays valid until the revision is sent.
     *
     * @throws EstimateException
     */
    public function revise(User $actor, Estimate $estimate): Estimate
    {
        $organization = $this->organizationOf($actor, $estimate);

        [$revision, $created] = DB::transaction(function () use ($actor, $organization, $estimate) {
            $locked = $this->lock($estimate);
            $versions = $this->versions($locked);

            if (($draft = $versions->first(fn (Estimate $v) => $v->isDraft())) !== null) {
                return [$draft, false];
            }

            if (! $locked->status->canBeRevised()) {
                throw new EstimateException($locked->status === EstimateStatus::Accepted
                    ? 'The customer accepted this estimate, so it can\'t be revised.'
                    : 'Only a sent estimate can be revised.');
            }

            if ($versions->contains(fn (Estimate $v) => $v->status === EstimateStatus::Accepted)) {
                throw new EstimateException('The customer already accepted another version of this estimate.');
            }

            $revision = $locked->replicate(['status', 'public_token', 'public_token_hash', 'send_message_id', 'sent_at', 'viewed_at', 'accepted_at',
                'declined_at', 'decline_reason', 'decline_note', 'expired_at', 'cancelled_at', 'created_by', 'revision', 'revision_of_id']);
            $revision->forceFill([
                'status' => EstimateStatus::Draft,
                'revision' => $versions->max('revision') + 1,
                'revision_of_id' => $locked->rootId(),
                'created_by' => $actor->id,
            ]);

            if ($revision->isPastValidUntil($organization)) {
                $revision->valid_until = $organization->localNow()->addDays($organization->businessSettings()->estimateValidDays())->toDateString();
            }

            $revision->save();

            $now = now();
            $revision->items()->insert($locked->items()->get()->map(fn ($item) => [
                'estimate_id' => $revision->id,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $item->only(['description', 'quantity', 'unit_price', 'amount', 'sort_order']))->all());

            ConversationEvent::recordForEstimate($revision, 'estimate_revised', $actor, ['from' => $locked->displayNumber()]);

            return [$revision, true];
        });

        if ($created) {
            EstimateCreated::dispatch($organization->id, $revision->id, $revision->customer_id, $revision->conversation_id);
        }

        return $revision;
    }

    /**
     * @throws EstimateException
     */
    public function cancel(User $actor, Estimate $estimate): Estimate
    {
        $this->organizationOf($actor, $estimate);

        return DB::transaction(function () use ($actor, $estimate) {
            $locked = $this->lock($estimate);

            if (in_array($locked->status, [EstimateStatus::Accepted, EstimateStatus::Declined, EstimateStatus::Cancelled], true)) {
                throw new EstimateException("This estimate is {$locked->status->label()} and can't be cancelled.");
            }

            if ($this->isSending($locked)) {
                throw new EstimateException('This estimate is being sent. Wait a moment and try again.');
            }

            return $this->cancelLocked($locked, $actor);
        });
    }

    // The customer

    /**
     * The estimate a customer link points to, or null. Drafts and revoked links are never found.
     */
    public function findByToken(string $token): ?Estimate
    {
        if (! preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token)) {
            return null;
        }

        $estimate = Estimate::query()->where('public_token_hash', hash('sha256', $token))->first();

        return $estimate === null || $estimate->isDraft() ? null : $estimate;
    }

    /**
     * The customer opened the link. The first view of a sent estimate marks it viewed (once).
     */
    public function recordView(Estimate $estimate): Estimate
    {
        $this->expireIfPastValidUntil($estimate);

        $viewed = DB::transaction(function () use ($estimate) {
            $locked = $this->lock($estimate);

            if ($locked->status !== EstimateStatus::Sent) {
                return null;
            }

            $locked->forceFill(['status' => EstimateStatus::Viewed, 'viewed_at' => now()])->save();
            ConversationEvent::recordForEstimate($locked, 'estimate_viewed');

            return $locked;
        });

        if ($viewed !== null) {
            EstimateViewed::dispatch($viewed->organization_id, $viewed->id, $viewed->customer_id, $viewed->conversation_id);
        }

        return $estimate->refresh();
    }

    /**
     * Accept on the customer's behalf (from their secure link). Accepting twice changes nothing
     * and dispatches nothing the second time.
     *
     * @throws EstimateException
     */
    public function accept(Estimate $estimate): Estimate
    {
        return $this->decide($estimate, EstimateStatus::Accepted);
    }

    /**
     * @throws EstimateException
     */
    public function decline(Estimate $estimate, ?EstimateDeclineReason $reason = null, ?string $note = null): Estimate
    {
        $note = $note === null ? null : Str::limit(trim(strip_tags($note)), 500, '');

        return $this->decide($estimate, EstimateStatus::Declined, $reason, $note === '' ? null : $note);
    }

    // Expiry

    /**
     * Mark sent/viewed estimates past their "valid until" date (in each organization's timezone)
     * as expired. Accepted, declined and cancelled estimates are never touched.
     *
     * @return int How many expired.
     */
    public function expireDue(): int
    {
        // The application's clock, not the database's, so every "today" in the app agrees.
        $now = now()->utc()->toIso8601String();

        $rows = DB::select(<<<'SQL'
            update estimates e
            set status = 'expired', expired_at = ?::timestamptz, updated_at = ?::timestamptz
            from organizations o
            where o.id = e.organization_id
              and e.status in ('sent', 'viewed')
              and e.valid_until < (?::timestamptz at time zone coalesce(o.timezone, ?))::date
            returning e.id
        SQL, [$now, $now, $now, config('follow_ups.default_timezone')]);

        $this->afterExpiry(collect($rows)->pluck('id'));

        return count($rows);
    }

    public function expireIfPastValidUntil(Estimate $estimate): bool
    {
        if (! $estimate->status->isAwaitingCustomer() || ! $estimate->isPastValidUntil($estimate->organization)) {
            return false;
        }

        $expired = Estimate::query()->whereKey($estimate->id)->whereIn('status', ['sent', 'viewed'])
            ->update(['status' => EstimateStatus::Expired, 'expired_at' => now(), 'updated_at' => now()]);

        if ($expired === 1) {
            $this->afterExpiry(collect([$estimate->id]));
        }

        $estimate->refresh();

        return $expired === 1;
    }

    // Internals

    /**
     * @param  Collection<int, int>  $ids
     */
    private function afterExpiry(Collection $ids): void
    {
        foreach (Estimate::query()->whereIn('id', $ids)->get() as $estimate) {
            ConversationEvent::recordForEstimate($estimate, 'estimate_expired');
            EstimateExpired::dispatch($estimate->organization_id, $estimate->id, $estimate->customer_id, $estimate->conversation_id);
            Log::info('Estimate expired.', ['organization_id' => $estimate->organization_id, 'estimate_id' => $estimate->id]);
        }
    }

    /**
     * @throws EstimateException
     */
    private function decide(Estimate $estimate, EstimateStatus $decision, ?EstimateDeclineReason $reason = null, ?string $note = null): Estimate
    {
        // Expiry is committed on its own, so the customer's error below doesn't undo it.
        $this->expireIfPastValidUntil($estimate);

        $decided = DB::transaction(function () use ($estimate, $decision, $reason, $note) {
            $locked = $this->lock($estimate);

            if ($locked->status === $decision) {
                return null; // Already done: no second event.
            }

            if (! $locked->status->isAwaitingCustomer()) {
                throw new EstimateException(match ($locked->status) {
                    EstimateStatus::Accepted => 'This estimate was already accepted.',
                    EstimateStatus::Declined => 'This estimate was already declined.',
                    EstimateStatus::Expired => 'This estimate has expired. Please contact us for an updated estimate.',
                    default => 'This estimate is no longer available.',
                });
            }

            $now = now();
            $locked->forceFill($decision === EstimateStatus::Accepted
                ? ['status' => EstimateStatus::Accepted, 'accepted_at' => $now, 'viewed_at' => $locked->viewed_at ?? $now]
                : ['status' => EstimateStatus::Declined, 'declined_at' => $now, 'viewed_at' => $locked->viewed_at ?? $now, 'decline_reason' => $reason, 'decline_note' => $note]
            )->save();

            ConversationEvent::recordForEstimate($locked, 'estimate_'.$decision->value, null, array_filter([
                'total' => $locked->money('total'),
                'reason' => $reason?->label(),
                'note' => $note,
            ]));

            return $locked;
        });

        if ($decided !== null) {
            // The decision is made: automated follow-ups about this estimate are no longer needed.
            $this->followUps->skipForEstimate($decided->organization_id, $decided->id);

            $decision === EstimateStatus::Accepted
                ? EstimateAccepted::dispatch($decided->organization_id, $decided->id, $decided->customer_id, $decided->conversation_id)
                : EstimateDeclined::dispatch($decided->organization_id, $decided->id, $decided->customer_id, $decided->conversation_id);

            Log::info('Estimate '.$decision->value.'.', ['organization_id' => $decided->organization_id, 'estimate_id' => $decided->id]);
        }

        return $estimate->refresh();
    }

    private function cancelLocked(Estimate $locked, ?User $actor, string $why = 'cancelled', ?string $replacedBy = null): Estimate
    {
        $locked->forceFill([
            'status' => EstimateStatus::Cancelled,
            'cancelled_at' => now(),
            // Revoke the customer's link.
            'public_token' => null,
            'public_token_hash' => null,
        ])->save();

        ConversationEvent::recordForEstimate($locked, $why === 'replaced' ? 'estimate_replaced' : 'estimate_cancelled', $actor, array_filter(['replaced_by' => $replacedBy]));
        DB::afterCommit(fn () => $this->followUps->skipForEstimate($locked->organization_id, $locked->id));

        return $locked;
    }

    private function lock(Estimate $estimate): Estimate
    {
        return Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Every version of this estimate (the original and its revisions).
     *
     * @return Collection<int, Estimate>
     */
    private function versions(Estimate $estimate): Collection
    {
        $root = $estimate->rootId();

        return Estimate::query()
            ->where('organization_id', $estimate->organization_id)
            ->where(fn ($q) => $q->whereKey($root)->orWhere('revision_of_id', $root))
            ->get();
    }

    /**
     * The estimate's own conversation, or a new one: the email's Reply-To routes the customer's
     * answer back into it (Task 5), where it is classified (Task 6).
     */
    private function conversationFor(Estimate $estimate, Customer $customer, Organization $organization): Conversation
    {
        $conversation = $estimate->conversation_id === null ? null
            : $organization->conversations()->where('customer_id', $customer->id)->find($estimate->conversation_id);

        if ($conversation !== null) {
            return $conversation;
        }

        $conversation = new Conversation;
        $conversation->forceFill([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => Str::limit("Estimate {$estimate->displayNumber()}: {$estimate->title}", 200, ''),
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ])->save();

        return $conversation;
    }

    /**
     * EST-1001, EST-1002, … per organization. The counter row is updated atomically, and the
     * unique index on (organization_id, estimate_number, revision) is the final guard.
     */
    private function nextNumber(Organization $organization): string
    {
        $next = DB::selectOne(
            'update organizations set estimate_sequence = greatest(estimate_sequence + 1, ?) where id = ? returning estimate_sequence',
            [(int) config('estimates.first_number'), $organization->id],
        )->estimate_sequence;

        return config('estimates.number_prefix').$next;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Estimate $estimate, array $data): void
    {
        $estimate->forceFill([
            'customer_id' => $data['customer']->id,
            'conversation_id' => $data['conversation']?->id,
            'title' => $data['title'],
            'notes' => $data['notes'],
            'valid_until' => $data['valid_until'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'] === null ? null : Money::toDecimal($data['discount_value']),
            'tax_rate' => $data['tax_rate'] === null ? null : Money::toDecimal($data['tax_rate'], 3),
        ] + $data['totals']->toColumns());
    }

    /**
     * @param  list<array{description: string, quantity: int, unit_price: int}>  $lines
     */
    private function saveItems(Estimate $estimate, array $lines, EstimateTotals $totals): void
    {
        $estimate->items()->delete();
        $now = now();

        $estimate->items()->insert(array_map(fn (array $line, int $i) => [
            'estimate_id' => $estimate->id,
            'description' => $line['description'],
            'quantity' => Money::toDecimal($line['quantity']),
            'unit_price' => Money::toDecimal($line['unit_price']),
            'amount' => Money::toDecimal($totals->lineAmounts[$i]),
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ], $lines, array_keys($lines)));
    }

    /**
     * Validate and normalize form input. Totals are always calculated here, never taken from input.
     *
     * @param  array<string, mixed>  $input
     * @return array{customer: Customer, conversation: ?Conversation, title: string, notes: ?string, valid_until: ?string, discount_type: ?DiscountType, discount_value: ?int, tax_rate: ?int, lines: list<array{description: string, quantity: int, unit_price: int}>, totals: EstimateTotals}
     *
     * @throws EstimateException
     */
    private function validated(Organization $organization, array $input): array
    {
        $errors = [];

        $customer = filled($input['customer_id'] ?? null) && ctype_digit((string) $input['customer_id'])
            ? $organization->customers()->find((int) $input['customer_id'])
            : null;

        if ($customer === null) {
            $errors['customer_id'] = 'Choose a customer.';
        }

        $conversation = null;

        if (filled($input['conversation_id'] ?? null)) {
            $conversation = ctype_digit((string) $input['conversation_id']) ? $organization->conversations()->find((int) $input['conversation_id']) : null;

            if ($conversation === null || ($customer !== null && $conversation->customer_id !== $customer->id)) {
                $errors['conversation_id'] = 'That conversation does not belong to this customer.';
            }
        }

        $title = trim(preg_replace('/\s+/', ' ', (string) ($input['title'] ?? '')));

        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Enter a title of up to 200 characters, e.g. "AC Installation".';
        }

        $notes = trim(str_replace("\r\n", "\n", (string) ($input['notes'] ?? '')));

        if (mb_strlen($notes) > 5000) {
            $errors['notes'] = 'Notes can be up to 5,000 characters.';
        }

        $validUntil = null;

        if (filled($input['valid_until'] ?? null)) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['valid_until']);
                $validUntil = $date !== false && $date->format('Y-m-d') === $input['valid_until'] ? $date->toDateString() : null;
            } catch (\Throwable) {
                $validUntil = null;
            }

            if ($validUntil === null) {
                $errors['valid_until'] = 'Enter a valid date.';
            } elseif ($validUntil < $organization->localNow()->toDateString()) {
                $errors['valid_until'] = '"Valid until" can\'t be in the past.';
            }
        }

        $lines = [];
        $items = array_values(array_filter((array) ($input['items'] ?? []), 'is_array'));

        foreach ($items as $i => $item) {
            $description = trim(preg_replace('/\s+/', ' ', (string) ($item['description'] ?? '')));
            $quantity = trim((string) ($item['quantity'] ?? ''));
            $price = trim((string) ($item['unit_price'] ?? ''));

            // A completely empty row is ignored.
            if ($description === '' && ($quantity === '' || $quantity === '1') && $price === '') {
                continue;
            }

            if ($description === '' || mb_strlen($description) > 500) {
                $errors["items.{$i}.description"] = 'Enter a description (up to 500 characters).';
            }

            $q = Money::tryParse($quantity);

            if ($q === null || $q <= 0 || $q > EstimateCalculator::MAX_QUANTITY) {
                $errors["items.{$i}.quantity"] = 'Enter a quantity more than 0 (up to 2 decimals).';
            }

            $p = Money::tryParse($price);

            if ($p === null || $p > EstimateCalculator::MAX_UNIT_PRICE) {
                $errors["items.{$i}.unit_price"] = 'Enter a price of 0 or more, e.g. 2500 or 2500.00.';
            }

            $lines[] = ['description' => $description, 'quantity' => $q ?? 0, 'unit_price' => $p ?? 0];
        }

        if (count($lines) > (int) config('estimates.max_items')) {
            $errors['items'] = 'An estimate can have up to '.config('estimates.max_items').' items.';
        }

        $discountType = DiscountType::tryFrom((string) ($input['discount_type'] ?? ''));
        $discountValue = null;

        if ($discountType !== null && filled($input['discount_value'] ?? null)) {
            $discountValue = Money::tryParse($input['discount_value']);

            if ($discountValue === null) {
                $errors['discount_value'] = 'Enter a discount of 0 or more, e.g. 10 or 250.00.';
            } elseif ($discountType === DiscountType::Percent && $discountValue > 10_000) {
                $errors['discount_value'] = 'A percentage discount can be at most 100%.';
            }
        }

        $taxRate = null;

        if (filled($input['tax_rate'] ?? null)) {
            $taxRate = Money::tryParse($input['tax_rate'], 3);

            if ($taxRate === null || $taxRate > EstimateCalculator::MAX_TAX_RATE) {
                $errors['tax_rate'] = 'Enter a tax rate from 0 to 100, e.g. 8.25.';
            }
        }

        if ($errors !== []) {
            throw EstimateException::invalid($errors);
        }

        try {
            $totals = $this->calculator->calculate(
                array_map(fn (array $l) => ['quantity' => $l['quantity'], 'unit_price' => $l['unit_price']], $lines),
                $discountType,
                $discountValue,
                $taxRate,
            );
        } catch (InvalidArgumentException $e) {
            throw EstimateException::invalid([str_contains($e->getMessage(), 'discount') ? 'discount_value' : 'items' => $e->getMessage()]);
        }

        return [
            'customer' => $customer,
            'conversation' => $conversation,
            'title' => $title,
            'notes' => $notes === '' ? null : $notes,
            'valid_until' => $validUntil,
            'discount_type' => $discountValue ? $discountType : null,
            'discount_value' => $discountValue ?: null,
            'tax_rate' => $taxRate ?: null,
            'lines' => $lines,
            'totals' => $totals,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?DiscountType, 1: ?int, 2: ?int}
     */
    private function rates(array $input): array
    {
        $type = DiscountType::tryFrom((string) ($input['discount_type'] ?? ''));
        $discount = $type === null ? null : Money::tryParse($input['discount_value'] ?? null);
        $tax = Money::tryParse($input['tax_rate'] ?? null, 3);

        return [$discount ? $type : null, $discount, $tax];
    }

    /**
     * @throws EstimateException
     */
    private function organizationOf(User $actor, ?Estimate $estimate = null): Organization
    {
        $organization = $actor->organization;

        if ($organization === null || ($estimate !== null && $estimate->organization_id !== $organization->id)) {
            throw EstimateException::notFound();
        }

        return $organization;
    }
}
