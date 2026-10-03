<?php

namespace App\Livewire;

use App\Services\FollowUps\FollowUpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(FollowUpService $followUps)
    {
        $organization = Auth::user()->organization ?? abort(403);

        return view('livewire.dashboard', ['followUps' => $followUps->counts($organization)]);
    }
}
