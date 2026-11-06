<?php

use App\Livewire\Automations\AutomationIndex;
use Livewire\Livewire;

beforeEach(function () {
    $this->team = teamBusiness(); // owner John Smith, manager Sarah Wilson, staff Mike Johnson
});

test('staff are told why they cannot add automations, and who to ask', function () {
    Livewire::actingAs($this->team['staff'])->test(AutomationIndex::class)
        ->assertSeeHtml('data-view-only-note')
        ->assertSee('You can view automations, but not add or change them.')
        ->assertSee('Ask John Smith')
        ->assertDontSee('Create Automation')
        ->assertDontSee('Browse Templates');
});

test('owners and managers do not see the note', function (string $role) {
    Livewire::actingAs($this->team[$role])->test(AutomationIndex::class)
        ->assertDontSeeHtml('data-view-only-note')
        ->assertSee('Create Automation');
})->with(['owner', 'manager']);
