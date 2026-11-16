<?php

namespace App\Services\Outreach;

use App\Enums\Outreach\OutreachChannel;
use App\Exceptions\Outreach\OutreachSendException;
use App\Models\OutreachContact;
use App\Models\OutreachTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * Sends one approved message through the platform's own accounts. Returns the provider's message ID.
 *
 * Tokens come from config and are never logged or returned. The email carries an unsubscribe link, and WhatsApp
 * can only send a template Meta has approved, never free text.
 */
class OutreachSender
{
    public function __construct(private readonly OutreachRenderer $renderer) {}

    public function send(OutreachChannel $channel, OutreachContact $contact, OutreachTemplate $template): string
    {
        return $channel === OutreachChannel::Email
            ? $this->sendEmail($contact, $template)
            : $this->sendWhatsApp($contact, $template);
    }

    private function sendEmail(OutreachContact $contact, OutreachTemplate $template): string
    {
        $token = (string) config('outreach.email.postmark_token');
        $from = (string) config('outreach.email.from_email');

        if ($token === '' || $from === '') {
            throw new OutreachSendException('Email sending is not configured.');
        }

        $unsubscribe = URL::signedRoute('outreach.unsubscribe', ['contact' => $contact->id]);
        $text = $this->renderer->render($template->body, $contact)."\n\nTo stop these emails: ".$unsubscribe;

        $response = $this->call(fn () => Http::withHeaders([
            'X-Postmark-Server-Token' => $token,
            'Accept' => 'application/json',
        ])->timeout(15)->post('https://api.postmarkapp.com/email', [
            'From' => config('outreach.email.from_name').' <'.$from.'>',
            'To' => $contact->email,
            'Subject' => $this->renderer->render((string) $template->subject, $contact),
            'TextBody' => $text,
            'MessageStream' => config('outreach.email.message_stream'),
            'Tag' => 'outreach',
        ]));

        return (string) ($response->json('MessageID') ?? '');
    }

    private function sendWhatsApp(OutreachContact $contact, OutreachTemplate $template): string
    {
        $token = (string) config('outreach.whatsapp.token');
        $phoneId = (string) config('outreach.whatsapp.phone_number_id');

        if ($token === '' || $phoneId === '' || blank($template->whatsapp_template_name)) {
            throw new OutreachSendException('WhatsApp sending is not configured for this template.');
        }

        $firstName = $this->renderer->render('{{first_name}}', $contact);

        $response = $this->call(fn () => Http::baseUrl(rtrim((string) config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version'))
            ->withToken($token)->acceptJson()->timeout(15)
            ->post($phoneId.'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim((string) $contact->phone, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $template->whatsapp_template_name,
                    'language' => ['code' => $template->whatsapp_language ?: 'en'],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $firstName]]]],
                ],
            ]));

        return (string) ($response->json('messages.0.id') ?? '');
    }

    /** Turns an HTTP or network failure into the exception the job understands. */
    private function call(\Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new OutreachSendException('The provider could not be reached.', transient: true);
        }

        if ($response->successful()) {
            return $response;
        }

        $transient = $response->status() === 429 || $response->serverError();

        throw new OutreachSendException('The provider refused the message (HTTP '.$response->status().').', transient: $transient);
    }
}
