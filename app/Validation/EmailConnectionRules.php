<?php

namespace App\Validation;

use App\Enums\EmailProvider;
use App\Rules\DomainName;
use App\Rules\SenderEmailMatchesDomain;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules for creating or updating an email connection,
 * usable from Livewire components and form requests alike.
 */
class EmailConnectionRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?string $domain): array
    {
        return [
            'provider' => ['required', Rule::enum(EmailProvider::class)],
            'domain' => ['required', 'string', 'max:253', new DomainName],
            'sender_email' => ['required', 'string', 'max:254', 'email:rfc', new SenderEmailMatchesDomain($domain)],
            'sender_name' => ['required', 'string', 'max:255'],
        ];
    }
}
