<?php

namespace App\Services\Automation;

use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Services\Automation\Registry\TriggerDefinition;
use App\Services\Automation\Registry\VariableRegistry;

/**
 * Fills {{variable}} placeholders in automation templates (emails, tasks, notifications).
 *
 * Only variables in VariableRegistry can be used. Anything else in double braces, such as
 * {{php_code}} or {{customer.password}}, is rejected when the template is validated.
 * validateFor() also rejects variables the trigger can't provide, and render() refuses to
 * send a template whose data is missing instead of leaving a blank. Rendering is plain
 * string replacement: nothing in a template is ever evaluated.
 */
class EmailTemplateRenderer
{
    private const PLACEHOLDER = '/\{\{\s*([^{}]*?)\s*\}\}/';

    /**
     * @throws InvalidEmailTemplateException
     */
    public function validate(string $template): void
    {
        foreach ($this->variablesIn($template) as $variable) {
            if (isset(VariableRegistry::UNAVAILABLE[$variable])) {
                throw InvalidEmailTemplateException::unavailable($variable, VariableRegistry::UNAVAILABLE[$variable]);
            }

            if (VariableRegistry::find($variable) === null) {
                throw InvalidEmailTemplateException::unsupported($variable);
            }
        }

        // Leftover braces mean a malformed placeholder such as "{{customer.first_name}" or "{{ {{x}} }}".
        $remainder = preg_replace(self::PLACEHOLDER, '', $template);

        if (str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
            throw InvalidEmailTemplateException::malformed();
        }
    }

    /**
     * validate(), plus: every variable must be available for the trigger.
     *
     * @throws InvalidEmailTemplateException
     */
    public function validateFor(string $template, ?TriggerDefinition $trigger): void
    {
        $this->validate($template);

        if ($trigger === null) {
            return;
        }

        foreach ($this->variablesIn($template) as $variable) {
            $subject = VariableRegistry::find($variable)?->subject;

            if ($subject !== null && ! $trigger->provides($subject)) {
                throw InvalidEmailTemplateException::notForTrigger($variable, $trigger->label);
            }
        }
    }

    /**
     * @throws InvalidEmailTemplateException when the template is invalid or its data is missing
     */
    public function render(string $template, ?Customer $customer, Organization $organization, ?Estimate $estimate = null, ?FollowUp $followUp = null, ?Conversation $conversation = null): string
    {
        $this->validate($template);

        $values = $this->values($customer, $organization, $estimate, $followUp, $conversation);

        foreach ($this->variablesIn($template) as $variable) {
            if (($values[$variable] ?? null) === null) {
                throw $variable === 'estimate.number' || str_starts_with($variable, 'estimate.')
                    ? InvalidEmailTemplateException::missingEstimate()
                    : InvalidEmailTemplateException::missing($variable);
            }
        }

        return preg_replace_callback(self::PLACEHOLDER, fn (array $match) => $values[$match[1]], $template);
    }

    public function usesEstimate(string $template): bool
    {
        return collect($this->variablesIn($template))->contains(fn (string $variable) => str_starts_with($variable, 'estimate.'));
    }

    /**
     * @return list<string>
     */
    public function variablesIn(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, ?string> Null = the data isn't available for this event.
     */
    private function values(?Customer $customer, Organization $organization, ?Estimate $estimate, ?FollowUp $followUp, ?Conversation $conversation): array
    {
        $name = $customer === null ? null : trim(preg_replace('/\s+/', ' ', $customer->name));
        $firstName = $name === null ? null : (strtok($name, ' ') ?: '');

        return [
            'customer.first_name' => $customer === null ? null : ($firstName !== '' ? $firstName : 'there'),
            'customer.last_name' => $customer === null ? null : trim(mb_substr($name, mb_strlen($firstName))),
            'customer.name' => $name,
            'customer.email' => $customer?->email,
            'business.name' => $organization->name,
            'conversation.subject' => $conversation === null ? null : ($conversation->subject ?? 'your inquiry'),
            'estimate.number' => $estimate?->displayNumber(),
            'estimate.title' => $estimate?->title,
            'estimate.total' => $estimate?->money('total'),
            'estimate.valid_until' => $estimate === null ? null : ($estimate->valid_until?->format('F j, Y') ?? 'no expiry date'),
            'follow_up.due_at' => $followUp === null ? null : $organization->localTime($followUp->due_at)->format('F j, Y'),
        ];
    }
}
