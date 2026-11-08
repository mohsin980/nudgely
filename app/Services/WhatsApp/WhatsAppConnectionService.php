<?php

namespace App\Services\WhatsApp;

use App\Enums\Team\Permission;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Connects a business's own WhatsApp number (Meta WhatsApp Cloud API) and checks it works.
 *
 * The business creates its WhatsApp Business account in Meta and pastes four values here. QuoteFlow
 * checks them with Meta straight away, stores the secrets encrypted, and gives the business a webhook
 * address and verify token to paste back into Meta so customers' replies reach QuoteFlow.
 * Only people who may manage the business's email (the owner) can change it.
 */
class WhatsAppConnectionService
{
    public function __construct(private readonly WhatsAppClient $client) {}

    /**
     * Save (or replace) the connection, then check it with Meta.
     *
     * @param  array<string, mixed>  $input  phone_number_id, business_account_id, access_token, app_secret
     *
     * @throws AuthorizationException|ValidationException
     */
    public function connect(User $actor, array $input): WhatsAppConnection
    {
        $organization = $this->organizationOf($actor);
        $existing = $organization->whatsappConnection()->first();
        $keepSecrets = $existing !== null && ! $existing->isEmbeddedSignup();

        $data = Validator::make($input, [
            'phone_number_id' => ['required', 'regex:/^\d{6,30}$/', Rule::unique('whatsapp_connections', 'phone_number_id')->ignore($existing?->id)],
            'business_account_id' => ['required', 'regex:/^\d{6,30}$/'],
            // Secrets can be left empty to keep the ones already saved (not when switching from one-click connect).
            'access_token' => [$keepSecrets ? 'nullable' : 'required', 'string', 'min:20', 'max:1000', 'regex:/^\S+$/'],
            'app_secret' => [$keepSecrets ? 'nullable' : 'required', 'string', 'min:16', 'max:200', 'regex:/^\S+$/'],
        ], [
            'phone_number_id.regex' => 'The phone number ID is a long number, found in WhatsApp → API Setup.',
            'business_account_id.regex' => 'The WhatsApp Business account ID is a long number, found in WhatsApp → API Setup.',
            'phone_number_id.unique' => 'This WhatsApp number is already connected to another business.',
            'access_token.regex' => 'Paste the access token without spaces.',
            'app_secret.regex' => 'Paste the app secret without spaces.',
        ], ['phone_number_id' => 'phone number ID', 'business_account_id' => 'WhatsApp Business account ID'])->validate();

        $connection = $existing ?? new WhatsAppConnection;
        $connection->forceFill([
            'organization_id' => $organization->id,
            'phone_number_id' => $data['phone_number_id'],
            'business_account_id' => $data['business_account_id'],
            'connected_via' => WhatsAppConnection::VIA_MANUAL,
            'webhook_verify_token' => $connection->webhook_verify_token ?: Str::random(40),
            'status' => WhatsAppConnection::STATUS_PENDING,
            'last_error' => null,
        ]);

        if (filled($data['access_token'] ?? null)) {
            $connection->access_token = $data['access_token'];
        }

        if (filled($data['app_secret'] ?? null)) {
            $connection->app_secret = $data['app_secret'];
        }

        $connection->save();
        OrganizationActivity::record($organization, 'whatsapp_connected', $actor, ['phone_number_id' => $connection->phone_number_id]);

        return $this->check($connection);
    }

    /**
     * Whether one-click connect (Meta Embedded Signup through QuoteFlow's Meta app) is set up on this server.
     */
    public static function embeddedSignupEnabled(): bool
    {
        $config = (array) config('services.whatsapp.embedded');

        return filled($config['app_id'] ?? null) && filled($config['app_secret'] ?? null) && filled($config['config_id'] ?? null);
    }

    /**
     * Finish one-click connect. The browser hands over Meta's one-time sign-in code and the IDs Meta's popup
     * reported. Nothing from the browser is trusted on its own: the code is exchanged for a token by the server,
     * and the number must belong to the WhatsApp Business account that token was granted for.
     *
     * Then QuoteFlow's app is subscribed to the account (so replies arrive) and the number is registered for the
     * Cloud API with a PIN QuoteFlow keeps.
     *
     * @throws AuthorizationException|ValidationException|RuntimeException
     */
    public function completeEmbeddedSignup(User $actor, string $code, string $phoneNumberId, string $businessAccountId): WhatsAppConnection
    {
        $organization = $this->organizationOf($actor);

        if (! self::embeddedSignupEnabled()) {
            throw new RuntimeException('One-click WhatsApp connect isn\'t set up on this server. Connect manually instead.');
        }

        $existing = $organization->whatsappConnection()->first();
        $data = Validator::make(['code' => $code, 'phone_number_id' => $phoneNumberId, 'business_account_id' => $businessAccountId], [
            'code' => ['required', 'string', 'max:2000', 'regex:/^\S+$/'],
            'phone_number_id' => ['required', 'regex:/^\d{6,30}$/', Rule::unique('whatsapp_connections', 'phone_number_id')->ignore($existing?->id)],
            'business_account_id' => ['required', 'regex:/^\d{6,30}$/'],
        ], [
            'phone_number_id.unique' => 'This WhatsApp number is already connected to another business.',
            'phone_number_id.regex' => 'Meta did not share a WhatsApp number. Finish every step in the Facebook window, including adding a number.',
            'business_account_id.regex' => 'Meta did not share a WhatsApp Business account. Try again.',
        ])->validate();

        $token = $this->exchangeCode($data['code']);
        $this->assertNumberInAccount($token, $data['phone_number_id'], $data['business_account_id']);
        $this->request(fn () => $this->client->graphWithToken($token)->post($data['business_account_id'].'/subscribed_apps'), 'Meta did not let QuoteFlow receive messages for this account');

        $pin = $existing?->registration_pin ?: (string) random_int(100000, 999999);
        $this->register($token, $data['phone_number_id'], $pin, $organization->id);

        $connection = $existing ?? new WhatsAppConnection;
        $connection->forceFill([
            'organization_id' => $organization->id,
            'phone_number_id' => $data['phone_number_id'],
            'business_account_id' => $data['business_account_id'],
            'connected_via' => WhatsAppConnection::VIA_EMBEDDED_SIGNUP,
            'access_token' => $token,
            'app_secret' => null,
            'registration_pin' => $pin,
            'webhook_verify_token' => $connection->webhook_verify_token ?: Str::random(40),
            'status' => WhatsAppConnection::STATUS_PENDING,
            'last_error' => null,
        ])->save();

        OrganizationActivity::record($organization, 'whatsapp_connected', $actor, ['phone_number_id' => $connection->phone_number_id, 'via' => 'embedded_signup']);

        return $this->check($connection);
    }

    /**
     * Ask Meta for the number's details: proves the ID and token are right and the token can use the number.
     */
    public function check(WhatsAppConnection $connection): WhatsAppConnection
    {
        try {
            $response = $this->client->graph($connection)->get($connection->phone_number_id, ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException) {
            return $this->failed($connection, 'Meta could not be reached. Try again in a minute.');
        }

        if (! $response->successful()) {
            return $this->failed($connection, WhatsAppClient::metaError($response));
        }

        $connection->forceFill([
            'status' => WhatsAppConnection::STATUS_ACTIVE,
            'display_phone_number' => mb_substr((string) $response->json('display_phone_number'), 0, 32) ?: null,
            'verified_name' => mb_substr((string) $response->json('verified_name'), 0, 191) ?: null,
            'verified_at' => now(),
            'last_error' => null,
        ])->save();

        Log::info('WhatsApp number connected.', ['organization_id' => $connection->organization_id, 'whatsapp_connection_id' => $connection->id]);

        return $connection;
    }

    /**
     * Send Meta's ready-made "hello_world" template to a number, to show the connection works end to end.
     * (Every new WhatsApp Business account has it; the number must be added as a test recipient while the
     * Meta app is in development mode.)
     *
     * @throws AuthorizationException|ValidationException|RuntimeException
     */
    public function sendTest(User $actor, string $to): void
    {
        $organization = $this->organizationOf($actor);
        $connection = $organization->whatsappConnection()->first();

        if ($connection === null || ! $connection->isActive()) {
            throw new RuntimeException('Connect your WhatsApp number first.');
        }

        $number = PhoneNumber::toE164($to, $organization->country ?? 'US')
            ?? throw ValidationException::withMessages(['test_to' => 'Enter a mobile number with its country code, e.g. +92 300 1234567.']);

        try {
            $response = $this->client->graph($connection)->post($connection->phone_number_id.'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($number, '+'),
                'type' => 'template',
                'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']],
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Meta could not be reached. Try again in a minute.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Meta did not send it: '.WhatsAppClient::metaError($response));
        }
    }

    /**
     * The approved template used for follow-ups outside WhatsApp's 24-hour window. Its {{1}} gets the
     * customer's first name. Empty name: no template (those follow-ups go by email when possible).
     *
     * @param  array<string, mixed>  $input  template, language
     *
     * @throws AuthorizationException|ValidationException|RuntimeException
     */
    public function saveFollowUpTemplate(User $actor, array $input): WhatsAppConnection
    {
        $organization = $this->organizationOf($actor);
        $connection = $organization->whatsappConnection()->first() ?? throw new RuntimeException('Connect your WhatsApp number first.');

        $data = Validator::make($input, [
            'template' => ['nullable', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
        ], [
            'template.regex' => 'Use the template\'s name exactly as in Meta: lowercase letters, numbers and underscores.',
            'language.regex' => 'Use a language code like en, en_US or ur.',
        ])->validate();

        $connection->forceFill(['follow_up_template' => $data['template'] ?: null, 'follow_up_template_language' => $data['language']])->save();
        OrganizationActivity::record($organization, 'whatsapp_template_updated', $actor, ['template' => $connection->follow_up_template]);

        return $connection;
    }

    /**
     * @throws AuthorizationException
     */
    public function disconnect(User $actor): void
    {
        $organization = $this->organizationOf($actor);

        if (($connection = $organization->whatsappConnection()->first()) !== null) {
            // Connected through QuoteFlow's Meta app: stop Meta sending this account's messages to us (best effort).
            if ($connection->isEmbeddedSignup()) {
                try {
                    $this->client->graph($connection)->delete($connection->business_account_id.'/subscribed_apps');
                } catch (ConnectionException) {
                    // Meta unreachable: webhooks for an unknown number are refused anyway.
                }
            }

            $connection->delete();
            OrganizationActivity::record($organization, 'whatsapp_disconnected', $actor);
        }
    }

    /**
     * The one-time sign-in code becomes a token for the business's WhatsApp account. Only the server can do this
     * (it needs QuoteFlow's app secret), and a code works once.
     *
     * @throws RuntimeException
     */
    private function exchangeCode(string $code): string
    {
        $response = $this->request(fn () => $this->client->graphWithToken(null)->get('oauth/access_token', [
            'client_id' => config('services.whatsapp.embedded.app_id'),
            'client_secret' => config('services.whatsapp.embedded.app_secret'),
            'code' => $code,
        ]), 'Meta did not accept the Facebook sign-in');

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Meta did not return access to your WhatsApp account. Try connecting again.');
        }

        return $token;
    }

    /**
     * @throws RuntimeException
     */
    private function assertNumberInAccount(string $token, string $phoneNumberId, string $businessAccountId): void
    {
        $response = $this->request(fn () => $this->client->graphWithToken($token)->get($businessAccountId.'/phone_numbers', ['fields' => 'id', 'limit' => 100]),
            'Meta did not let QuoteFlow read this WhatsApp Business account');

        $ids = array_map('strval', array_column((array) $response->json('data'), 'id'));

        if (! in_array($phoneNumberId, $ids, true)) {
            throw new RuntimeException('That WhatsApp number isn\'t in the account you shared. Connect again and pick the number you want to use.');
        }
    }

    /**
     * Register the number for the Cloud API. A number that is already registered (e.g. connected before) may
     * refuse a new PIN; it still works, so this only logs, and check() decides whether the connection is usable.
     */
    private function register(string $token, string $phoneNumberId, string $pin, int $organizationId): void
    {
        try {
            $response = $this->client->graphWithToken($token)->post($phoneNumberId.'/register', ['messaging_product' => 'whatsapp', 'pin' => $pin]);
        } catch (ConnectionException) {
            Log::warning('WhatsApp number registration could not reach Meta.', ['organization_id' => $organizationId]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('WhatsApp number registration was refused.', ['organization_id' => $organizationId, 'meta_error' => WhatsAppClient::metaError($response)]);
        }
    }

    /**
     * @param  \Closure(): Response  $call
     *
     * @throws RuntimeException
     */
    private function request(\Closure $call, string $failure): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new RuntimeException('Meta could not be reached. Try again in a minute.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($failure.': '.WhatsAppClient::metaError($response));
        }

        return $response;
    }

    private function failed(WhatsAppConnection $connection, string $error): WhatsAppConnection
    {
        $connection->forceFill(['status' => WhatsAppConnection::STATUS_FAILED, 'last_error' => mb_substr($error, 0, 500)])->save();
        Log::warning('WhatsApp connection check failed.', ['organization_id' => $connection->organization_id, 'whatsapp_connection_id' => $connection->id]);

        return $connection;
    }

    /**
     * @throws AuthorizationException
     */
    private function organizationOf(User $actor)
    {
        if (! $actor->hasPermission(Permission::ManageEmail) || $actor->organization === null) {
            throw new AuthorizationException('Only the business owner can connect WhatsApp.');
        }

        return $actor->organization;
    }
}
