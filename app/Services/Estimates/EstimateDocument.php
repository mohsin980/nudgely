<?php

namespace App\Services\Estimates;

use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * What the estimate document shows: the business, the customer, the items and totals.
 * One shape for the email, the business preview and the customer's page (and a future PDF).
 */
final readonly class EstimateDocument
{
    /**
     * @param  Collection<int, EstimateItem>  $items
     */
    public function __construct(
        public Estimate $estimate,
        public Organization $organization,
        public ?Customer $customer,
        public Collection $items,
        public ?string $businessEmail,
    ) {}

    public static function for(Estimate $estimate, Organization $organization): self
    {
        return new self(
            $estimate,
            $organization,
            $organization->customers()->find($estimate->customer_id),
            $estimate->items()->get(),
            $organization->emailConnections()->default()->value('sender_email'),
        );
    }

    /**
     * The date shown on the document: when it was sent, or today for a draft.
     */
    public function date(): string
    {
        return $this->organization->localTime($this->estimate->sent_at ?? now())->format('F j, Y');
    }
}
