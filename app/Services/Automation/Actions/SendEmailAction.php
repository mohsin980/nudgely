<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\OrganizationRole;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Organization;
use App\Models\User;
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
 * from VariableRegistry. With recipient "owner", it emails the automation's owner instead.
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

        if (($config['recipient'] ?? 'customer') === 'owner') {
            return $this->emailOwner($action, $context, $subject, $body);
        }

        if (($blocked = $this->policy->check($action, $context)) !== null) {
            return $blocked;
        }

        $organization = Organization::findOrFail($context->organizationId);
        $conversation = $context->conversation();
        $customer = $organization->customers()->findOrFail($conversation->customer_id);
        $key = $context->idempotencyKey($action);

        try {
            $text = $context->renderTemplate($body);
            $renderedSubject = $context->renderTemplate($subject);
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

    /**
     * An email to the business itself (the automation's owner): not customer-facing, so no
     * approval or opt-out applies, but it still goes through EmailService from the verified
     * sender, once per action and event.
     */
    private function emailOwner(AutomationAction $action, AutomationContext $context, string $subject, string $body): AutomationActionResult
    {
        $organization = Organization::findOrFail($context->organizationId);
        $automation = Automation::query()->forOrganization($organization)->find($action->automation_id);
        $owner = $automation?->created_by === null ? null : User::query()->where('organization_id', $organization->id)->find($automation->created_by);
        $owner ??= User::query()->where('organization_id', $organization->id)->where('role', OrganizationRole::Admin)->orderBy('id')->first();

        if ($owner === null || ! filter_var($owner->email, FILTER_VALIDATE_EMAIL)) {
            return AutomationActionResult::failed('There is no one at the business to email.');
        }

        $key = $context->idempotencyKey($action);

        if ($key !== null && ($messageId = $this->policy->sentMessageId($organization, $key)) !== null) {
            return AutomationActionResult::skipped('Email already sent for this event.', ['reason' => 'already_exists', 'message_id' => $messageId]);
        }

        try {
            $text = $context->renderTemplate($body);
            $message = $this->email->send($organization, $owner->email, $context->renderTemplate($subject), '<p>'.nl2br(e($text)).'</p>', $text,
                toName: $owner->name, metadata: array_filter(['type' => 'automation_owner', 'automation_key' => $key, 'automation_action_id' => (string) $action->id]));
        } catch (InvalidEmailTemplateException $e) {
            return AutomationActionResult::failed($e->getMessage());
        } catch (EmailSendingNotAllowedException $e) {
            return AutomationActionResult::failed($e->getMessage(), ['reason' => 'sender_not_allowed']);
        } catch (UniqueConstraintViolationException) {
            return AutomationActionResult::skipped('Email already sent for this event.', ['reason' => 'already_exists']);
        }

        return AutomationActionResult::completed("Email sent to {$owner->name}.", ['message_id' => $message->id]);
    }
}
