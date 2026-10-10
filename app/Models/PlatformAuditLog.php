<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who did what on the platform side (suspensions and similar). Never shown to customers, so it may hold the
 * internal reason. Append-only: rows are written by record() and never edited.
 */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** Human labels for actions. */
    public const LABELS = [
        'organization_suspended' => 'Organization suspended',
        'organization_reactivated' => 'Organization reactivated',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function record(string $action, ?User $actor, ?int $organizationId = null, ?string $reason = null): self
    {
        $entry = new self;
        $entry->forceFill(['action' => $action, 'actor_id' => $actor?->id, 'organization_id' => $organizationId, 'reason' => $reason])->save();

        return $entry;
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
