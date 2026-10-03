<?php

namespace App\Services\Automation;

use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Customer;
use App\Models\Organization;

/**
 * Fills {{variable}} placeholders in automated email subjects and bodies.
 *
 * Only the variables in VARIABLES can be used. Anything else in double braces, such as
 * {{php_code}} or {{customer.password}}, is rejected when the template is validated.
 * Rendering is plain string replacement: nothing in a template is ever evaluated.
 */
class EmailTemplateRenderer
{
    /**
     * Variables whose data exists today, with a label for the builder.
     */
    public const VARIABLES = [
        'customer.first_name' => 'Customer first name',
        'customer.last_name' => 'Customer last name',
        'customer.email' => 'Customer email',
        'business.name' => 'Business name',
    ];

    /**
     * Known variables that can't be used yet because the data doesn't exist in QuoteFlow.
     */
    public const UNAVAILABLE = [
        'business.phone' => 'Business phone numbers are not stored yet.',
        'estimate.number' => 'Estimates are not available yet.',
        'estimate.total' => 'Estimates are not available yet.',
    ];

    private const PLACEHOLDER = '/\{\{\s*([^{}]*?)\s*\}\}/';

    /**
     * @throws InvalidEmailTemplateException
     */
    public function validate(string $template): void
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        foreach ($matches[1] as $variable) {
            if (isset(self::UNAVAILABLE[$variable])) {
                throw InvalidEmailTemplateException::unavailable($variable, self::UNAVAILABLE[$variable]);
            }

            if (! isset(self::VARIABLES[$variable])) {
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
     * @throws InvalidEmailTemplateException
     */
    public function render(string $template, Customer $customer, Organization $organization): string
    {
        $this->validate($template);

        $values = $this->values($customer, $organization);

        return preg_replace_callback(self::PLACEHOLDER, fn (array $match) => $values[$match[1]], $template);
    }

    /**
     * @return array<string, string>
     */
    private function values(Customer $customer, Organization $organization): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $customer->name));
        $firstName = strtok($name, ' ') ?: '';

        return [
            'customer.first_name' => $firstName !== '' ? $firstName : 'there',
            'customer.last_name' => trim(mb_substr($name, mb_strlen($firstName))),
            'customer.email' => $customer->email,
            'business.name' => $organization->name,
        ];
    }
}
