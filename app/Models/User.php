<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Enums\Team\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * organization_id, role and status are intentionally not mass assignable: they only change
 * through TeamService (invitations, role changes, suspension, removal, ownership transfer).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => OrganizationRole::class,
            'status' => MemberStatus::class,
            'suspended_at' => 'datetime',
            'removed_at' => 'datetime',
            'last_active_at' => 'datetime',
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
     * Belongs to an organization and may act in it (not suspended or removed).
     */
    public function isActiveMember(): bool
    {
        return $this->organization_id !== null && $this->status === MemberStatus::Active;
    }

    public function isOwner(): bool
    {
        return $this->isActiveMember() && $this->role === OrganizationRole::Owner;
    }

    /**
     * The single place role permissions are checked (also registered as Gates of the same name).
     */
    public function hasPermission(Permission $permission): bool
    {
        return $this->isActiveMember() && $this->role instanceof OrganizationRole && $this->role->can($permission);
    }

    /**
     * Active members of an organization: the only people work can be assigned to or who are notified.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActiveIn(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId)->where('status', MemberStatus::Active);
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0] ?: $this->name;
    }
}
