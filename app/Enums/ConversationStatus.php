<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case WaitingCustomer = 'waiting_customer';
    case WaitingBusiness = 'waiting_business';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::WaitingCustomer => 'Waiting on customer',
            self::WaitingBusiness => 'Waiting on business',
            self::Closed => 'Closed',
        };
    }
}
