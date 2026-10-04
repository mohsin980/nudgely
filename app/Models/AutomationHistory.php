<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change someone made to an automation: created, edited, activated, paused, archived,
 * restored, duplicated.
 */
class AutomationHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'automation_history';

    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(Automation $automation, string $action, ?User $user, array $data = []): self
    {
        $entry = new self;
        $entry->forceFill([
            'organization_id' => $automation->organization_id,
            'automation_id' => $automation->id,
            'user_id' => $user?->id,
            'action' => $action,
            'data' => $data ?: null,
        ])->save();

        return $entry;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
