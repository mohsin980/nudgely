<?php

namespace App\Enums;

enum MessageChannel: string
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
