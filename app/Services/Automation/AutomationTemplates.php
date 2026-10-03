<?php

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\Organization;
use App\Models\User;

/**
 * Ready-made automations a business can add with one click.
 *
 * Installing copies the template into the organization's own records as a draft, through
 * AutomationBuilder, so it is validated like any automation and can be edited freely.
 * None of the templates email customers.
 */
class AutomationTemplates
{
    public function __construct(private readonly AutomationBuilder $builder) {}

    /**
     * @return array<string, array{name: string, description: string, trigger_type: string, conditions: list<array<string, string>>, actions: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        $highConfidence = ['type' => 'confidence_greater_than', 'operator' => 'greater_than_or_equal', 'value' => '0.8'];
        $notifyOwner = fn (string $message) => ['type' => 'notify_user', 'configuration' => ['message' => $message, 'recipients' => 'admins']];

        return [
            'ready_to_book' => [
                'name' => 'Customer Ready to Book',
                'description' => 'When a customer says they are ready to book, create a high-priority task, tag them and notify the owner.',
                'trigger_type' => 'customer_reply_classified',
                'conditions' => [
                    ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'ready_to_book'],
                    $highConfidence,
                ],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Book {customer_name}', 'priority' => 'high', 'due_in_hours' => 4]],
                    ['type' => 'add_customer_tag', 'configuration' => ['tag' => 'Ready to book']],
                    $notifyOwner('{customer_name} is ready to book.'),
                ],
            ],
            'price_objection' => [
                'name' => 'Price Objection',
                'description' => 'When a customer pushes back on price, create a high-priority task, tag them and notify the owner. No price is changed automatically.',
                'trigger_type' => 'customer_reply_classified',
                'conditions' => [
                    ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'price_objection'],
                ],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Review price concern from {customer_name}', 'priority' => 'high', 'due_in_hours' => 24]],
                    ['type' => 'add_customer_tag', 'configuration' => ['tag' => 'Price objection']],
                    $notifyOwner('{customer_name} raised a price concern.'),
                ],
            ],
            'interested' => [
                'name' => 'Interested Customer',
                'description' => 'When a customer is interested, tag them and schedule a follow-up in 2 days. A reply from the customer cancels the follow-up.',
                'trigger_type' => 'customer_reply_classified',
                'conditions' => [
                    ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'interested'],
                    $highConfidence,
                ],
                'actions' => [
                    ['type' => 'schedule_follow_up', 'configuration' => [
                        'delay_days' => 2,
                        'subject' => 'Following up on your estimate',
                        'body' => "Hi {{customer.first_name}},\n\nJust checking in on your estimate. Let us know if you have any questions or would like to schedule.\n\nThanks,\n{{business.name}}",
                    ]],
                    ['type' => 'add_customer_tag', 'configuration' => ['tag' => 'Interested']],
                ],
            ],
            'wants_callback' => [
                'name' => 'Customer Wants Callback',
                'description' => 'When a customer asks for a call, create a high-priority task, notify the owner and mark the conversation as waiting on the business.',
                'trigger_type' => 'customer_reply_classified',
                'conditions' => [
                    ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'wants_callback'],
                ],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Call back {customer_name}', 'priority' => 'high', 'due_in_hours' => 2]],
                    $notifyOwner('{customer_name} asked for a call back.'),
                    ['type' => 'update_conversation_status', 'configuration' => ['status' => 'waiting_business']],
                ],
            ],
            'estimate_follow_up' => [
                'name' => 'Estimate Follow-Up',
                'description' => 'When an estimate is sent, schedule a follow-up in 3 days. A customer reply, or accepting or declining the estimate, cancels it.',
                'trigger_type' => 'estimate_sent',
                'conditions' => [],
                'actions' => [
                    ['type' => 'schedule_follow_up', 'configuration' => [
                        'delay_days' => 3,
                        'subject' => 'Following up on estimate {{estimate.number}}',
                        'body' => "Hi {{customer.first_name}},\n\nI wanted to follow up on estimate {{estimate.number}} for {{estimate.title}} ({{estimate.total}}). Let us know if you have any questions.\n\nThanks,\n{{business.name}}",
                    ]],
                ],
            ],
            'estimate_declined' => [
                'name' => 'Estimate Declined',
                'description' => 'When a customer declines an estimate, create a high-priority task and notify the owner.',
                'trigger_type' => 'estimate_declined',
                'conditions' => [],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Review declined estimate for {customer_name}', 'priority' => 'high', 'due_in_hours' => 24]],
                    $notifyOwner('{customer_name} declined an estimate.'),
                ],
            ],
        ];
    }

    /**
     * Copy a template into the organization as a new draft automation.
     */
    public function install(Organization $organization, User $user, string $key): Automation
    {
        $template = self::all()[$key] ?? abort(404);

        return $this->builder->save($organization, $user, $template);
    }
}
