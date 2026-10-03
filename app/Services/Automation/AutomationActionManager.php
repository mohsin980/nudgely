<?php

namespace App\Services\Automation;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\Automation\AutomationActionType;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Services\Automation\Actions\AddCustomerTagAction;
use App\Services\Automation\Actions\CreateTaskAction;
use App\Services\Automation\Actions\NotifyUserAction;
use App\Services\Automation\Actions\ScheduleFollowUpAction;
use App\Services\Automation\Actions\SendEmailAction;
use App\Services\Automation\Actions\UpdateConversationStatusAction;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Maps each controlled action type to its handler and runs it with a tenant check.
 */
class AutomationActionManager
{
    /**
     * @var array<string, class-string<AutomationActionInterface>>
     */
    private const HANDLERS = [
        'create_task' => CreateTaskAction::class,
        'schedule_follow_up' => ScheduleFollowUpAction::class,
        'send_email' => SendEmailAction::class,
        'add_customer_tag' => AddCustomerTagAction::class,
        'update_conversation_status' => UpdateConversationStatusAction::class,
        'notify_user' => NotifyUserAction::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function handlerFor(AutomationActionType $type): AutomationActionInterface
    {
        return $this->container->make(self::HANDLERS[$type->value]);
    }

    /**
     * Run one action for an event. Never throws: every outcome is a result.
     */
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        // Raw value: an unknown stored type is reported, not crashed on.
        $type = AutomationActionType::tryFrom((string) ($action->getAttributes()['type'] ?? ''));

        if ($type === null || ! isset(self::HANDLERS[$type->value])) {
            return AutomationActionResult::failed('Unsupported action.');
        }

        $organizationId = Automation::query()->whereKey($action->automation_id)->value('organization_id');

        if ($organizationId === null || $organizationId !== $context->organizationId) {
            Log::warning('Automation action rejected: organization mismatch.', ['automation_action_id' => $action->id]);

            return AutomationActionResult::failed('This action does not belong to the organization that triggered it.');
        }

        try {
            return $this->handlerFor($type)->execute($action, $context);
        } catch (Throwable $e) {
            Log::error('Automation action failed unexpectedly.', [
                'automation_action_id' => $action->id,
                'action_type' => $type->value,
                'exception' => $e::class,
            ]);

            return AutomationActionResult::failed('The action failed unexpectedly.');
        }
    }
}
