<?php

namespace App\Services\Automation;

use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every check an automated email must pass before it is handed to EmailService.
 *
 * Returns the reason the email must not be sent, or null when it may be sent. Checks run
 * from the business's own settings down to this customer and this event.
 */
class AutomatedEmailPolicy
{
    public function __construct(private readonly EmailService $email) {}

    public function check(AutomationAction $action, AutomationContext $context): ?AutomationActionResult
    {
        $organization = Organization::find($context->organizationId);

        if ($organization === null) {
            return AutomationActionResult::failed('The organization no longer exists.');
        }

        if (! $organization->automations_enabled || ! $organization->automatic_email_enabled) {
            return AutomationActionResult::skipped('Automatic emails are turned off for this organization.', ['reason' => 'automatic_email_disabled']);
        }

        if ($organization->require_approval_for_email || $action->requires_approval) {
            return AutomationActionResult::skipped('Email not sent: it requires approval.', ['reason' => 'approval_required']);
        }

        $automation = Automation::query()->forOrganization($organization)->find($action->automation_id);

        if ($automation === null || ! $automation->isActive()) {
            return AutomationActionResult::skipped('The automation is no longer active.', ['reason' => 'automation_inactive']);
        }

        $conversation = $context->conversation();
        $customer = $context->customer() ?? ($conversation === null ? null : $organization->customers()->find($conversation->customer_id));

        return $this->checkDelivery($organization, $conversation, $customer, $context->idempotencyKey($action));
    }

    /**
     * The recipient, sender, duplicate and rate-limit checks shared by every automated email.
     */
    public function checkDelivery(Organization $organization, ?Conversation $conversation, ?Customer $customer, ?string $key): ?AutomationActionResult
    {
        if ($conversation === null || $customer === null
            || $conversation->organization_id !== $organization->id
            || $customer->organization_id !== $organization->id
            || $conversation->customer_id !== $customer->id) {
            return AutomationActionResult::failed('An automated email needs a conversation and customer in this organization.');
        }

        if (! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            return AutomationActionResult::failed('The customer does not have a valid email address.');
        }

        if ($customer->hasOptedOutOfEmail()) {
            return AutomationActionResult::skipped('Email not sent: the customer opted out of email.', ['reason' => 'opted_out']);
        }

        try {
            $this->email->assertCanSendFrom($organization->emailConnections()->default()->first(), $organization->id);
        } catch (EmailSendingNotAllowedException $e) {
            return AutomationActionResult::failed($e->getMessage(), ['reason' => 'sender_not_allowed']);
        }

        if ($key !== null && ($messageId = $this->sentMessageId($organization, $key)) !== null) {
            return AutomationActionResult::skipped('Email already sent for this event.', ['reason' => 'already_exists', 'message_id' => $messageId]);
        }

        if (RateLimiter::tooManyAttempts(self::rateLimitKey($organization->id), $this->hourlyLimit())) {
            return AutomationActionResult::skipped('Email not sent: the hourly limit for automated emails was reached.', ['reason' => 'rate_limited']);
        }

        return null;
    }

    /**
     * Count one automated email against the organization's hourly limit.
     */
    public function recordSent(int $organizationId): void
    {
        RateLimiter::hit(self::rateLimitKey($organizationId), 3600);
    }

    public function sentMessageId(Organization $organization, string $key): ?int
    {
        return Message::query()
            ->where('organization_id', $organization->id)
            ->where('metadata->automation_key', $key)
            ->value('id');
    }

    public static function rateLimitKey(int $organizationId): string
    {
        return "automation-email:{$organizationId}";
    }

    private function hourlyLimit(): int
    {
        return (int) config('automation.limits.max_automated_emails_per_hour');
    }
}
