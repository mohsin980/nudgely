<?php

namespace App\Livewire\Automations;

use App\Models\Automation;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant lookups for the automation screens: the organization always comes from the
 * signed-in user, and another organization's automation is a 404.
 */
trait ResolvesAutomations
{
    protected function currentOrganization(): Organization
    {
        return Auth::user()->organization ?? abort(403);
    }

    protected function findAutomation(int $automationId): Automation
    {
        return Automation::query()
            ->forOrganization($this->currentOrganization())
            ->whereKey($automationId)
            ->first() ?? abort(404);
    }
}
