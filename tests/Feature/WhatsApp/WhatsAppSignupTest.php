<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WhatsAppPayloads;
use Tests\TestCase;

class WhatsAppSignupTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppPayloads;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->configureWhatsApp();
        $this->owner = User::factory()->owner()->create();
    }

    /**
     * @return array<string, string>
     */
    private function signupResult(): array
    {
        return ['code' => 'AQB-signup-code', 'waba_id' => '555000111', 'phone_number_id' => self::WA_PHONE_NUMBER_ID];
    }

    public function test_a_completed_signup_connects_the_number_and_stores_its_token_encrypted(): void
    {
        $this->fakeMetaGraph();

        $this->actingAs($this->owner)
            ->post(route('settings.whatsapp.connect'), $this->signupResult())
            ->assertRedirect(route('settings.whatsapp'))
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/access_token')
            && $request['code'] === 'AQB-signup-code'
            && $request['client_id'] === '1234567890'
            && $request['client_secret'] === self::WA_APP_SECRET);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/555000111/subscribed_apps')
            && $request->hasHeader('Authorization', 'Bearer EAAG-new-token'));

        $connection = WhatsAppConnection::query()->where('organization_id', $this->owner->organization_id)->sole();
        $this->assertSame(self::WA_PHONE_NUMBER_ID, $connection->phone_number_id);
        $this->assertSame(self::WA_DISPLAY_NUMBER, $connection->display_phone_number);
        $this->assertSame('EAAG-new-token', $connection->access_token);
        $this->assertNotSame('EAAG-new-token', \DB::table('whatsapp_connections')->value('access_token'), 'The token is encrypted at rest.');
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
    }

    public function test_a_failed_token_exchange_connects_nothing_and_says_so(): void
    {
        Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 100]], 400)]);

        $this->actingAs($this->owner)
            ->post(route('settings.whatsapp.connect'), $this->signupResult())
            ->assertSessionHas('error', 'WhatsApp could not be connected. Try again, or contact support if it keeps failing.');

        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_a_number_already_connected_to_another_business_is_refused(): void
    {
        $other = User::factory()->owner()->create();
        $this->connectWhatsApp($other->organization_id);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('settings.whatsapp.connect'), $this->signupResult())
            ->assertSessionHas('error', 'This WhatsApp number is already connected to another account.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_connections', 1);
    }

    public function test_the_connect_endpoint_is_closed_until_signup_is_configured(): void
    {
        config(['whatsapp.signup_config_id' => null]);

        $this->actingAs($this->owner)
            ->post(route('settings.whatsapp.connect'), $this->signupResult())
            ->assertNotFound();
    }

    public function test_the_signup_input_must_be_numeric_ids(): void
    {
        $this->actingAs($this->owner)
            ->post(route('settings.whatsapp.connect'), ['code' => 'x', 'waba_id' => 'abc', 'phone_number_id' => '1 OR 1=1'])
            ->assertSessionHasErrors(['waba_id', 'phone_number_id']);
    }

    public function test_the_settings_card_offers_connect_only_when_configured(): void
    {
        $this->actingAs($this->owner)->get(route('settings.whatsapp'))
            ->assertOk()
            ->assertSee('Connect WhatsApp')
            ->assertSee('connect.facebook.net/en_US/sdk.js', escape: false)
            ->assertSee('987654321');

        config(['whatsapp.app_secret' => null]);

        $this->actingAs($this->owner)->get(route('settings.whatsapp'))
            ->assertOk()
            ->assertDontSee('Connect WhatsApp')
            ->assertSee('not available yet');
    }

    public function test_the_settings_card_never_shows_the_access_token(): void
    {
        $this->connectWhatsApp($this->owner->organization_id);

        $this->actingAs($this->owner)->get(route('settings.whatsapp'))
            ->assertOk()
            ->assertSee(self::WA_DISPLAY_NUMBER)
            ->assertDontSee('EAAG-test-access-token')
            ->assertDontSee('Connect WhatsApp');
    }
}
