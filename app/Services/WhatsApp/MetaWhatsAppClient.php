<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppApiInterface;
use App\Exceptions\WhatsApp\WhatsAppApiException;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta WhatsApp Cloud API over the Graph API (https://developers.facebook.com/docs/whatsapp/cloud-api).
 *
 * The business's own token is used for its number. Nothing here logs request or response bodies, because
 * they contain the customer's phone number and the message text.
 */
class MetaWhatsAppClient implements WhatsAppApiInterface
{
    /**
     * @param  array{url: string, version: string, timeout: int}  $graph
     * @param  array{app_id: ?string, app_secret: ?string}  $app
     */
    public function __construct(
        private readonly array $graph,
        private readonly array $app,
    ) {}

    public function sendText(WhatsAppConnection $connection, string $to, string $body): string
    {
        return $this->sendMessage($connection, $to, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendTemplate(WhatsAppConnection $connection, string $to, string $name, string $language, array $parameters): string
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];

        if ($parameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], $parameters),
            ]];
        }

        return $this->sendMessage($connection, $to, ['type' => 'template', 'template' => $template]);
    }

    public function exchangeCode(string $code): string
    {
        $response = $this->request()->get($this->url('oauth/access_token'), [
            'client_id' => $this->app['app_id'],
            'client_secret' => $this->app['app_secret'],
            'code' => $code,
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw $this->failure('Meta did not return an access token for the signup code.', $response);
        }

        return $token;
    }

    public function phoneNumber(string $phoneNumberId, string $accessToken): array
    {
        $response = $this->request($accessToken)->get($this->url($phoneNumberId), ['fields' => 'display_phone_number,verified_name']);

        $display = $response->json('display_phone_number');

        if (! $response->successful() || ! is_string($display) || $display === '') {
            throw $this->failure('Meta did not return the phone number details.', $response);
        }

        $verifiedName = $response->json('verified_name');

        return [
            'display_phone_number' => $display,
            'verified_name' => is_string($verifiedName) ? $verifiedName : null,
        ];
    }

    public function subscribeApp(string $wabaId, string $accessToken): void
    {
        $response = $this->request($accessToken)->post($this->url($wabaId.'/subscribed_apps'));

        if (! $response->successful()) {
            throw $this->failure('Meta did not subscribe the app to the business account.', $response);
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function sendMessage(WhatsAppConnection $connection, string $to, array $content): string
    {
        try {
            $response = $this->request($connection->access_token)->post($this->url($connection->phone_number_id.'/messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
            ] + $content);
        } catch (ConnectionException) {
            throw new WhatsAppApiException('WhatsApp could not be reached.', transient: true);
        }

        $messageId = $response->json('messages.0.id');

        if (! $response->successful() || ! is_string($messageId) || $messageId === '') {
            throw $this->failure('Meta did not accept the WhatsApp message.', $response);
        }

        return $messageId;
    }

    private function failure(string $message, Response $response): WhatsAppApiException
    {
        $code = $response->json('error.code');
        $transient = $response->status() === 429 || $response->serverError() || $response->json('error.is_transient') === true;

        return new WhatsAppApiException($message.' HTTP '.$response->status().($code === null ? '' : ' code '.$code).'.', $transient);
    }

    private function request(?string $accessToken = null): PendingRequest
    {
        $request = Http::acceptJson()->timeout((int) $this->graph['timeout']);

        return $accessToken === null ? $request : $request->withToken($accessToken);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->graph['url'], '/').'/'.$this->graph['version'].'/'.ltrim($path, '/');
    }
}
