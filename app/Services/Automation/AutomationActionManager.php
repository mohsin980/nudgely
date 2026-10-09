<?php

namespace App\Services\Automation;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\Automation\AutomationActionType;
use App\Exceptions\Automation\TransientAutomationException;
use App\Exceptions\Email\EmailProviderException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Services\Automation\Actions\AddCustomerTagAction;
use App\Services\Automation\Actions\CancelFollowUpAction;
use App\Services\Automation\Actions\CompleteFollowUpAction;
use App\Services\Automation\Actions\CreateTaskAction;
use App\Services\Automation\Actions\NotifyUserAction;
use App\Services\Automation\Actions\RemoveCustomerTagAction;
use App\Services\Automation\Actions\ScheduleFollowUpAction;
use App\Services\Automation\Actions\SendEmailAction;
use App\Services\Automation\Actions\UpdateConversationStatusAction;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionException;
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
        'remove_customer_tag' => RemoveCustomerTagAction::class,
        'complete_follow_up' => CompleteFollowUpAction::class,
        'cancel_follow_up' => CancelFollowUpAction::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function handlerFor(AutomationActionType $type): AutomationActionInterface
    {
        return $this->container->make(self::HANDLERS[$type->value]);
    }

    /**
     * Run one action for an event. Never throws: every outcome is a result. Temporary
     * failures are marked with data.retryable so the engine can retry them.
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
            $retryable = $this->isTransient($e);

            Log::error('Automation action failed unexpectedly.', [
                'automation_action_id' => $action->id,
                'action_type' => $type->value,
                'exception' => $e::class,
                'retryable' => $retryable,
            ]);

            return $retryable
                ? AutomationActionResult::failed('The action failed temporarily.', ['retryable' => true])
                : AutomationActionResult::failed('The action failed unexpectedly.');
        }
    }

    /**
     * Temporary infrastructure problems are worth retrying; everything else is permanent.
     */
    private function isTransient(Throwable $e): bool
    {
        return $e instanceof TransientAutomationException
            || $e instanceof DeadlockException
            || $e instanceof LostConnectionException
            || ($e instanceof EmailProviderException && $e->isTransient());
    }
}
