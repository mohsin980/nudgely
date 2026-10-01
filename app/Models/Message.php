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

/**
 * A single message exchanged with a customer. Only outbound email exists so far.
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
     * @return BelongsTo<EmailConnection, $this>
     */
    public function emailConnection(): BelongsTo
    {
        return $this->belongsTo(EmailConnection::class);
    }
}
