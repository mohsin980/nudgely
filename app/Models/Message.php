<?php

namespace App\Models;

use App\Enums\EmailProvider;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single email exchanged with a customer, outbound or inbound.
 *
 * Nothing is mass assignable: messages are created by EmailService, which sets
 * tenancy and delivery state explicitly.
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'channel' => MessageChannel::class,
            'provider' => EmailProvider::class,
            'status' => MessageStatus::class,
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<EmailReplyRoute, $this>
     */
    public function replyRoute(): BelongsTo
    {
        return $this->belongsTo(EmailReplyRoute::class, 'email_reply_route_id');
    }

    /**
     * Every classification attempt, newest first.
     *
     * @return HasMany<MessageClassification, $this>
     */
    public function classifications(): HasMany
    {
        return $this->hasMany(MessageClassification::class)->latest('id');
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    /**
     * When the message happened: received for inbound, sent (or created) for outbound.
     */
    public function occurredAt(): Carbon
    {
        return $this->received_at ?? $this->sent_at ?? $this->created_at;
    }

    /**
     * @return BelongsTo<EmailConnection, $this>
     */
    public function emailConnection(): BelongsTo
    {
        return $this->belongsTo(EmailConnection::class);
    }
}
