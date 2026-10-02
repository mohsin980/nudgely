<?php

namespace App\Models;

use App\Enums\ClassificationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\ReplySentiment;
use App\Enums\ReplyUrgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI classification attempt for an inbound message. Rows are never updated or deleted.
 */
class MessageClassification extends Model
{
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClassificationStatus::class,
            'intent' => CustomerReplyIntent::class,
            'confidence' => 'float',
            'sentiment' => ReplySentiment::class,
            'urgency' => ReplyUrgency::class,
            'requires_human_review' => 'boolean',
            'classified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @param  Builder<MessageClassification>  $query
     */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', ClassificationStatus::Succeeded);
    }
}
