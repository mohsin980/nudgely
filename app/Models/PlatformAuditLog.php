<?php

namespace App\Models;

use App\Support\Audit\AuditRedactor;
use App\Support\CorrelationId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * The platform's audit trail: who (actor, or null for the system / a server command) did what (a structured action)
 * to which target, with what outcome, and when. Never shown to customers, so it may hold the internal reason.
 *
 * Append-only: record() is the only way in and it always redacts, and a saved row cannot be updated or deleted through
 * the model (retention removes expired rows with PlatformAuditPruner, which is itself audited). `changes` holds the
 * sanitized details of the event (old and new values, counts, identifiers), never secrets or message content.
 */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const SUCCESS = 'success';

    public const DENIED = 'denied';

    public const FAILED = 'failed';

    protected $guarded = ['*'];

    /** Human labels for actions. Event names are structured: <target>_<past-tense verb>. */
    public const LABELS = [
        'organization_suspended' => 'Organization suspended',
        'organization_reactivated' => 'Organization reactivated',
        'user_deactivated' => 'User deactivated',
        'user_reactivated' => 'User reactivated',
        'subscription_refreshed' => 'Subscription refreshed from provider',
        'plan_updated' => 'Plan updated',
        'plan_reset' => 'Plan reset to configured defaults',
        'platform_admin_granted' => 'Platform access granted',
        'platform_admin_revoked' => 'Platform access revoked',
        'admin_session_started' => 'Admin panel session started',
        'audit_log_pruned' => 'Expired audit events removed',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit events cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'changes' => 'array'];
    }

    /**
     * Write one audit event. Everything free-form is redacted here, whoever calls it.
     *
     * The target defaults to the user (if a subject user is given) or the organization (if one is given).
     *
     * @param  array<string, mixed>|null  $changes  details of the event; sanitized before saving
     * @param  string|null  $targetType  organization, user, plan, subscription, ...
     */
    public static function record(
        string $action,
        ?User $actor,
        ?int $organizationId = null,
        ?string $reason = null,
        ?int $subjectUserId = null,
        ?array $changes = null,
        ?string $targetType = null,
        string|int|null $targetId = null,
        string $outcome = self::SUCCESS,
    ): self {
        if ($targetType === null) {
            [$targetType, $targetId] = match (true) {
                $subjectUserId !== null => ['user', $subjectUserId],
                $organizationId !== null => ['organization', $organizationId],
                default => [null, null],
            };
        }

        $entry = new self;
        $entry->forceFill([
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : mb_substr((string) $targetId, 0, 64),
            'outcome' => $outcome,
            'actor_id' => $actor?->id,
            'organization_id' => $organizationId,
            'subject_user_id' => $subjectUserId,
            'reason' => AuditRedactor::text($reason),
            'changes' => $changes === null ? null : AuditRedactor::sanitize($changes),
            'correlation_id' => Context::get(CorrelationId::ATTRIBUTE),
        ])->save();

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

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
