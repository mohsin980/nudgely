<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\AutomationAction;
use App\Models\Organization;
use App\Services\Automation\AutomatedEmailPolicy;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use App\Services\Email\EmailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * send_email: emails the conversation's customer from the organization's verified sender.
 *
 * Off by default. It sends only when the organization turned automatic emails on and
 * approval off, the action does not require approval, and AutomatedEmailPolicy passes
 * (verified sender, valid address, no opt-out, rate limit, not already sent). Delivery
 * always goes through EmailService, never a provider directly.
 *
 * Configuration: subject and body (required; plain text with placeholders).
 */
class SendEmailAction implements AutomationActionInterface
{
    public function __construct(
        private readonly AutomatedEmailPolicy $policy,
        private readonly EmailService $email,
    ) {}

    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $config = $action->configuration ?? [];
        $subject = is_string($config['subject'] ?? null) ? trim($context->render($config['subject'])) : '';
        $body = is_string($config['body'] ?? null) ? trim($context->render($config['body'])) : '';

        if ($subject === '' || $body === '') {
            return AutomationActionResult::failed('An email subject and body are required.');
        }

        if (($blocked = $this->policy->check($action, $context)) !== null) {
            return $blocked;
        }

        $key = $context->idempotencyKey($action);

        try {
            $message = $this->email->sendToConversation(
                $context->conversation(),
                Str::limit($subject, 200, ''),
                '<p>'.nl2br(e($body)).'</p>',
                $body,
                array_filter(['type' => 'automation', 'automation_key' => $key, 'automation_action_id' => (string) $action->id]),
            );
        } catch (EmailSendingNotAllowedException $e) {
            return AutomationActionResult::failed($e->getMessage(), ['reason' => 'sender_not_allowed']);
        } catch (UniqueConstraintViolationException) {
            $existing = $key === null ? null : $this->policy->sentMessageId(Organization::findOrFail($context->organizationId), $key);

            return AutomationActionResult::skipped('Email already sent for this event.', ['reason' => 'already_exists', 'message_id' => $existing]);
        }

        $this->policy->recordSent($context->organizationId);

        return AutomationActionResult::completed('Email queued to the customer.', ['message_id' => $message->id]);
    }
}
