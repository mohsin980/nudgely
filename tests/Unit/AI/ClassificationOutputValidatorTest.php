<?php

namespace Tests\Unit\AI;

use App\Enums\CustomerReplyIntent;
use App\Enums\ReplySentiment;
use App\Enums\ReplyUrgency;
use App\Exceptions\AI\ClassificationFailedException;
use App\Services\AI\ClassificationOutputValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClassificationOutputValidatorTest extends TestCase
{
    private function aiOutput(array $overrides = [], array $without = []): string
    {
        $data = array_merge([
            'intent' => 'price_objection',
            'confidence' => 0.96,
            'summary' => 'Customer is interested but is negotiating the quoted price.',
            'sentiment' => 'neutral',
            'urgency' => 'medium',
            'requires_human_review' => true,
        ], $overrides);

        return json_encode(array_diff_key($data, array_flip($without)));
    }

    public function test_valid_output_is_accepted(): void
    {
        $result = (new ClassificationOutputValidator)->fromJson($this->aiOutput(), 'gpt-test');

        $this->assertSame(CustomerReplyIntent::PriceObjection, $result->intent);
        $this->assertSame(0.96, $result->confidence);
        $this->assertSame('Customer is interested but is negotiating the quoted price.', $result->summary);
        $this->assertSame(ReplySentiment::Neutral, $result->sentiment);
        $this->assertSame(ReplyUrgency::Medium, $result->urgency);
        $this->assertTrue($result->requiresHumanReview);
        $this->assertSame('gpt-test', $result->model);
    }

    public function test_optional_fields_may_be_null_or_absent_and_integers_are_valid_confidence(): void
    {
        $result = (new ClassificationOutputValidator)->fromJson($this->aiOutput(['sentiment' => null, 'confidence' => 1], without: ['urgency']), 'm');

        $this->assertNull($result->sentiment);
        $this->assertNull($result->urgency);
        $this->assertSame(1.0, $result->confidence);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidOutputs(): array
    {
        $base = ['intent' => 'question', 'confidence' => 0.9, 'summary' => 'Asks about installation.', 'sentiment' => null, 'urgency' => null, 'requires_human_review' => false];

        return [
            'malformed json' => ['{"intent": "question",'],
            'not json' => ['Sure! The intent is question.'],
            'json array' => [json_encode([$base])],
            'json scalar' => ['"question"'],
            'unknown intent' => [json_encode(['intent' => 'wants_discount'] + $base)],
            'intent wrong type' => [json_encode(['intent' => 3] + $base)],
            'confidence above 1' => [json_encode(['confidence' => 1.2] + $base)],
            'confidence below 0' => [json_encode(['confidence' => -0.1] + $base)],
            'confidence as string' => [json_encode(['confidence' => '0.9'] + $base)],
            'confidence as percent' => [json_encode(['confidence' => 94] + $base)],
            'missing intent' => [json_encode(array_diff_key($base, ['intent' => 1]))],
            'missing confidence' => [json_encode(array_diff_key($base, ['confidence' => 1]))],
            'missing summary' => [json_encode(array_diff_key($base, ['summary' => 1]))],
            'missing review flag' => [json_encode(array_diff_key($base, ['requires_human_review' => 1]))],
            'empty summary' => [json_encode(['summary' => '   '] + $base)],
            'summary too long' => [json_encode(['summary' => str_repeat('a', 301)] + $base)],
            'review flag as string' => [json_encode(['requires_human_review' => 'yes'] + $base)],
            'unknown sentiment' => [json_encode(['sentiment' => 'ecstatic'] + $base)],
            'unknown urgency' => [json_encode(['urgency' => 'asap'] + $base)],
            'extra fields' => [json_encode($base + ['action' => 'send_discount_email'])],
        ];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_is_rejected_without_a_result(string $json): void
    {
        try {
            (new ClassificationOutputValidator)->fromJson($json, 'm');
            $this->fail('Expected exception.');
        } catch (ClassificationFailedException $e) {
            $this->assertFalse($e->transient);
            $this->assertStringStartsWith('AI returned an invalid classification', $e->getMessage());
            $this->assertStringNotContainsString('installation', $e->getMessage());
        }
    }
}
