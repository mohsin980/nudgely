<?php

namespace App\Services\Email\Data;

/**
 * A provider-independent DNS record the organization must publish.
 */
final readonly class DnsRecord
{
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
        public string $purpose,
        public ?int $priority = null,
        public ?bool $verified = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) $data['type'],
            name: (string) $data['name'],
            value: (string) $data['value'],
            purpose: (string) ($data['purpose'] ?? ''),
            priority: isset($data['priority']) ? (int) $data['priority'] : null,
            verified: isset($data['verified']) ? (bool) $data['verified'] : null,
        );
    }

    /**
     * @return array{type: string, name: string, value: string, purpose: string, priority: ?int, verified: ?bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'value' => $this->value,
            'purpose' => $this->purpose,
            'priority' => $this->priority,
            'verified' => $this->verified,
        ];
    }
}
