<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\AutomationAction;
use App\Models\Organization;
use App\Services\Automation\AutomatedEmailPolicy;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\EmailTemplateRenderer;
use App\Services\Email\EmailService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * send_email: emails the conversation's customer from the organization's verified sender.
 *
 * Off by default. It sends only when the organization turned automatic emails on and
 * approval off, the action does not require approval, and AutomatedEmailPolicy passes
 * (verified sender, valid address, no opt-out, rate limit, not already sent). Delivery
 * always goes through EmailService, never a provider directly.
 *
 * Configuration: subject (max 200) and body (max 5000), plain text with {{variables}}
 * from EmailTemplateRenderer::VARIABLES.
 */
class SendEmailAction implements AutomationActionInterface
{
    public const MAX_SUBJECT = 200;

    public const MAX_BODY = 5000;

    public function __construct(
        private readonly AutomatedEmailPolicy $policy,
        private readonly EmailService $email,
        private readonly EmailTemplateRenderer $templates,
    ) {}

    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $config = $action->configuration ?? [];
        $subject = is_string($config['subject'] ?? null) ? trim($config['subject']) : '';
        $body = is_string($config['body'] ?? null) ? trim($config['body']) : '';

        if ($subject === '' || $body === '' || mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) {
            return AutomationActionResult::failed('An email subject (up to 200 characters) and body (up to 5000) are required.');
        }

        try {
            $this->templates->validate($subject);
            $this->templates->validate($body);
        } catch (InvalidEmailTemplateException $e) {
            return AutomationActionResult::failed($e->getMessage());
        }

        if (($blocked = $this->policy->check($action, $context)) !== null) {
            return $blocked;
        }

        $organization = Organization::findOrFail($context->organizationId);
        $conversation = $context->conversation();
        $customer = $organization->customers()->findOrFail($conversation->customer_id);
        $key = $context->idempotencyKey($action);

        try {
            $text = $this->templates->render($body, $customer, $organization, $context->estimate());
            $renderedSubject = $this->templates->render($subject, $customer, $organization, $context->estimate());
        } catch (InvalidEmailTemplateException $e) {
            return AutomationActionResult::failed($e->getMessage());
        }

        try {
            $message = $this->email->sendToConversation(
                $conversation,
                $renderedSubject,
                '<p>'.nl2br(e($text)).'</p>',
                $text,
                array_filter(['type' => 'automation', 'automation_key' => $key, 'automation_action_id' => (string) $action->id]),
            );
        } catch (EmailSendingNotAllowedException $e) {
            return AutomationActionResult::failed($e->getMessage(), ['reason' => 'sender_not_allowed']);
        } catch (UniqueConstraintViolationException) {
            return AutomationActionResult::skipped('Email already sent for this event.', ['reason' => 'already_exists', 'message_id' => $key === null ? null : $this->policy->sentMessageId($organization, $key)]);
        }

        $this->policy->recordSent($organization->id);

        return AutomationActionResult::completed("Email sent to the customer from {$message->from_address}.", ['message_id' => $message->id]);
    }
}
