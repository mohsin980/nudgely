<?php

namespace App\Services\Automation\Registry;

/**
 * The only {{variables}} templates may use. Values are filled by EmailTemplateRenderer with
 * plain text replacement; nothing in a template is evaluated.
 */
final class VariableRegistry
{
    /**
     * Known variables that can't be used yet because the data doesn't exist.
     */
    public const UNAVAILABLE = [
        'business.phone' => 'Business phone numbers are not stored yet.',
    ];

    /** @var array<string, VariableDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, VariableDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= collect([
            new VariableDefinition('customer.first_name', 'Customer first name', Subject::Customer, 'John'),
            new VariableDefinition('customer.last_name', 'Customer last name', Subject::Customer, 'Smith'),
            new VariableDefinition('customer.name', 'Customer full name', Subject::Customer, 'John Smith'),
            new VariableDefinition('customer.email', 'Customer email', Subject::Customer, 'john@example.com'),
            new VariableDefinition('business.name', 'Business name', null, 'Dallas HVAC'),
            new VariableDefinition('conversation.subject', 'Conversation subject', Subject::Conversation, 'Your AC estimate'),
            new VariableDefinition('estimate.number', 'Estimate number', Subject::Estimate, 'EST-1024'),
            new VariableDefinition('estimate.title', 'Estimate title', Subject::Estimate, 'AC Installation'),
            new VariableDefinition('estimate.total', 'Estimate total', Subject::Estimate, '$2,750.00'),
            new VariableDefinition('estimate.valid_until', 'Estimate valid until', Subject::Estimate, 'November 2, 2026'),
            new VariableDefinition('follow_up.due_at', 'Follow-up due date', Subject::FollowUp, 'October 6, 2026'),
        ])->keyBy('key')->all();
    }

    public static function find(string $key): ?VariableDefinition
    {
        return self::all()[$key] ?? null;
    }
}
