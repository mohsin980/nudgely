<?php

namespace App\Models;

use App\Enums\Automation\AutomationActionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One THEN step of an automation, chosen from the controlled AutomationActionType list.
 */
#[Fillable(['type', 'configuration', 'sort_order', 'requires_approval'])]
class AutomationAction extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AutomationActionType::class,
            'configuration' => 'array',
            'sort_order' => 'integer',
            'requires_approval' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Conservative default: actions that contact customers require approval unless set explicitly.
        static::creating(function (AutomationAction $action) {
            if (! array_key_exists('requires_approval', $action->getAttributes())) {
                $action->requires_approval = $action->type?->requiresApprovalByDefault() ?? false;
            }

            if (! array_key_exists('configuration', $action->getAttributes())) {
                $action->configuration = [];
            }
        });
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
