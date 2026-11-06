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
 * Nothing is activated automatically.
 */
class AutomationTemplates
{
    /** Shown first, as "Starter templates". */
    public const STARTERS = ['follow_up_after_estimate', 'new_lead_follow_up', 'ready_to_book', 'estimate_accepted', 'interested'];

    public function __construct(private readonly AutomationBuilder $builder) {}

    /**
     * @return array<string, array{name: string, description: string, trigger_type: string, conditions: list<array<string, string>>, actions: list<array<string, mixed>>, wait_minutes?: int}>
     */
    public static function all(): array
    {
        $highConfidence = ['type' => 'confidence_greater_than', 'operator' => 'greater_than_or_equal', 'value' => '0.8'];
        $notifyOwner = fn (string $message) => ['type' => 'notify_user', 'configuration' => ['message' => $message, 'recipients' => 'admins']];

        return [
            'follow_up_after_estimate' => [
                'name' => 'Follow up after estimate',
                'description' => 'Three days after an estimate is sent, email the customer if they haven’t replied.',
                'trigger_type' => 'estimate_sent',
                'wait_minutes' => 3 * 1440,
                'conditions' => [
                    ['type' => 'customer_replied', 'operator' => 'is_false', 'value' => ''],
                ],
                'actions' => [
                    ['type' => 'send_email', 'requires_approval' => false, 'configuration' => [
                        'subject' => 'Checking in about your estimate',
                        'body' => "Hi {{customer.first_name}},\n\nJust checking in regarding estimate {{estimate.number}}.\n\nPlease let us know if you have any questions.\n\nThanks,\n{{business.name}}",
                    ]],
                ],
            ],
            'ready_to_book' => [
                'name' => 'Ready to book alert',
                'description' => 'When a customer says they are ready to book, create a high-priority task, tag them and notify the owner.',
                'trigger_type' => 'customer_reply_classified',
                'conditions' => [
                    ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'ready_to_book'],
                    $highConfidence,
                ],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Book {{customer.name}}', 'priority' => 'high', 'due_in_hours' => 4]],
                    ['type' => 'add_customer_tag', 'configuration' => ['tag' => 'Ready to book']],
                    $notifyOwner('{{customer.name}} is ready to book.'),
                ],
            ],
            'estimate_accepted' => [
                'name' => 'Estimate accepted',
                'description' => 'When a customer accepts an estimate, create a task to schedule the service.',
                'trigger_type' => 'estimate_accepted',
                'conditions' => [],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Contact {{customer.name}} to schedule service', 'description' => 'Accepted estimate {{estimate.number}} ({{estimate.total}}).', 'priority' => 'high', 'due_in_hours' => 24, 'assign_to' => 'owner']],
                ],
            ],
            'interested' => [
                'name' => 'Interested customer follow-up',
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
            'price_objection' => [
                'name' => 'Price objection',
                'description' => 'When a customer pushes back on price, create a high-priority task, tag them and notify the owner. No price is changed automatically.',
                'trigger_type' => 'customer_reply_classified',
                'conditions' => [
                    ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'price_objection'],
                ],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Review price concern from {{customer.name}}', 'priority' => 'high', 'due_in_hours' => 24]],
                    ['type' => 'add_customer_tag', 'configuration' => ['tag' => 'Price objection']],
                    $notifyOwner('{{customer.name}} raised a price concern.'),
                ],
            ],
            'wants_callback' => [
                'name' => 'Customer wants callback',
                'description' => 'When a customer asks for a call, create a high-priority task, notify the owner and mark the conversation as waiting on the business.',
                'trigger_type' => 'customer_reply_classified',
                'conditions' => [
                    ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'wants_callback'],
                ],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Call back {{customer.name}}', 'priority' => 'high', 'due_in_hours' => 2]],
                    $notifyOwner('{{customer.name}} asked for a call back.'),
                    ['type' => 'update_conversation_status', 'configuration' => ['status' => 'waiting_business']],
                ],
            ],
            'estimate_follow_up' => [
                'name' => 'Estimate follow-up (scheduled)',
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
                'name' => 'Estimate declined',
                'description' => 'When a customer declines an estimate, create a high-priority task and notify the owner.',
                'trigger_type' => 'estimate_declined',
                'conditions' => [],
                'actions' => [
                    ['type' => 'create_task', 'configuration' => ['title' => 'Review declined estimate for {{customer.name}}', 'priority' => 'high', 'due_in_hours' => 24]],
                    $notifyOwner('{{customer.name}} declined an estimate.'),
                ],
            ],
            'new_lead_sequence' => [
                'name' => 'New lead sequence',
                'description' => 'Three check-in emails to a new lead, on days 1, 3 and 7. Stops when they reply, you send an estimate, or you mark the lead won or lost.',
                'trigger_type' => 'customer_created',
                'conditions' => [
                    ['type' => 'customer_has_email', 'operator' => 'is_true', 'value' => ''],
                    ['type' => 'customer_has_estimate', 'operator' => 'is_false', 'value' => ''],
                ],
                'actions' => [
                    ['type' => 'schedule_follow_up', 'configuration' => [
                        'delay_days' => 1,
                        'subject' => 'Thanks for getting in touch with {{business.name}}',
                        'body' => "Hi {{customer.first_name}},\n\nThanks for reaching out to {{business.name}}. Do you have any questions, or would you like us to put together an estimate for you?\n\nJust reply to this email and we'll get back to you.\n\nThanks,\n{{business.name}}",
                    ]],
                    ['type' => 'schedule_follow_up', 'configuration' => [
                        'delay_days' => 3,
                        'subject' => 'Can we help with your project?',
                        'body' => "Hi {{customer.first_name}},\n\nI wanted to follow up in case my last email got buried. If you tell us a little about what you need, we can send you a free estimate.\n\nJust reply to this email.\n\nThanks,\n{{business.name}}",
                    ]],
                    ['type' => 'schedule_follow_up', 'configuration' => [
                        'delay_days' => 7,
                        'subject' => 'Still interested?',
                        'body' => "Hi {{customer.first_name}},\n\nI don't want to fill your inbox, so this is my last check-in. If you'd still like an estimate, just reply and we'll take it from there.\n\nThanks,\n{{business.name}}",
                    ]],
                ],
            ],
            'new_lead_follow_up' => [
                'name' => 'New lead follow-up',
                'description' => 'When a new customer is added without an estimate, email them the next day to check in. Stops if they reply or you send them an estimate.',
                'trigger_type' => 'customer_created',
                'conditions' => [
                    ['type' => 'customer_has_email', 'operator' => 'is_true', 'value' => ''],
                    ['type' => 'customer_has_estimate', 'operator' => 'is_false', 'value' => ''],
                ],
                'actions' => [
                    ['type' => 'schedule_follow_up', 'configuration' => [
                        'delay_days' => 1,
                        'subject' => 'Thanks for getting in touch with {{business.name}}',
                        'body' => "Hi {{customer.first_name}},\n\nThanks for reaching out to {{business.name}}. Do you have any questions, or would you like us to put together an estimate for you?\n\nJust reply to this email and we'll get back to you.\n\nThanks,\n{{business.name}}",
                    ]],
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
