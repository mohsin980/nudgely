<?php

namespace App\Services\WhatsApp;

use App\Enums\Team\Permission;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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

        $data = Validator::make($input, [
            'phone_number_id' => ['required', 'regex:/^\d{6,30}$/', Rule::unique('whatsapp_connections', 'phone_number_id')->ignore($existing?->id)],
            'business_account_id' => ['required', 'regex:/^\d{6,30}$/'],
            // Secrets can be left empty to keep the ones already saved.
            'access_token' => [$existing ? 'nullable' : 'required', 'string', 'min:20', 'max:1000', 'regex:/^\S+$/'],
            'app_secret' => [$existing ? 'nullable' : 'required', 'string', 'min:16', 'max:200', 'regex:/^\S+$/'],
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
     * Ask Meta for the number's details: proves the ID and token are right and the token can use the number.
     */
    public function check(WhatsAppConnection $connection): WhatsAppConnection
    {
        try {
            $response = $this->graph($connection)->get($connection->phone_number_id, ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException) {
            return $this->failed($connection, 'Meta could not be reached. Try again in a minute.');
        }

        if (! $response->successful()) {
            return $this->failed($connection, $this->metaError($response));
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
            $response = $this->graph($connection)->post($connection->phone_number_id.'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($number, '+'),
                'type' => 'template',
                'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']],
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Meta could not be reached. Try again in a minute.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Meta did not send it: '.$this->metaError($response));
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function disconnect(User $actor): void
    {
        $organization = $this->organizationOf($actor);

        if (($connection = $organization->whatsappConnection()->first()) !== null) {
            $connection->delete();
            OrganizationActivity::record($organization, 'whatsapp_disconnected', $actor);
        }
    }

    private function graph(WhatsAppConnection $connection): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version'))
            ->withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout((int) config('services.whatsapp.timeout', 10));
    }

    private function failed(WhatsAppConnection $connection, string $error): WhatsAppConnection
    {
        $connection->forceFill(['status' => WhatsAppConnection::STATUS_FAILED, 'last_error' => mb_substr($error, 0, 500)])->save();
        Log::warning('WhatsApp connection check failed.', ['organization_id' => $connection->organization_id, 'whatsapp_connection_id' => $connection->id]);

        return $connection;
    }

    /**
     * Meta's own explanation, which is usually specific ("Invalid OAuth access token").
     */
    private function metaError(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) && $message !== '' ? Str::limit($message, 300) : 'Meta answered with an error (HTTP '.$response->status().'). Check the values and try again.';
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
