<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrganizationRole;
use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use App\Enums\Team\MemberStatus;
use App\Enums\Team\Permission;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * organization_id, role and status are intentionally not mass assignable: they only change
 * through TeamService (invitations, role changes, suspension, removal, ownership transfer).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

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
    /**
     * @return HasOne<PlatformAdmin, $this>
     */
    public function platformAdmin(): HasOne
    {
        return $this->hasOne(PlatformAdmin::class);
    }

    public function isPlatformAdmin(): bool
    {
        // Loaded once per user instance: this is called from gates, which run for every row of a list.
        $this->loadMissing('platformAdmin');

        return $this->platformAdmin !== null;
    }

    /**
     * An account that is active and recorded as a platform administrator. Roles alone never make this true.
     */
    public function isActivePlatformAdmin(): bool
    {
        return $this->status === MemberStatus::Active && $this->isPlatformAdmin();
    }

    /**
     * A platform permission needs all three: an active account, a platform_admins record, and a role that holds the
     * permission. A role on its own (for example one attached to a customer by mistake) grants nothing.
     * Business roles (OrganizationRole) are checked with hasPermission(), which is a separate system.
     */
    public function hasPlatformPermission(PlatformPermission $permission): bool
    {
        return $this->isActivePlatformAdmin()
            && $this->checkPermissionTo($permission->value, PlatformRole::GUARD);
    }

    /**
     * The Super Admin panel is for platform administrators who hold the access_admin_panel permission. Business
     * roles (owner, manager, staff) never grant it, and a suspended or removed account is refused.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->hasPlatformPermission(PlatformPermission::AccessAdminPanel);
    }

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
