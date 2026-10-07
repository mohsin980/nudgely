<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audited change to the business or its team (same shape as AutomationHistory):
 * who did it, to whom (team actions), what changed ({field: {from, to}}) and when.
 */
class OrganizationActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'organization_activity';

    protected $guarded = ['*'];

    /** Human labels for actions. */
    public const LABELS = [
        'business_created' => 'Business account created',
        'business_profile_updated' => 'Business profile changed',
        'logo_updated' => 'Logo uploaded',
        'logo_removed' => 'Logo removed',
        'preferences_updated' => 'Business preferences changed',
        'business_hours_updated' => 'Business hours changed',
        'estimate_defaults_updated' => 'Estimate defaults changed',
        'follow_up_defaults_updated' => 'Follow-up defaults changed',
        'automation_defaults_updated' => 'Automation defaults changed',
        'notification_defaults_updated' => 'Notification defaults changed',
        'member_invited' => 'Team member invited',
        'invitation_resent' => 'Invitation resent',
        'invitation_revoked' => 'Invitation revoked',
        'invitation_accepted' => 'Invitation accepted',
        'role_changed' => 'Role changed',
        'member_suspended' => 'Member suspended',
        'member_reactivated' => 'Member reactivated',
        'member_removed' => 'Member removed',
        'ownership_transferred' => 'Ownership transferred',
        'password_changed' => 'Password changed',
        'subscription_started' => 'Subscription started',
        'subscription_plan_changed' => 'Plan changed',
        'subscription_cancelled' => 'Subscription cancelled',
        'subscription_resumed' => 'Subscription resumed',
        'other_sessions_logged_out' => 'Signed out other sessions',
    ];

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
     * @param  array<string, mixed>  $data  For settings: ['changes' => [field => ['from' => …, 'to' => …]]].
     */
    public static function record(Organization|int $organization, string $action, ?User $actor, array $data = [], ?User $subject = null): self
    {
        $entry = new self;
        $entry->forceFill([
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
            'user_id' => $actor?->id,
            'subject_user_id' => $subject?->id,
            'action' => $action,
            'data' => $data ?: null,
        ])->save();

        return $entry;
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }

    /**
     * @param  Builder<OrganizationActivity>  $query
     * @param  list<string>  $actions
     * @return Builder<OrganizationActivity>
     */
    public function scopeOfActions(Builder $query, array $actions): Builder
    {
        return $query->whereIn('action', $actions);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
