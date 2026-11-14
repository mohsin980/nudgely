<?php

namespace App\Services\Assistant;

use App\Enums\MessageDirection;
use App\Models\AppointmentRequest;
use App\Models\AssistantChat;
use App\Models\Message;

/**
 * What a person needs to pick up a chat the AI assistant handed over: what the assistant last said, and what is still
 * open. Built only from data already stored; it sends and saves nothing.
 */
class HandoverBrief
{
    /**
     * @return array{told: ?string, open: list<string>}
     */
    public function for(AssistantChat $chat): array
    {
        return [
            'told' => $this->lastAssistantReply($chat),
            'open' => $this->openItems($chat),
        ];
    }

    private function lastAssistantReply(AssistantChat $chat): ?string
    {
        $text = Message::query()
            ->where('organization_id', $chat->organization_id)
            ->where('conversation_id', $chat->conversation_id)
            ->where('direction', MessageDirection::Outbound)
            ->where('metadata->type', 'assistant')
            ->latest('id')
            ->value('body_text');

        return filled($text) ? mb_substr((string) $text, 0, 300) : null;
    }

    /**
     * @return list<string>
     */
    private function openItems(AssistantChat $chat): array
    {
        $open = [];

        $request = AppointmentRequest::query()
            ->where('assistant_chat_id', $chat->id)
            ->where('status', AppointmentRequest::STATUS_REQUESTED)
            ->latest('id')
            ->first();

        if ($request !== null) {
            $open[] = 'Confirm the requested time: '.$chat->conversation->organization->formatDateTime($request->starts_at);
        }

        if ($chat->qualified_at === null) {
            $open[] = 'Not yet qualified';
        }

        $customer = $chat->customer;

        if ($customer !== null && blank($customer->email) && blank($customer->address)) {
            $open[] = 'No email or address on file';
        }

        return $open;
    }
}
