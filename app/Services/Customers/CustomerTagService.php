<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerTag;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes a customer's labels. Tags are shared within a business and never across businesses.
 */
class CustomerTagService
{
    public const MAX_PER_CUSTOMER = 10;

    /**
     * Tag a customer, creating the tag for the business if it doesn't exist yet. Adding a tag twice is harmless.
     */
    public function add(Customer $customer, string $name): CustomerTag
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $slug = CustomerTag::slugFor($name);

        if ($slug === null) {
            throw ValidationException::withMessages(['tag' => 'Use letters or numbers for a tag.']);
        }

        // CustomerTag is fully guarded, so the row is written explicitly rather than mass-assigned.
        $tag = CustomerTag::query()->where('organization_id', $customer->organization_id)->where('slug', $slug)->first()
            ?? CustomerTag::query()->forceCreate([
                'organization_id' => $customer->organization_id,
                'name' => mb_substr($name, 0, 50),
                'slug' => $slug,
            ]);

        if (! $customer->tags()->whereKey($tag->id)->exists()) {
            if ($customer->tags()->count() >= self::MAX_PER_CUSTOMER) {
                throw ValidationException::withMessages(['tag' => 'A customer can have up to '.self::MAX_PER_CUSTOMER.' tags.']);
            }

            $customer->tags()->attach($tag->id);
        }

        return $tag;
    }

    /**
     * Remove a tag from a customer. Only tags already attached are removed; tags are attached within one business.
     */
    public function remove(Customer $customer, int $tagId): void
    {
        if ($customer->tags()->whereKey($tagId)->exists()) {
            $customer->tags()->detach($tagId);
        }
    }
}
