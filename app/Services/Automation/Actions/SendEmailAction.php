<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\MessageChannel;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\WhatsApp\WhatsAppSendException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Organization;
use App\Services\Automation\AutomatedEmailPolicy;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\EmailTemplateRenderer;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\Team\TeamDirectory;
use App\Services\WhatsApp\WhatsAppMessenger;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * send_email ("Send message"): messages the conversation's customer by email (from the organization's verified
 * sender), WhatsApp, or both: configuration "channel" is email, whatsapp, both, or customer (their own channel).
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
        private readonly WhatsAppMessenger $whatsapp,
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

        if (($blocked = $this->policy->checkAllowed($action, $context)) !== null) {
            return $blocked;
        }

        $organization = Organization::findOrFail($context->organizationId);
        $conversation = $context->conversation();

        if ($conversation === null) {
            return AutomationActionResult::failed('A message to the customer needs their conversation.');
        }

        $customer = $organization->customers()->findOrFail($conversation->customer_id);
        [$byWhatsApp, $byEmail] = $this->channels((string) ($config['channel'] ?? 'email'), $customer, $organization);

        $whatsApp = $byWhatsApp ? $this->sendWhatsApp($action, $context, $organization, $conversation, $customer, $body) : null;
        $email = $byEmail ? $this->sendEmail($action, $context, $organization, $conversation, $customer, $subject, $body) : null;

        return match (true) {
            $whatsApp === null => $email,
            $email === null => $whatsApp,
            $whatsApp->status === AutomationActionRunStatus::Completed && $email->status === AutomationActionRunStatus::Completed => AutomationActionResult::completed('Sent to the customer by email and WhatsApp.', $email->data + ['whatsapp_message_id' => $whatsApp->data['message_id'] ?? null]),
            $email->status === AutomationActionRunStatus::Completed => AutomationActionResult::completed($email->message.' WhatsApp: '.$whatsApp->message, $email->data),
            default => $whatsApp->status === AutomationActionRunStatus::Completed ? AutomationActionResult::completed($whatsApp->message.' Email: '.$email->message, $whatsApp->data) : $email,
        };
    }

    /**
     * Which channels to use, as [WhatsApp, email]. "customer": their preference (FollowUpProcessor::channelFor), by
     * email when WhatsApp can't reach them right now. Older automations without a choice send email.
     *
     * @return array{0: bool, 1: bool}
     */
    private function channels(string $channel, $customer, Organization $organization): array
    {
        return match ($channel) {
            'whatsapp' => [true, false],
            'both' => [$this->whatsapp->unavailableReason($customer) === null, true],
            'customer' => app(FollowUpProcessor::class)->channelFor($customer, $organization) === MessageChannel::WhatsApp
                && ($this->whatsapp->canSendAutomated($customer) || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) ? [true, false] : [false, true],
            default => [false, true],
        };
    }

    private function sendWhatsApp(AutomationAction $action, AutomationContext $context, Organization $organization, $conversation, $customer, string $body): AutomationActionResult
    {
        $key = $context->idempotencyKey($action);
        $key = $key === null ? null : $key.'|whatsapp';

        if ($key !== null && ($messageId = $this->policy->sentMessageId($organization, $key)) !== null) {
            return AutomationActionResult::skipped('WhatsApp message already sent for this event.', ['reason' => 'already_exists', 'message_id' => $messageId]);
        }

        if (! $customer->isActive()) {
            return AutomationActionResult::skipped('Not sent: the customer is marked inactive.', ['reason' => 'inactive']);
        }

        if ($this->policy->rateLimited($organization->id)) {
            return AutomationActionResult::skipped('Not sent: the hourly limit for automatic messages was reached.', ['reason' => 'rate_limited']);
        }

        try {
            $message = $this->whatsapp->sendAutomated($conversation, $context->renderTemplate($body),
                array_filter(['type' => 'automation', 'automation_key' => $key, 'automation_action_id' => (string) $action->id]));
        } catch (InvalidEmailTemplateException|WhatsAppSendException $e) {
            return AutomationActionResult::failed($e->getMessage(), ['reason' => 'whatsapp_not_sent']);
        }

        $this->policy->recordSent($organization->id);

        return AutomationActionResult::completed('WhatsApp message sent to the customer.', ['message_id' => $message->id]);
    }

    private function sendEmail(AutomationAction $action, AutomationContext $context, Organization $organization, $conversation, $customer, string $subject, string $body): AutomationActionResult
    {
        $key = $context->idempotencyKey($action);

        if (($blocked = $this->policy->checkDelivery($organization, $conversation, $customer, $key)) !== null) {
            return $blocked;
        }

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
        // The creator while active, else the default automation owner, else the business owner.
        $owner = $automation === null ? app(TeamDirectory::class)->owner($organization->id) : app(TeamDirectory::class)->automationOwner($automation);

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
