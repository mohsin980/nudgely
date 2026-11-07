<?php

namespace App\Services\AI\Chat;

/**
 * One way to ask a model: a provider's model with one of its API keys. A provider with three keys is three
 * ChatModels in the chain, so when one key reaches its limit the next key is tried before the next provider.
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
        public string $keyId = 'none',
        public ?string $keyHint = null,
    ) {}

    /**
     * Whether the model itself enforces a JSON schema (otherwise it is told the shape in the prompt).
     */
    public function enforcesSchema(): bool
    {
        return $this->jsonMode === 'schema';
    }

    /**
     * "Google Gemini (key ••••1234)", for messages to administrators.
     */
    public function describe(): string
    {
        return $this->label.($this->keyHint !== null ? ' (key '.$this->keyHint.')' : '');
    }

    /**
     * The provider's model once per key, in key order. Empty when it isn't set up (no key, or no URL for Ollama).
     *
     * @param  array<string, mixed>  $config  as AiModelSettings::all() gives it
     * @return list<self>
     */
    public static function forEachKey(string $name, array $config): array
    {
        $baseUrl = trim((string) ($config['base_url'] ?? ''));

        if ($baseUrl === '' || blank($config['model'] ?? null)) {
            return [];
        }

        $keys = ($config['keyless'] ?? false)
            ? [['id' => 'none', 'api_key' => null, 'hint' => null]]
            : (array) ($config['keys'] ?? []);

        return array_map(fn (array $key) => new self(
            name: $name,
            label: (string) ($config['label'] ?? $name),
            baseUrl: rtrim($baseUrl, '/'),
            apiKey: $key['api_key'],
            model: (string) $config['model'],
            timeout: max(5, (int) ($config['timeout'] ?? 30)),
            jsonMode: in_array($config['json'] ?? null, ['schema', 'object', 'prompt'], true) ? $config['json'] : 'prompt',
            openAiDialect: ($config['dialect'] ?? 'compatible') === 'openai',
            organization: filled($config['organization'] ?? null) ? (string) $config['organization'] : null,
            keyId: (string) $key['id'],
            keyHint: $key['hint'],
        ), $keys);
    }
}
