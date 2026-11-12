<?php

use App\Livewire\Settings\BillingPlans;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->owner = teamBusiness()['owner'];
});

test('the plans page offers "Talk to support" for custom needs, linked to the support email', function () {
    config(['app.support_email' => 'support@quoteflow.test']);

    Livewire::actingAs($this->owner)->test(BillingPlans::class)
        ->assertSee('Need something custom?')
        ->assertSee('Talk to support')
        ->assertSee('mailto:support@quoteflow.test?subject=Custom%20solution%20enquiry', false);
});

test('without a support email, the custom solutions card still explains who to contact, with no broken link', function () {
    config(['app.support_email' => null]);

    Livewire::actingAs($this->owner)->test(BillingPlans::class)
        ->assertSee('Need something custom?')
        ->assertSee('Contact QuoteFlow support to discuss a custom solution.')
        ->assertDontSee('mailto:', false);
});
