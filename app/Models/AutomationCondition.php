<?php

namespace App\Models;

use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\Automation\AutomationConditionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One IF clause of an automation. All conditions of an automation are combined with AND.
 */
#[Fillable(['type', 'operator', 'value', 'sort_order'])]
class AutomationCondition extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AutomationConditionType::class,
            'operator' => AutomationConditionOperator::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
