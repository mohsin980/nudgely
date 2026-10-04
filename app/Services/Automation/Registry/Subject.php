<?php

namespace App\Services\Automation\Registry;

/**
 * A record an automation run is guaranteed to have, given its trigger. Conditions, actions
 * and template variables declare which subjects they need; the builder only offers (and
 * accepts) what the chosen trigger provides.
 */
enum Subject: string
{
    case Customer = 'customer';
    case Conversation = 'conversation';
    case Message = 'message';
    case Classification = 'classification';
    case Estimate = 'estimate';
    case FollowUp = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'customer',
            self::Conversation => 'conversation',
            self::Message => 'customer reply',
            self::Classification => 'AI classification',
            self::Estimate => 'estimate',
            self::FollowUp => 'follow-up',
        };
    }
}
