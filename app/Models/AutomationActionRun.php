<?php

namespace App\Models;

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The outcome of one action within an automation run (audit record).
 */
class AutomationActionRun extends Model
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
            'action_type' => AutomationActionType::class,
            'status' => AutomationActionRunStatus::class,
            'result' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    /**
     * @return BelongsTo<AutomationAction, $this>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(AutomationAction::class, 'automation_action_id');
    }
}
