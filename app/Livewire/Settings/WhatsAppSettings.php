<?php

namespace App\Livewire\Settings;

use App\Livewire\Settings\Concerns\SettingsPage;
use App\Services\Settings\BusinessSettingsService;
use App\Services\WhatsApp\WhatsAppConnectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Settings → WhatsApp: how the business follows up (email, WhatsApp or both) and its own WhatsApp number.
 */
#[Layout('components.layouts.app')]
#[Title('WhatsApp')]
class WhatsAppSettings extends Component
{
    use SettingsPage;

    public string $channels = 'email';

    public string $primary = 'email';

    public string $phoneNumberId = '';

    public string $businessAccountId = '';

    public string $accessToken = '';

    public string $appSecret = '';

    public string $testTo = '';

    public string $followUpTemplate = '';

    public string $templateLanguage = 'en';

    public function mount(): void
    {
        $settings = $this->organization()->businessSettings();
        $chosen = array_map(fn ($channel) => $channel->value, $settings->followUpChannels());
        $this->channels = count($chosen) > 1 ? 'both' : $chosen[0];
        $this->primary = $settings->primaryChannel()->value;

        if ($connection = $this->organization()->whatsappConnection()->first()) {
            $this->phoneNumberId = $connection->phone_number_id;
            $this->businessAccountId = $connection->business_account_id;
            $this->followUpTemplate = (string) $connection->follow_up_template;
            $this->templateLanguage = $connection->follow_up_template_language ?: 'en';
        }
    }

    public function saveChannels(BusinessSettingsService $settings): void
    {
        try {
            $settings->updateFollowUpChannels($this->user(), ['channels' => $this->channels, 'primary' => $this->primary]);
            $this->saved('Follow-up channels saved.');
        } catch (ValidationException $e) {
            $this->showErrors($e);
        } catch (AuthorizationException $e) {
            $this->failed($e->getMessage());
        }
    }

    public function connect(WhatsAppConnectionService $whatsapp): void
    {
        try {
            $connection = $whatsapp->connect($this->user(), [
                'phone_number_id' => trim($this->phoneNumberId),
                'business_account_id' => trim($this->businessAccountId),
                'access_token' => trim($this->accessToken),
                'app_secret' => trim($this->appSecret),
            ]);
        } catch (ValidationException $e) {
            $this->showErrors($e, ['phone_number_id' => 'phoneNumberId', 'business_account_id' => 'businessAccountId', 'access_token' => 'accessToken', 'app_secret' => 'appSecret']);

            return;
        } catch (AuthorizationException $e) {
            $this->failed($e->getMessage());

            return;
        }

        $this->reset('accessToken', 'appSecret'); // never kept in the page after saving
        $connection->isActive()
            ? $this->saved('WhatsApp is connected: '.($connection->display_phone_number ?? $connection->phone_number_id).'. Now add the webhook in Meta (step 4).')
            : $this->failed('Saved, but Meta did not accept these details: '.$connection->last_error);
    }

    /**
     * Called by the "Continue with Facebook" button once Meta's popup finishes (see resources/js/app.js).
     */
    public function completeEmbeddedSignup(string $code, string $phoneNumberId, string $businessAccountId): void
    {
        $key = 'whatsapp-embedded-signup:'.$this->organization()->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->failed('Too many attempts. Please wait a minute and try again.');

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $connection = app(WhatsAppConnectionService::class)->completeEmbeddedSignup($this->user(), $code, $phoneNumberId, $businessAccountId);
        } catch (ValidationException $e) {
            $this->failed(collect($e->errors())->flatten()->first() ?? 'Meta did not share the WhatsApp details. Try again.');

            return;
        } catch (AuthorizationException|RuntimeException $e) {
            $this->failed($e->getMessage());

            return;
        }

        $this->phoneNumberId = $connection->phone_number_id;
        $this->businessAccountId = $connection->business_account_id;

        $connection->isActive()
            ? $this->saved('WhatsApp is connected: '.($connection->display_phone_number ?? $connection->phone_number_id).'. Customer replies will arrive in Conversations.')
            : $this->failed('Connected, but Meta reported a problem with the number: '.$connection->last_error);
    }

    public function recheck(WhatsAppConnectionService $whatsapp): void
    {
        $connection = $this->organization()->whatsappConnection()->first() ?? abort(404);
        abort_unless($this->user()->can('manage-email'), 403);

        $whatsapp->check($connection)->isActive()
            ? $this->saved('WhatsApp is connected.')
            : $this->failed('Meta did not accept these details: '.$connection->last_error);
    }

    public function sendTest(WhatsAppConnectionService $whatsapp): void
    {
        $key = 'whatsapp-test:'.$this->organization()->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->failed('Please wait a minute before sending another test.');

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $whatsapp->sendTest($this->user(), $this->testTo);
            $this->saved('Test message sent. It should arrive on WhatsApp in a few seconds.');
        } catch (ValidationException $e) {
            $this->showErrors($e, ['test_to' => 'testTo']);
        } catch (AuthorizationException|RuntimeException $e) {
            $this->failed($e->getMessage());
        }
    }

    public function saveTemplate(WhatsAppConnectionService $whatsapp): void
    {
        try {
            $whatsapp->saveFollowUpTemplate($this->user(), ['template' => trim($this->followUpTemplate), 'language' => trim($this->templateLanguage)]);
            $this->saved('Follow-up template saved.');
        } catch (ValidationException $e) {
            $this->showErrors($e, ['template' => 'followUpTemplate', 'language' => 'templateLanguage']);
        } catch (AuthorizationException|RuntimeException $e) {
            $this->failed($e->getMessage());
        }
    }

    public function disconnect(WhatsAppConnectionService $whatsapp): void
    {
        try {
            $whatsapp->disconnect($this->user());
            $this->reset('phoneNumberId', 'businessAccountId', 'accessToken', 'appSecret');
            $this->saved('WhatsApp disconnected. Follow-ups go by email until you connect it again.');
        } catch (AuthorizationException $e) {
            $this->failed($e->getMessage());
        }
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.settings.whatsapp-settings', [
            'organization' => $organization,
            'connection' => $organization->whatsappConnection()->first(),
            'webhookUrl' => route('webhooks.whatsapp'),
            'embeddedProblem' => WhatsAppConnectionService::embeddedSignupProblem(),
            'embeddedSignup' => WhatsAppConnectionService::embeddedSignupEnabled() ? [
                'appId' => (string) config('services.whatsapp.embedded.app_id'),
                'configId' => (string) config('services.whatsapp.embedded.config_id'),
                'version' => (string) config('services.whatsapp.graph_version'),
            ] : null,
            'canManage' => $this->user()->can('manage-email'),
        ]);
    }
}
