<?php

namespace App\Enums\Outreach;

enum OutreachChannel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
