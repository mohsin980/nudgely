<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Models\AutomationAction;
use App\Models\CustomerTag;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use Illuminate\Support\Facades\DB;

/**
 * remove_customer_tag: removes a tag from the event's customer. Removing a tag the customer
 * doesn't have is a no-op (skipped), so retries are harmless.
 */
class RemoveCustomerTagAction implements AutomationActionInterface
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
            return AutomationActionResult::failed('This event has no customer in this organization.');
        }

        $tagId = CustomerTag::query()->where('organization_id', $context->organizationId)->where('slug', $slug)->value('id');
        $removed = $tagId === null ? 0 : DB::table('customer_customer_tag')->where('customer_id', $customer->id)->where('customer_tag_id', $tagId)->delete();

        return $removed === 0
            ? AutomationActionResult::skipped("Customer didn't have the tag \"{$slug}\".", ['reason' => 'not_tagged', 'tag' => $slug])
            : AutomationActionResult::completed("Tag \"{$slug}\" removed.", ['tag' => $slug]);
    }
}
