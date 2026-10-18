<?php

namespace App\Services\WhatsApp;

use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\WhatsApp\WhatsAppException;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\DB;

/**
 * Sends WhatsApp messages from a conversation. Each send is stored as a queued Message first, then the
 * SendWhatsAppMessageJob hands it to Meta, so a slow or failed call never loses or duplicates a message.
 */
class WhatsAppMessagingService
{
    public const MAX_BODY = 4096;

    public function __construct(private readonly WhatsAppWindow $window) {}

    /**
     * Free-form text. Only allowed while the customer's 24-hour window is open.
     *
     * @throws WhatsAppException
     */
    public function sendText(User $actor, Conversation $conversation, string $body): Message
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            throw WhatsAppException::invalidBody(self::MAX_BODY);
        }

        $connection = $this->connectionFor($actor, $conversation);

        if (! $this->window->isOpen($conversation->customer)) {
            throw WhatsAppException::windowClosed();
        }

        return $this->queue($actor, $conversation, $connection, $body, ['kind' => 'text']);
    }

    /**
     * The approved follow-up template. Allowed at any time, and the only option once the window has closed.
     *
     * @param  list<string>  $parameters  Values for the template's placeholders, in order.
     *
     * @throws WhatsAppException
     */
    public function sendTemplate(User $actor, Conversation $conversation, array $parameters): Message
    {
        $connection = $this->connectionFor($actor, $conversation);
        $template = config('whatsapp.follow_up_template');
        $values = array_values(array_map(fn ($value) => mb_substr(trim((string) $value), 0, 1024), $parameters));

        return $this->queue($actor, $conversation, $connection, implode(' ', $values) ?: '[Template message]', [
            'kind' => 'template',
            'template' => $template['name'],
            'language' => $template['language'],
            'parameters' => $values,
        ]);
    }

    /**
     * @throws WhatsAppException
     */
    private function connectionFor(User $actor, Conversation $conversation): WhatsAppConnection
    {
        if ($actor->organization_id === null || $actor->organization_id !== $conversation->organization_id) {
            throw WhatsAppException::notAllowed();
        }

        $connection = WhatsAppConnection::query()->where('organization_id', $conversation->organization_id)->first();

        if ($connection === null || ! $connection->isActive()) {
            throw WhatsAppException::notConnected();
        }

        if ($conversation->customer->phone_digits === null) {
            throw WhatsAppException::noPhone();
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $content  What to send, stored with the message so the job can send it.
     */
    private function queue(User $actor, Conversation $conversation, WhatsAppConnection $connection, string $bodyText, array $content): Message
    {
        return DB::transaction(function () use ($actor, $conversation, $connection, $bodyText, $content) {
            $message = new Message;
            $message->forceFill([
                'organization_id' => $conversation->organization_id,
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'channel' => MessageChannel::WhatsApp,
                'provider' => WhatsAppConnection::PROVIDER,
                'from_address' => $connection->display_phone_number,
                'to_address' => '+'.$conversation->customer->phone_digits,
                'subject' => '',
                'body_text' => $bodyText,
                'status' => MessageStatus::Queued,
                'metadata' => ['sent_by' => (string) $actor->id] + $content,
            ])->save();

            $conversation->recordActivity(now(), ConversationStatus::WaitingCustomer);

            SendWhatsAppMessageJob::dispatch($message->id)->afterCommit();

            return $message;
        });
    }
}
