<?php

namespace App\Services\Email\Data;

use App\Enums\MessageStatus;

/**
 * The normalized outcome of handing an email to a provider. Never contains raw provider responses.
 */
final readonly class EmailSendResult
{
    public function __construct(
        public MessageStatus $status,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function sent(string $providerMessageId): self
    {
        return new self(MessageStatus::Sent, $providerMessageId);
    }

    public static function failed(?string $errorCode, string $errorMessage): self
    {
        return new self(MessageStatus::Failed, errorCode: $errorCode, errorMessage: $errorMessage);
    }

    public function successful(): bool
    {
        return $this->status === MessageStatus::Sent || $this->status === MessageStatus::Queued;
    }
}
