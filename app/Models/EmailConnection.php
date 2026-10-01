<?php

namespace App\Models;

use App\Enums\EmailProvider;
use App\Enums\EmailVerificationStatus;
use Database\Factories\EmailConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * organization_id, verification_status, verified_at and provider_domain_id are
 * intentionally not mass assignable: tenancy is set through the organization
 * relationship and verification state is owned by the system.
 */
#[Fillable(['provider', 'domain', 'sender_email', 'sender_name', 'is_default'])]
class EmailConnection extends Model
{
    /** @use HasFactory<EmailConnectionFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'verification_status' => 'pending',
        'is_default' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => EmailProvider::class,
            'verification_status' => EmailVerificationStatus::class,
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep a single default connection per organization, whichever way the flag is set.
        static::saving(function (EmailConnection $connection) {
            if (! $connection->is_default || ! $connection->isDirty(['is_default', 'organization_id'])) {
                return;
            }

            static::query()
                ->where('organization_id', $connection->organization_id)
                ->where('is_default', true)
                ->when($connection->exists, fn (Builder $query) => $query->whereKeyNot($connection->getKey()))
                ->update(['is_default' => false]);
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Make this the organization's default connection, unsetting any previous default atomically.
     */
    public function markAsDefault(): static
    {
        DB::transaction(fn () => $this->forceFill(['is_default' => true])->save());

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === EmailVerificationStatus::Verified;
    }

    /**
     * @param  Builder<EmailConnection>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->where('verification_status', EmailVerificationStatus::Verified);
    }

    /**
     * @param  Builder<EmailConnection>  $query
     */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    protected function domain(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : strtolower(trim($value)));
    }

    protected function senderEmail(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : strtolower(trim($value)));
    }
}
