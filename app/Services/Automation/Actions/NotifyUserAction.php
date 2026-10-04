<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\OrganizationRole;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\AutomationAction;
use App\Models\User;
use App\Notifications\AutomationNotification;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * notify_user: in-app notification for people at the business.
 *
 * Configuration: message (required; placeholders allowed), recipients ("admins" by default,
 * "members", or a user ID in the same organization), channel ("in_app" only for now).
 * Each recipient gets one notification per action and event (deterministic notification ID).
 */
class NotifyUserAction implements AutomationActionInterface
{
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $config = $action->configuration ?? [];

        if (($config['channel'] ?? 'in_app') !== 'in_app') {
            return AutomationActionResult::failed('Only in-app notifications are supported.');
        }

        try {
            $message = is_string($config['message'] ?? null) ? trim($context->render($config['message'])) : '';
        } catch (InvalidEmailTemplateException $e) {
            return AutomationActionResult::failed($e->getMessage());
        }

        if ($message === '') {
            return AutomationActionResult::failed('A notification message is required.');
        }

        $recipients = $this->recipients($config['recipients'] ?? 'admins', $context->organizationId);
        if ($recipients === null) {
            return AutomationActionResult::failed('The recipient is not a member of this organization.');
        }

        if ($recipients->isEmpty()) {
            return AutomationActionResult::skipped('No one to notify.', ['reason' => 'no_recipients']);
        }

        $conversation = $context->conversation();
        $key = $context->idempotencyKey($action);
        $sent = 0;

        foreach ($recipients as $user) {
            $notification = new AutomationNotification(
                message: Str::limit($message, 255, '…'),
                // The most useful page for the event: the conversation, else the estimate or customer.
                url: match (true) {
                    $conversation !== null => route('inbox.show', $conversation->id),
                    $context->estimateId !== null => route('estimates.show', $context->estimateId),
                    $context->customer() !== null => route('customers.show', $context->customer()->id),
                    default => null,
                },
                conversationId: $conversation?->id,
                automationId: $action->automation_id,
                automationActionId: $action->id,
            );
            $notification->id = $key === null ? (string) Str::uuid() : Uuid::uuid5(Uuid::NAMESPACE_URL, "{$key}|{$user->id}")->toString();

            if (DB::table('notifications')->where('id', $notification->id)->exists()) {
                continue;
            }

            try {
                DB::transaction(fn () => $user->notifyNow($notification));
                $sent++;
            } catch (UniqueConstraintViolationException) {
                // A concurrent retry delivered it first.
            }
        }

        return $sent === 0
            ? AutomationActionResult::skipped('Already notified.', ['reason' => 'already_exists'])
            : AutomationActionResult::completed('Notified '.$sent.' '.Str::plural('person', $sent).'.', ['notified' => $sent]);
    }

    /**
     * @return Collection<int, User>|null Null when a specific user is outside the organization.
     */
    private function recipients(mixed $recipients, int $organizationId): ?Collection
    {
        $query = User::query()->where('organization_id', $organizationId)->orderBy('id');

        return match (true) {
            $recipients === 'admins' => $query->where('role', OrganizationRole::Admin)->get(),
            $recipients === 'members' => $query->get(),
            is_int($recipients) => ($user = $query->whereKey($recipients)->first()) ? collect([$user]) : null,
            default => null,
        };
    }
}
