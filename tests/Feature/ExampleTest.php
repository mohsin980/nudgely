<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page_from_the_home_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_signed_in_users_are_sent_to_the_dashboard_from_the_home_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
    }
}
