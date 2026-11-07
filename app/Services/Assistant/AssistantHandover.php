<?php

namespace App\Services\Assistant;

use App\Enums\ConversationStatus;
use App\Enums\Team\NotificationType;
use App\Models\AssistantChat;
use App\Models\AssistantProfile;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Billing\UsageService;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands an AI assistant chat to a person: the chat stops, the conversation waits on the business, and the owner and
 * managers are told in the app (and by email if they chose), with the AI's summary. If the owner gave a WhatsApp
 * number, they also get a WhatsApp ping; WhatsApp only delivers it if that number messaged the business number in
 * the last 24 hours, so the app notification is what counts.
 */
class AssistantHandover
{
    public function __construct(
        private readonly TeamNotifier $notifier,
        private readonly TeamDirectory $team,
        private readonly WhatsAppClient $whatsapp,
    ) {}

    public function handOver(AssistantChat $chat, AssistantProfile $profile, string $reason): void
    {
        $changed = DB::transaction(function () use ($chat, $reason) {
            $locked = AssistantChat::query()->lockForUpdate()->find($chat->id);

            if ($locked === null || ! $locked->isActive()) {
                return false;
            }

            $locked->forceFill(['status' => AssistantChat::HANDED_OVER, 'handed_over_at' => now(), 'handover_reason' => mb_substr($reason, 0, 255)])->save();
            $chat->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $changed) {
            return;
        }

        $conversation = $chat->conversation;
        $conversation->forceFill(['status' => ConversationStatus::WaitingBusiness])->save();

        $name = $chat->customer?->name ?? 'A customer';
        $message = ($profile->assistant_name ?: 'The AI assistant').' handed over '.$name.': '.$reason;

        $this->notifyTeam($conversation, NotificationType::AssistantHandover, $message, 'assistant-chat:'.$chat->id.':handover');
        $this->pingOwner($conversation, $profile, $name, $reason, $chat->summary);

        Log::info('AI assistant handed over a chat.', ['organization_id' => $chat->organization_id, 'conversation_id' => $chat->conversation_id, 'assistant_chat_id' => $chat->id]);
    }

    /**
     * A person replied or pressed "Take over": the assistant stops answering this conversation.
     */
    public function takeOver(User $actor, Conversation $conversation): bool
    {
        return AssistantChat::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('conversation_id', $conversation->id)
            ->where('status', AssistantChat::ACTIVE)
            ->update([
                'status' => AssistantChat::HANDED_OVER,
                'handed_over_at' => now(),
                'handover_reason' => mb_substr('Taken over by '.$actor->name.'.', 0, 255),
                'taken_over_by' => $actor->id,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * The plan has no AI conversations left this month: the team is told once a month, and answers themselves.
     */
    public function limitReached(Conversation $conversation): void
    {
        [$start] = app(UsageService::class)->period($conversation->organization);

        $this->notifyTeam($conversation, NotificationType::AssistantHandover,
            'The AI assistant has used this month\'s AI conversations, so new WhatsApp chats need a person. Upgrade your plan for more.',
            'assistant-limit:'.$conversation->organization_id.':'.$start->format('Y-m'));
    }

    private function notifyTeam(Conversation $conversation, NotificationType $type, string $message, string $key): void
    {
        try {
            $this->notifier->notify($conversation->organization, $type, $this->team->leaders($conversation->organization_id), $message,
                route('inbox.show', $conversation->id), $key, data: ['conversation_id' => $conversation->id]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function pingOwner(Conversation $conversation, AssistantProfile $profile, string $name, string $reason, ?string $summary): void
    {
        $connection = $conversation->organization->whatsappConnection()->first();

        if (blank($profile->owner_whatsapp) || $connection === null || ! $connection->isActive()) {
            return;
        }

        $text = "QuoteFlow: {$name} needs you on WhatsApp.\n{$reason}"
            .(filled($summary) ? "\n\n{$summary}" : '')
            ."\n\nOpen the chat: ".route('inbox.show', $conversation->id);

        try {
            $this->whatsapp->sendText($connection, ltrim((string) $profile->owner_whatsapp, '+'), mb_substr($text, 0, 4000));
        } catch (Throwable) {
            // Usually: the owner hasn't messaged the business number in 24 hours. The app notification still went out.
            Log::info('Owner WhatsApp ping not delivered.', ['organization_id' => $conversation->organization_id]);
        }
    }
}
