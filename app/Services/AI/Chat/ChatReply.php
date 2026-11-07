<?php

namespace App\Services\AI\Chat;

/**
 * A model's answer, and which model gave it.
 */
final readonly class ChatReply
{
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
    ) {}
}
