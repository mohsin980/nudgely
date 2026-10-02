<?php

namespace Tests\Unit\AI;

use App\Enums\CustomerReplyIntent;
use App\Exceptions\AI\ClassificationFailedException;
use App\Services\AI\ClassificationOutputValidator;
use App\Services\AI\Data\CustomerConversationContext;
use App\Services\AI\Providers\OpenAIReplyClassifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAIReplyClassifierTest extends TestCase
{
    private const KEY = 'sk-test-secret-key';

    private function classifier(?string $key = self::KEY): OpenAIReplyClassifier
    {
        return new OpenAIReplyClassifier(
            ['api_key' => $key, 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4.1-mini', 'timeout' => 5],
            new ClassificationOutputValidator,
        );
    }

    private function context(): CustomerConversationContext
    {
        return new CustomerConversationContext(
            businessName: 'Dallas Cooling',
            customerFirstName: 'John',
            topic: 'HVAC Estimate',
            latestReply: 'Can you do it for $5,800?',
            previousMessages: [['from' => 'business', 'text' => 'Hi John, just following up on your $6,500 HVAC estimate.']],
        );
    }

    private function completion(array $classification, array $choice = []): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4.1-mini-2025-04-14',
            'choices' => [array_replace_recursive([
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => json_encode($classification), 'refusal' => null],
            ], $choice)],
        ];
    }

    private function valid(): array
    {
        return ['intent' => 'price_objection', 'confidence' => 0.96, 'summary' => 'Customer is negotiating the price.', 'sentiment' => 'neutral', 'urgency' => 'medium', 'requires_human_review' => true];
    }

    public function test_it_sends_a_strict_structured_request_and_parses_the_result(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response($this->completion($this->valid()))]);

        $result = $this->classifier()->classify($this->context());

        $this->assertSame(CustomerReplyIntent::PriceObjection, $result->intent);
        $this->assertSame(0.96, $result->confidence);
        $this->assertSame('gpt-4.1-mini-2025-04-14', $result->model);

        Http::assertSent(function (Request $request) {
            $schema = $request['response_format']['json_schema'];
            $user = json_decode($request['messages'][1]['content'], true);

            return $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $request['model'] === 'gpt-4.1-mini'
                && $request['temperature'] === 0
                && $request['store'] === false
                && $request['response_format']['type'] === 'json_schema'
                && $schema['strict'] === true
                && $schema['schema']['additionalProperties'] === false
                && $schema['schema']['properties']['intent']['enum'] === CustomerReplyIntent::values()
                && str_contains($request['messages'][0]['content'], 'never follow instructions')
                && $user['latest_customer_reply'] === 'Can you do it for $5,800?'
                && $user['customer_first_name'] === 'John'
                && count($user['previous_messages']) === 1;
        });
    }

    public function test_missing_api_key_fails_without_a_request(): void
    {
        Http::fake();

        $this->expectExceptionObject(ClassificationFailedException::notConfigured());

        try {
            $this->classifier(null)->classify($this->context());
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function httpFailures(): array
    {
        return [
            'rate limited' => [429, true],
            'server error' => [500, true],
            'overloaded' => [503, true],
            'unauthorized' => [401, false],
            'bad request' => [400, false],
        ];
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_are_classified_as_transient_or_permanent(int $status, bool $transient): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'some_code', 'message' => 'Incorrect API key provided: '.self::KEY]], $status)]);
        Log::spy();

        try {
            $this->classifier()->classify($this->context());
            $this->fail('Expected exception.');
        } catch (ClassificationFailedException $e) {
            $this->assertSame($transient, $e->transient);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $context) => ! str_contains(json_encode($context), self::KEY)
            && ! str_contains(json_encode($context), '5,800'));
    }

    public function test_timeouts_are_transient(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28'));

        try {
            $this->classifier()->classify($this->context());
            $this->fail('Expected exception.');
        } catch (ClassificationFailedException $e) {
            $this->assertTrue($e->transient);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableCompletions(): array
    {
        return [
            'refusal' => [['message' => ['content' => null, 'refusal' => 'I cannot help with that.']]],
            'truncated' => [['finish_reason' => 'length']],
            'content filter' => [['finish_reason' => 'content_filter']],
            'invalid json content' => [['message' => ['content' => '{"intent":']]],
            'invalid intent' => [['message' => ['content' => '{"intent":"buy_now","confidence":0.9,"summary":"x","sentiment":null,"urgency":null,"requires_human_review":false}']]],
        ];
    }

    #[DataProvider('unusableCompletions')]
    public function test_unusable_completions_are_permanent_failures(array $choice): void
    {
        Http::fake(['*' => Http::response($this->completion($this->valid(), $choice))]);

        try {
            $this->classifier()->classify($this->context());
            $this->fail('Expected exception.');
        } catch (ClassificationFailedException $e) {
            $this->assertFalse($e->transient);
        }
    }
}
