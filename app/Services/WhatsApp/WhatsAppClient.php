<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsApp\WhatsAppSendException;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to Meta's WhatsApp Cloud API for one business's number. Returns Meta's message id ("wamid…").
 */
class WhatsAppClient
{
    /**
     * Free text. Only allowed within 24 hours of the customer's last message to the business.
     *
     * @throws WhatsAppSendException
     */
    public function sendText(WhatsAppConnection $connection, string $to, string $body): string
    {
        return $this->send($connection, [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($to, '+'),
            'type' => 'text',
            'text' => ['body' => mb_substr($body, 0, 4096), 'preview_url' => false],
        ]);
    }

    /**
     * An approved template, with its {{1}}, {{2}}… body parameters in order.
     *
     * @param  list<string>  $parameters
     *
     * @throws WhatsAppSendException
     */
    public function sendTemplate(WhatsAppConnection $connection, string $to, string $name, string $language, array $parameters = []): string
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];

        if ($parameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => mb_substr($value, 0, 1024)], $parameters),
            ]];
        }

        return $this->send($connection, ['messaging_product' => 'whatsapp', 'to' => ltrim($to, '+'), 'type' => 'template', 'template' => $template]);
    }

    public function graph(WhatsAppConnection $connection): PendingRequest
    {
        return $this->graphWithToken((string) $connection->access_token);
    }

    /**
     * Meta's Graph API with a given token, or with none (for exchanging a sign-in code).
     */
    public function graphWithToken(?string $token): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version'))
            ->acceptJson()
            ->timeout((int) config('services.whatsapp.timeout', 10));

        return $token === null ? $request : $request->withToken($token);
    }

    /**
     * Meta's own explanation, which is usually specific ("Invalid OAuth access token").
     */
    public static function metaError(Response $response): string
    {
        $message = $response->json('error.error_data.details') ?: $response->json('error.message');

        return is_string($message) && $message !== '' ? Str::limit($message, 300) : 'Meta answered with an error (HTTP '.$response->status().').';
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws WhatsAppSendException
     */
    private function send(WhatsAppConnection $connection, array $payload): string
    {
        if (! $connection->isActive()) {
            throw new WhatsAppSendException('WhatsApp is not connected. Connect it in Settings → WhatsApp.');
        }

        try {
            $response = $this->graph($connection)->post($connection->phone_number_id.'/messages', $payload);
        } catch (ConnectionException) {
            throw new WhatsAppSendException('Meta could not be reached. Try again in a minute.');
        }

        $id = $response->json('messages.0.id');

        if (! $response->successful() || ! is_string($id) || $id === '') {
            throw new WhatsAppSendException('WhatsApp did not send it: '.self::metaError($response));
        }

        return $id;
    }
}
