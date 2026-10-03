<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A business's customer.
 *
 * `name` (the full name used across emails, automations and AI context) is kept in sync with
 * first_name/last_name in both directions, so older code that only knows `name` keeps working.
 * Emails are stored lowercased and trimmed; (organization_id, email) is unique.
 */
#[Fillable(['name', 'first_name', 'last_name', 'email', 'phone', 'company', 'notes', 'status'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_opted_out_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'status' => CustomerStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if ($customer->isDirty(['first_name', 'last_name']) && filled($customer->first_name)) {
                $customer->name = trim($customer->first_name.' '.$customer->last_name);
            } elseif ($customer->isDirty('name') || blank($customer->first_name)) {
                $name = trim(preg_replace('/\s+/', ' ', (string) $customer->name));
                $customer->first_name = Str::before($name, ' ') ?: null;
                $customer->last_name = Str::contains($name, ' ') ? Str::after($name, ' ') : null;
            }

            if ($customer->isDirty('phone')) {
                $digits = preg_replace('/\D+/', '', (string) $customer->phone);
                $customer->phone_digits = $digits === '' ? null : mb_substr($digits, 0, 20);
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status !== CustomerStatus::Inactive;
    }

    public function hasOptedOutOfEmail(): bool
    {
        return $this->email_opted_out_at !== null;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return BelongsToMany<CustomerTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class)->withPivot('created_at');
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Normalize an email for storage and duplicate checks: trimmed and lowercased.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeEmail($value));
    }

    /**
     * @return HasMany<Estimate, $this>
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }
}
