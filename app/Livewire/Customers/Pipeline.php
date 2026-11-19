<?php

namespace App\Livewire\Customers;

use App\Enums\LeadStage;
use App\Models\Customer;
use App\Services\Leads\LeadStageService;
use App\Services\Leads\PipelineReport;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The sales pipeline: one column per lead stage, a report on top, and a tag filter.
 * Anyone who can see the customer list can see the pipeline.
 */
#[Layout('components.layouts.app')]
#[Title('Pipeline')]
class Pipeline extends Component
{
    #[Url(except: '')]
    public string $tag = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    /**
     * Move a lead to another stage from its card. Another business's lead is a 404.
     */
    public function moveLead(int $customerId, string $stage, LeadStageService $stages): void
    {
        $customer = Auth::user()->organization->customers()->whereKey($customerId)->firstOrFail();

        $this->authorize('update', $customer);

        $stages->set(Auth::user(), $customer, LeadStage::tryFrom($stage) ?? abort(422));
    }

    public function render(PipelineReport $report)
    {
        $organization = Auth::user()->organization;

        return view('livewire.customers.pipeline', [
            'board' => $report->board($organization, $this->tag),
            'summary' => $report->summary($organization),
            'tags' => $organization->customerTags()->orderBy('name')->get(['id', 'name', 'slug']),
            'currency' => $organization->currencyCode(),
            'stages' => LeadStage::cases(),
        ]);
    }
}
