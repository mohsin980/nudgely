<?php

namespace App\Services\Assistant;

use App\Enums\Billing\LimitKey;
use App\Enums\LeadStage;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Exceptions\AI\AiRequestFailedException;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\WhatsApp\WhatsAppSendException;
use App\Models\AssistantChat;
use App\Models\AssistantProfile;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\Chat\ChatClient;
use App\Services\AI\Chat\ChatReply;
use App\Services\AI\Chat\ChatRequest;
use App\Services\Billing\EntitlementService;
use App\Services\Leads\LeadStageService;
use App\Services\WhatsApp\WhatsAppMessenger;
use Illuminate\Support\Facades\Log;

/**
 * Answers a customer's WhatsApp message as the business's AI assistant.
 *
 * It only answers when: the business switched the assistant on and its plan includes it; the message is the
 * customer's latest and nobody has answered it yet; no person from the business is handling the chat; and the
 * lead isn't already won or lost. Each chat it starts counts towards the plan's monthly AI conversations.
 *
 * The AI's answer is untrusted: it is checked (AssistantDecision) and can only do three things, all checked here:
 * send a WhatsApp reply in this chat, mark the lead Qualified, and hand the chat over to a person.
 * When every AI model is unavailable the customer is told a person will reply, and the chat is handed over.
 */
class WhatsAppAssistant
{
    /** After this many AI replies in one chat, a person takes over. */
    public const MAX_REPLIES = 25;

    public function __construct(
        private readonly ChatClient $ai,
        private readonly AssistantPromptBuilder $prompts,
        private readonly WhatsAppMessenger $whatsapp,
        private readonly AssistantHandover $handover,
        private readonly EntitlementService $entitlements,
        private readonly LeadStageService $leadStages,
    ) {}

    /**
     * @return string what happened, for logs and tests
     */
    public function respond(int $messageId): string
    {
        $message = Message::query()->where('direction', MessageDirection::Inbound)->where('channel', MessageChannel::WhatsApp)->find($messageId);
        $conversation = $message?->conversation;
        $organization = $conversation?->organization;
        $customer = $conversation?->customer;

        if ($message === null || $conversation === null || $organization === null || $customer === null) {
            return 'missing';
        }

        $profile = $organization->assistantProfile()->first();

        if (! $profile?->enabled || ! $this->entitlements->hasFeature($organization, 'ai_assistant')) {
            return 'off';
        }

        if ($this->answeredOrNewer($message)) {
            return 'already answered';
        }

        $chat = AssistantChat::query()->where('conversation_id', $conversation->id)->latest('id')->first();

        if ($chat !== null && ! $chat->isActive()) {
            return 'handled by a person';
        }

        if ($chat === null) {
            if ($customer->lead_stage->isClosed() || $this->personIsHandling($conversation)) {
                return 'handled by a person';
            }

            $chat = $this->start($conversation);

            if ($chat === null) {
                return 'plan limit';
            }
        }

        if ($chat->replies >= self::MAX_REPLIES) {
            $this->handover->handOver($chat, $profile, 'Long conversation: the assistant handed over after '.self::MAX_REPLIES.' replies.');

            return 'handed over';
        }

        try {
            $decision = $this->decide($organization, $profile, $conversation, $chat);
        } catch (AiRequestFailedException $e) {
            Log::warning('AI assistant could not answer; handing over.', ['organization_id' => $organization->id, 'conversation_id' => $conversation->id, 'reason' => $e->reason]);
            $this->send($conversation, $chat, $chat->language === 'ur'
                ? 'آپ کے پیغام کا شکریہ! ہماری ٹیم جلد آپ سے رابطہ کرے گی۔'
                : 'Thanks for your message! A member of our team will reply shortly.');
            $this->handover->handOver($chat, $profile, 'The AI was unavailable, so this chat needs a person.');

            return 'ai unavailable';
        }

        if ($this->send($conversation, $chat, $decision->reply) === null) {
            return 'not sent';
        }

        $chat->forceFill([
            'replies' => $chat->replies + 1,
            'language' => $decision->language,
            'summary' => $decision->summary !== '' ? $decision->summary : $chat->summary,
            'details' => array_merge($chat->details ?? [], array_filter($decision->details, fn ($value) => $value !== null)),
        ])->save();

        if ($decision->qualified && $chat->qualified_at === null) {
            $chat->forceFill(['qualified_at' => now()])->save();
            $this->leadStages->advance($organization->id, $customer->id, LeadStage::Qualified, 'assistant_qualified', $conversation->id);
        }

        if ($decision->handover) {
            $this->handover->handOver($chat, $profile, (string) $decision->handoverReason);

            return 'replied and handed over';
        }

        return 'replied';
    }

    private function decide($organization, AssistantProfile $profile, Conversation $conversation, AssistantChat $chat): AssistantDecision
    {
        $request = new ChatRequest(
            messages: $this->prompts->messages($organization, $profile, $conversation, $chat),
            maxTokens: 700,
            temperature: 0.3,
            schemaName: 'assistant_decision',
            schema: AssistantDecision::schema(),
        );

        return $this->ai->complete($request, fn (ChatReply $reply) => AssistantDecision::fromJson($reply->content));
    }

    private function send(Conversation $conversation, AssistantChat $chat, string $text): ?Message
    {
        try {
            return $this->whatsapp->sendText($conversation, $text, ['type' => 'assistant', 'assistant_chat_id' => (string) $chat->id]);
        } catch (WhatsAppSendException $e) {
            Log::warning('AI assistant reply not sent.', ['organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id]);

            return null;
        }
    }

    /**
     * A newer customer message (its own job answers it), or a business message after this one.
     */
    private function answeredOrNewer(Message $message): bool
    {
        return Message::query()
            ->where('organization_id', $message->organization_id)
            ->where('conversation_id', $message->conversation_id)
            ->where('id', '>', $message->id)
            ->exists();
    }

    /**
     * Someone from the business wrote in this conversation in the last 30 days (not an automatic message).
     */
    private function personIsHandling(Conversation $conversation): bool
    {
        return Message::query()
            ->where('organization_id', $conversation->organization_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNull('automation_key') // follow-ups and automations don't count as a person
            ->where(fn ($query) => $query->whereNull('metadata->type')->orWhere('metadata->type', '!=', 'assistant'))
            ->exists();
    }

    /**
     * A new assistant chat, if the plan has AI conversations left this month (otherwise the team is told).
     */
    private function start(Conversation $conversation): ?AssistantChat
    {
        try {
            return $this->entitlements->guard($conversation->organization, LimitKey::AiConversations, function () use ($conversation) {
                $chat = new AssistantChat;
                $chat->forceFill([
                    'organization_id' => $conversation->organization_id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'status' => AssistantChat::ACTIVE,
                    'replies' => 0,
                ])->save();

                return $chat;
            });
        } catch (PlanLimitException) {
            $this->handover->limitReached($conversation);

            return null;
        }
    }
}
