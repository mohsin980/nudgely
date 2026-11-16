<?php

namespace App\Services\Outreach;

use App\Models\OutreachContact;

/**
 * Fills the only placeholders templates may use. Plain text substitution: nothing is evaluated.
 */
class OutreachRenderer
{
    public function render(string $text, OutreachContact $contact): string
    {
        $firstName = trim(explode(' ', (string) $contact->name)[0] ?? '') ?: 'there';

        return strtr($text, [
            '{{first_name}}' => $firstName,
            '{{name}}' => filled($contact->name) ? $contact->name : 'there',
        ]);
    }

    /** @return list<string> */
    public static function placeholders(): array
    {
        return ['{{first_name}}', '{{name}}'];
    }
}
