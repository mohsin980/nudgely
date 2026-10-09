<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * An organization-scoped label for customers, unique per organization by slug.
 */
class CustomerTag extends Model
{
    protected $guarded = ['*'];

    /**
     * Normalize a tag ("Ready to book!" → "ready-to-book"); null when nothing usable remains.
     */
    public static function slugFor(string $name): ?string
    {
        $slug = Str::limit(Str::slug($name), 50, '');

        return $slug === '' ? null : $slug;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Customer, $this>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class)->withPivot('created_at');
    }
}
