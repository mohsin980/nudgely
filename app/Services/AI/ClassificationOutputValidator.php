<?php

namespace App\Services\AI;

use App\Enums\CustomerReplyIntent;
use App\Enums\ReplySentiment;
use App\Enums\ReplyUrgency;
use App\Exceptions\AI\ClassificationFailedException;
use App\Services\AI\Data\CustomerReplyClassification;
use Carbon\CarbonImmutable;

/**
 * Turns untrusted AI output into a CustomerReplyClassification, or refuses.
 *
 * Every field is checked against the controlled schema; nothing is coerced or invented.
 */
class ClassificationOutputValidator
{
    private const FIELDS = ['intent', 'confidence', 'summary', 'sentiment', 'urgency', 'requires_human_review'];

    /**
     * @throws ClassificationFailedException
     */
    public function fromJson(string $json, string $model): CustomerReplyClassification
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ClassificationFailedException::invalidOutput('malformed JSON');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw ClassificationFailedException::invalidOutput('expected a JSON object');
        }

        if ($missing = array_diff(['intent', 'confidence', 'summary', 'requires_human_review'], array_keys($data))) {
            throw ClassificationFailedException::invalidOutput('missing '.implode(', ', $missing));
        }

        if ($unexpected = array_diff(array_keys($data), self::FIELDS)) {
            throw ClassificationFailedException::invalidOutput('unexpected fields');
        }

        $intent = is_string($data['intent']) ? CustomerReplyIntent::tryFrom($data['intent']) : null;
        $intent ?? throw ClassificationFailedException::invalidOutput('unknown intent');

        $confidence = $data['confidence'];
        if ((! is_int($confidence) && ! is_float($confidence)) || is_nan((float) $confidence) || $confidence < 0 || $confidence > 1) {
            throw ClassificationFailedException::invalidOutput('confidence must be a number between 0 and 1');
        }

        $summary = $data['summary'];
        $maxLength = (int) config('ai.classification.summary_max_length', 300);
        if (! is_string($summary) || trim($summary) === '' || mb_strlen($summary) > $maxLength) {
            throw ClassificationFailedException::invalidOutput("summary must be a non-empty string of at most {$maxLength} characters");
        }

        if (! is_bool($data['requires_human_review'])) {
            throw ClassificationFailedException::invalidOutput('requires_human_review must be a boolean');
        }

        return new CustomerReplyClassification(
            intent: $intent,
            confidence: round((float) $confidence, 3),
            summary: trim(preg_replace('/\s+/u', ' ', $summary)),
            sentiment: $this->optionalEnum($data['sentiment'] ?? null, ReplySentiment::class, 'sentiment'),
            urgency: $this->optionalEnum($data['urgency'] ?? null, ReplyUrgency::class, 'urgency'),
            requiresHumanReview: $data['requires_human_review'],
            model: $model,
            classifiedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function optionalEnum(mixed $value, string $enum, string $field): ?\BackedEnum
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? $enum::tryFrom($value) : null)
            ?? throw ClassificationFailedException::invalidOutput("unknown {$field}");
    }
}
