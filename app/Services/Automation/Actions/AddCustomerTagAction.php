<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Models\AutomationAction;
use App\Models\CustomerTag;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use Illuminate\Support\Facades\DB;

/**
 * add_customer_tag: labels the event's customer, e.g. "ready-to-book".
 *
 * Configuration: tag (normalized to a slug, max 50 characters). Tags are unique per
 * organization and attaching an existing tag is a no-op, so retries are safe.
 */
class AddCustomerTagAction implements AutomationActionInterface
{
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $name = $action->configuration['tag'] ?? null;
        $slug = is_string($name) ? CustomerTag::slugFor($name) : null;

        if ($slug === null) {
            return AutomationActionResult::failed('A valid tag is required.');
        }

        $customer = $context->customer();

        if ($customer === null) {
            return AutomationActionResult::failed($context->customerId === null
                ? 'This event has no customer to tag.'
                : 'The customer does not belong to this organization.');
        }

        // Concurrency-safe: unique (organization_id, slug) and the pivot's primary key decide.
        DB::table('customer_tags')->insertOrIgnore([
            'organization_id' => $context->organizationId,
            'name' => $slug,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tagId = CustomerTag::query()->where('organization_id', $context->organizationId)->where('slug', $slug)->value('id');

        $attached = DB::table('customer_customer_tag')->insertOrIgnore([
            'customer_id' => $customer->id,
            'customer_tag_id' => $tagId,
            'created_at' => now(),
        ]);

        return $attached === 0
            ? AutomationActionResult::skipped("Customer already tagged \"{$slug}\".", ['reason' => 'already_exists', 'tag' => $slug, 'tag_id' => $tagId])
            : AutomationActionResult::completed("Customer tagged \"{$slug}\".", ['tag' => $slug, 'tag_id' => $tagId]);
    }
}
