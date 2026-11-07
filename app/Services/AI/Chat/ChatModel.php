<?php

namespace App\Services\AI\Chat;

/**
 * One model in the chain: where it is, how to reach it, and what it supports.
 */
final readonly class ChatModel
{
    public function __construct(
        public string $name,
        public string $label,
        public string $baseUrl,
        public ?string $apiKey,
        public string $model,
        public int $timeout,
        public string $jsonMode,
        public bool $openAiDialect,
        public ?string $organization = null,
    ) {}

    /**
     * Whether the model itself enforces a JSON schema (otherwise it is told the shape in the prompt).
     */
    public function enforcesSchema(): bool
    {
        return $this->jsonMode === 'schema';
    }

    /**
     * The model described by config/ai.php "models", or null when it isn't set up (no key, or no URL for Ollama).
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): ?self
    {
        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if ($baseUrl === '' || ($apiKey === '' && ! ($config['keyless'] ?? false)) || blank($config['model'] ?? null)) {
            return null;
        }

        return new self(
            name: $name,
            label: (string) ($config['label'] ?? $name),
            baseUrl: rtrim($baseUrl, '/'),
            apiKey: $apiKey === '' ? null : $apiKey,
            model: (string) $config['model'],
            timeout: max(5, (int) ($config['timeout'] ?? 30)),
            jsonMode: in_array($config['json'] ?? null, ['schema', 'object', 'prompt'], true) ? $config['json'] : 'prompt',
            openAiDialect: ($config['dialect'] ?? 'compatible') === 'openai',
            organization: filled($config['organization'] ?? null) ? (string) $config['organization'] : null,
        );
    }
}
