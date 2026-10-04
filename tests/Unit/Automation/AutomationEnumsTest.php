<?php

namespace Tests\Unit\Automation;

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\Automation\AutomationConditionType;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Automation\AutomationTriggerType;
use PHPUnit\Framework\TestCase;

/**
 * Enum values are persisted: these tests pin them so a rename can't silently break stored automations.
 */
class AutomationEnumsTest extends TestCase
{
    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    private function values(string $enum): array
    {
        return array_map(fn (\BackedEnum $case) => $case->value, $enum::cases());
    }

    public function test_enum_values_are_stable(): void
    {
        $this->assertSame(['draft', 'active', 'paused', 'archived'], $this->values(AutomationStatus::class));
        $this->assertSame([
            'customer_created', 'customer_reply_received', 'customer_reply_classified', 'estimate_created', 'estimate_sent',
            'estimate_viewed', 'estimate_expired', 'estimate_accepted', 'estimate_declined', 'follow_up_due', 'follow_up_completed',
            'conversation_closed', 'conversation_reopened',
        ], $this->values(AutomationTriggerType::class));
        $this->assertSame([
            'intent_equals', 'confidence_greater_than', 'customer_status_equals',
            'estimate_status_equals', 'days_since_last_message', 'conversation_status_equals',
            'customer_replied', 'customer_has_email', 'customer_has_phone', 'customer_email', 'customer_company',
            'conversation_priority', 'conversation_intent', 'conversation_subject', 'estimate_total', 'estimate_title',
            'estimate_valid_until', 'follow_up_status', 'follow_up_due_at',
        ], $this->values(AutomationConditionType::class));
        $this->assertSame([
            'equals', 'not_equals', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal',
            'contains', 'not_contains', 'is_true', 'is_false', 'before', 'after', 'on', 'on_or_before', 'on_or_after',
        ], $this->values(AutomationConditionOperator::class));
        $this->assertSame([
            'create_task', 'schedule_follow_up', 'send_email', 'add_customer_tag', 'remove_customer_tag', 'update_conversation_status', 'notify_user',
            'complete_follow_up', 'cancel_follow_up',
        ], $this->values(AutomationActionType::class));
        $this->assertSame(['waiting', 'running', 'completed', 'failed', 'skipped'], $this->values(AutomationRunStatus::class));
        $this->assertSame(['pending', 'running', 'completed', 'failed', 'skipped'], $this->values(AutomationActionRunStatus::class));
    }

    public function test_no_dangerous_action_types_exist(): void
    {
        foreach (['change_price', 'issue_discount', 'delete', 'book_appointment', 'refund', 'update_payment', 'change_permissions'] as $dangerous) {
            $this->assertNull(AutomationActionType::tryFrom($dangerous), $dangerous);
        }
    }

    public function test_only_send_email_requires_approval_by_default(): void
    {
        foreach (AutomationActionType::cases() as $type) {
            $this->assertSame($type === AutomationActionType::SendEmail, $type->requiresApprovalByDefault(), $type->value);
        }
    }

    public function test_condition_types_only_allow_sensible_operators(): void
    {
        // Operators come from the field's data type.
        $this->assertSame([AutomationConditionOperator::Equals, AutomationConditionOperator::NotEquals], AutomationConditionType::IntentEquals->allowedOperators());
        $this->assertNotContains(AutomationConditionOperator::Contains, AutomationConditionType::ConfidenceGreaterThan->allowedOperators());
        $this->assertSame(AutomationConditionOperator::GreaterThan, AutomationConditionType::ConfidenceGreaterThan->defaultOperator());
        $this->assertSame([AutomationConditionOperator::IsTrue, AutomationConditionOperator::IsFalse], AutomationConditionType::CustomerReplied->allowedOperators());
        $this->assertContains(AutomationConditionOperator::Contains, AutomationConditionType::CustomerEmail->allowedOperators());
        $this->assertContains(AutomationConditionOperator::OnOrBefore, AutomationConditionType::EstimateValidUntil->allowedOperators());
    }

    public function test_only_triggers_backed_by_real_events_are_available(): void
    {
        $available = array_values(array_filter(AutomationTriggerType::cases(), fn (AutomationTriggerType $t) => $t->isAvailable()));

        // Every trigger is dispatched by the application (Task 12 added the remaining events).
        $this->assertSame(AutomationTriggerType::cases(), $available);
    }

    public function test_every_case_has_a_label(): void
    {
        foreach ([AutomationStatus::class, AutomationTriggerType::class, AutomationConditionType::class, AutomationConditionOperator::class, AutomationActionType::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->label());
            }
        }
    }
}
