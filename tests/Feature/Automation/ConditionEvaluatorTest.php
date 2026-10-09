<?php

namespace Tests\Feature\Automation;

use App\Enums\Automation\AutomationTriggerType;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Models\Automation;
use App\Models\AutomationCondition;
use App\Models\Conversation;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\ConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private ConditionEvaluator $evaluator;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = app(ConditionEvaluator::class);
        $this->conversation = Conversation::factory()->create(['last_message_at' => now()->subDays(3)->subHour()]);
    }

    private function context(?CustomerReplyIntent $intent = CustomerReplyIntent::Interested, ?float $confidence = 0.85, ?int $conversationId = null): AutomationContext
    {
        return new AutomationContext(
            organizationId: $this->conversation->organization_id,
            triggerType: AutomationTriggerType::CustomerReplyClassified,
            eventId: 'classification:1',
            customerId: $this->conversation->customer_id,
            conversationId: $conversationId ?? $this->conversation->id,
            intent: $intent,
            confidence: $confidence,
        );
    }

    /**
     * Built from raw values, as a row loaded from the database would be (including corrupted ones).
     */
    private function condition(string $type, string $operator, string $value): AutomationCondition
    {
        return (new AutomationCondition)->setRawAttributes(['type' => $type, 'operator' => $operator, 'value' => $value]);
    }

    private function automation(array $conditions): Automation
    {
        $automation = Automation::factory()->create(['organization_id' => $this->conversation->organization_id]);
        foreach ($conditions as $i => [$type, $operator, $value]) {
            $automation->conditions()->create(['type' => $type, 'operator' => $operator, 'value' => $value, 'sort_order' => $i]);
        }

        return $automation;
    }

    public function test_intent_equals(): void
    {
        $this->assertTrue($this->evaluator->evaluate($this->condition('intent_equals', 'equals', 'interested'), $this->context()));
        $this->assertFalse($this->evaluator->evaluate($this->condition('intent_equals', 'equals', 'ready_to_book'), $this->context()));
        $this->assertTrue($this->evaluator->evaluate($this->condition('intent_equals', 'not_equals', 'ready_to_book'), $this->context()));
    }

    public function test_confidence_comparisons(): void
    {
        $ctx = $this->context(confidence: 0.8);

        $this->assertTrue($this->evaluator->evaluate($this->condition('confidence_greater_than', 'greater_than_or_equal', '0.8'), $ctx));
        $this->assertFalse($this->evaluator->evaluate($this->condition('confidence_greater_than', 'greater_than', '0.8'), $ctx));
        $this->assertTrue($this->evaluator->evaluate($this->condition('confidence_greater_than', 'greater_than', '0.75'), $ctx));
        $this->assertTrue($this->evaluator->evaluate($this->condition('confidence_greater_than', 'less_than', '0.9'), $ctx));
        $this->assertTrue($this->evaluator->evaluate($this->condition('confidence_greater_than', 'less_than_or_equal', '1'), $ctx));
    }

    public function test_conversation_status_and_days_since_last_message(): void
    {
        $this->assertTrue($this->evaluator->evaluate($this->condition('conversation_status_equals', 'equals', 'open'), $this->context()));
        $this->conversation->forceFill(['status' => ConversationStatus::WaitingBusiness])->save();
        $this->assertTrue($this->evaluator->evaluate($this->condition('conversation_status_equals', 'equals', 'waiting_business'), $this->context()));

        $this->assertTrue($this->evaluator->evaluate($this->condition('days_since_last_message', 'greater_than_or_equal', '3'), $this->context()));
        $this->assertFalse($this->evaluator->evaluate($this->condition('days_since_last_message', 'greater_than', '3'), $this->context()));
    }

    public function test_all_conditions_must_pass(): void
    {
        $both = $this->automation([['intent_equals', 'equals', 'interested'], ['confidence_greater_than', 'greater_than_or_equal', '0.8']]);
        $oneFails = $this->automation([['intent_equals', 'equals', 'interested'], ['confidence_greater_than', 'greater_than_or_equal', '0.9']]);
        $none = $this->automation([]);

        $this->assertTrue($this->evaluator->matches($both, $this->context(confidence: 0.85)));
        $this->assertFalse($this->evaluator->matches($oneFails, $this->context(confidence: 0.85)));
        $this->assertTrue($this->evaluator->matches($none, $this->context()), 'No conditions means every event matches.');
    }

    public function test_missing_data_never_matches(): void
    {
        $replyReceived = $this->context(intent: null, confidence: null);

        $this->assertFalse($this->evaluator->evaluate($this->condition('intent_equals', 'not_equals', 'spam'), $replyReceived));
        $this->assertFalse($this->evaluator->evaluate($this->condition('confidence_greater_than', 'less_than', '1'), $replyReceived));
    }

    public function test_conditions_never_read_another_organizations_conversation(): void
    {
        $foreign = Conversation::factory()->create(['status' => ConversationStatus::Closed]);

        $this->assertFalse($this->evaluator->evaluate(
            $this->condition('conversation_status_equals', 'equals', 'closed'),
            $this->context(conversationId: $foreign->id),
        ));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidConditions(): array
    {
        return [
            'unknown type' => ['customer_is_vip', 'equals', 'yes'],
            'unknown operator' => ['intent_equals', 'contains', 'interested'],
            'operator not allowed for type' => ['intent_equals', 'greater_than', 'interested'],
            'unknown intent' => ['intent_equals', 'equals', 'wants_discount'],
            'unknown conversation status' => ['conversation_status_equals', 'equals', 'archived'],
            'confidence as percent' => ['confidence_greater_than', 'greater_than', '80'],
            'confidence above 1' => ['confidence_greater_than', 'greater_than', '1.5'],
            'confidence not a number' => ['confidence_greater_than', 'greater_than', 'high'],
            'negative days' => ['days_since_last_message', 'greater_than', '-1'],
            'fractional days' => ['days_since_last_message', 'greater_than', '2.5'],
            'customer status (no data yet)' => ['customer_status_equals', 'equals', 'lead'],
            'unknown estimate status' => ['estimate_status_equals', 'equals', 'paid'],
        ];
    }

    #[DataProvider('invalidConditions')]
    public function test_invalid_conditions_are_rejected(string $type, string $operator, string $value): void
    {
        $this->expectException(InvalidAutomationConditionException::class);

        $this->evaluator->evaluate($this->condition($type, $operator, $value), $this->context());
    }

    #[DataProvider('invalidConditions')]
    public function test_assert_valid_rejects_the_same_conditions(string $type, string $operator, string $value): void
    {
        $this->expectException(InvalidAutomationConditionException::class);

        $this->evaluator->assertValid($type, $operator, $value);
    }

    public function test_assert_valid_accepts_good_conditions(): void
    {
        $this->evaluator->assertValid('intent_equals', 'equals', 'ready_to_book');
        $this->evaluator->assertValid('confidence_greater_than', 'greater_than_or_equal', '0.8');
        $this->evaluator->assertValid('days_since_last_message', 'greater_than', '2');
        $this->addToAssertionCount(3);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function codeInjection(): array
    {
        $marker = sys_get_temp_dir().'/quoteflow-condition-pwned';

        return [
            'php call as intent' => ['intent_equals', "system('touch {$marker}')"],
            'php tag' => ['intent_equals', '<?php touch("'.$marker.'"); ?>'],
            'expression as confidence' => ['confidence_greater_than', "0.5 || touch('{$marker}')"],
            'sql as days' => ['days_since_last_message', '1; DROP TABLE automations'],
            'backticks' => ['days_since_last_message', "`touch {$marker}`"],
        ];
    }

    #[DataProvider('codeInjection')]
    public function test_values_are_data_and_never_executed(string $type, string $value): void
    {
        $marker = sys_get_temp_dir().'/quoteflow-condition-pwned';
        @unlink($marker);
        $operator = $type === 'intent_equals' ? 'equals' : 'greater_than';

        try {
            $this->evaluator->evaluate($this->condition($type, $operator, $value), $this->context());
            $this->fail('Expected the value to be rejected.');
        } catch (InvalidAutomationConditionException) {
        }

        $this->assertFileDoesNotExist($marker);
        $this->assertTrue(Schema::hasTable('automations'));
    }

    public function test_evaluator_contains_no_dynamic_code_execution(): void
    {
        $source = file_get_contents(app_path('Services/Automation/ConditionEvaluator.php'));

        foreach (['eval(', 'create_function', 'call_user_func', 'assert(', 'preg_replace_callback', '$$'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }
}
