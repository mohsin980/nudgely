<?php

namespace App\Services\AI\Chat;

/**
 * What to ask a model. With a JSON schema, the answer must be one JSON object of that shape.
 */
final readonly class ChatRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public array $messages,
        public int $maxTokens = 400,
        public float $temperature = 0,
        public ?string $schemaName = null,
        public ?array $schema = null,
    ) {}

    public function wantsJson(): bool
    {
        return $this->schema !== null;
    }
}
