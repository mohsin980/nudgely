<?php

namespace App\Services\Assistant;

use App\Exceptions\AI\InvalidModelOutputException;

/**
 * What the AI assistant decided for one customer message: the reply to send, and whether the lead is qualified
 * or needs a person. Built only from AI output that passes every check here (the output is untrusted).
 */
final readonly class AssistantDecision
{
    public const DETAIL_FIELDS = ['requirement', 'budget', 'location', 'timeline', 'email', 'address', 'other'];

    /** Languages the assistant can reply in (codes match AssistantProfile::LANGUAGES). */
    public const LANGUAGES = ['en', 'ur', 'ar'];

    /**
     * @param  array<string, ?string>  $details
     */
    public function __construct(
        public string $reply,
        public string $language,
        public bool $qualified,
        public bool $handover,
        public ?string $handoverReason,
        public string $summary,
        public array $details,
        public ?string $appointmentSlot = null,
    ) {}

    /**
     * The same decision, handed to a person (used when the assistant booked a time for the team to confirm).
     */
    public function withHandover(string $reason): self
    {
        return new self(
            reply: $this->reply,
            language: $this->language,
            qualified: $this->qualified,
            handover: true,
            handoverReason: $reason,
            summary: $this->summary,
            details: $this->details,
            appointmentSlot: $this->appointmentSlot,
        );
    }

    /**
     * @throws InvalidModelOutputException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidModelOutputException('malformed JSON');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidModelOutputException('expected a JSON object');
        }

        $reply = is_string($data['reply'] ?? null) ? trim($data['reply']) : '';

        if ($reply === '' || mb_strlen($reply) > 1500) {
            throw new InvalidModelOutputException('reply missing or too long');
        }

        foreach (['qualified', 'handover'] as $flag) {
            if (! is_bool($data[$flag] ?? null)) {
                throw new InvalidModelOutputException("{$flag} must be true or false");
            }
        }

        $language = in_array($data['language'] ?? null, self::LANGUAGES, true) ? $data['language'] : 'en';
        $slot = is_string($data['appointment_slot'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $data['appointment_slot'])
            ? $data['appointment_slot'] : null;
        $details = [];

        foreach (self::DETAIL_FIELDS as $field) {
            $value = $data['details'][$field] ?? null;
            $details[$field] = is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 200) : null;
        }

        // Only a real-looking email is kept; anything else is dropped rather than saved on the customer.
        if ($details['email'] !== null && filter_var($details['email'], FILTER_VALIDATE_EMAIL) === false) {
            $details['email'] = null;
        }

        $reason = is_string($data['handover_reason'] ?? null) && trim($data['handover_reason']) !== '' ? mb_substr(trim($data['handover_reason']), 0, 200) : null;

        return new self(
            reply: $reply,
            language: $language,
            qualified: $data['qualified'],
            handover: $data['handover'],
            handoverReason: $data['handover'] ? ($reason ?? 'The assistant asked for a person to take over.') : null,
            summary: mb_substr(trim(is_string($data['summary'] ?? null) ? $data['summary'] : ''), 0, 600),
            details: $details,
            appointmentSlot: $slot,
        );
    }

    /**
     * The JSON schema the AI must answer with.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['reply', 'language', 'qualified', 'handover', 'handover_reason', 'summary', 'details', 'appointment_slot'],
            'properties' => [
                'reply' => ['type' => 'string', 'description' => 'The WhatsApp message to send to the customer now.'],
                'language' => ['type' => 'string', 'enum' => self::LANGUAGES],
                'appointment_slot' => ['type' => ['string', 'null'], 'description' => 'The key of the offered appointment time the customer picked, exactly as listed under OPEN APPOINTMENT TIMES; otherwise null.'],
                'qualified' => ['type' => 'boolean'],
                'handover' => ['type' => 'boolean'],
                'handover_reason' => $text,
                'summary' => ['type' => 'string', 'description' => 'Two or three sentences for the business owner: who the lead is and what they want.'],
                'details' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => self::DETAIL_FIELDS,
                    'properties' => array_fill_keys(self::DETAIL_FIELDS, $text),
                ],
            ],
        ];
    }
}
