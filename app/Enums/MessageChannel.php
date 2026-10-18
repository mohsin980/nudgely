<?php

namespace App\Enums;

enum MessageChannel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
}
