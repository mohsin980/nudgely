<?php

namespace App\Services\Outreach;

use App\Enums\Outreach\OutreachChannel;
use App\Enums\Outreach\OutreachMessageStatus;
use App\Models\OutreachContact;
use App\Models\OutreachMessage;
use App\Models\OutreachTemplate;

/**
 * Decides whether one contact may receive one message on one channel. Returns the reason it may not, or null.
 * Checked when a campaign starts and again just before each send, because consent or an opt-out can change in between.
 */
class OutreachEligibility
{
    public const NO_CONSENT = 'no_consent';

    public const OPTED_OUT = 'opted_out';

    public const NO_ADDRESS = 'no_address';

    public const TEMPLATE_NOT_APPROVED = 'template_not_approved';

    public const RECENTLY_CONTACTED = 'recently_contacted';

    public function reason(OutreachContact $contact, OutreachChannel $channel, OutreachTemplate $template): ?string
    {
        if ($template->channel !== $channel) {
            return self::TEMPLATE_NOT_APPROVED; // a template for another channel can't be used here
        }

        if (! $template->isApproved()) {
            return self::TEMPLATE_NOT_APPROVED;
        }

        if (! $contact->hasConsent()) {
            return self::NO_CONSENT;
        }

        $optedOut = $channel === OutreachChannel::Email ? $contact->email_opted_out_at : $contact->whatsapp_opted_out_at;

        if ($optedOut !== null) {
            return self::OPTED_OUT;
        }

        $address = $channel === OutreachChannel::Email ? $contact->email : $contact->phone;

        if (blank($address)) {
            return self::NO_ADDRESS;
        }

        $recently = OutreachMessage::query()
            ->where('outreach_contact_id', $contact->id)
            ->where('channel', $channel)
            ->where('status', OutreachMessageStatus::Sent)
            ->where('sent_at', '>=', now()->subDays((int) config('outreach.recontact_days')))
            ->exists();

        return $recently ? self::RECENTLY_CONTACTED : null;
    }

    /** A plain-language label for a skip reason, for the logs and the campaign screen. */
    public static function label(?string $reason): string
    {
        return match ($reason) {
            self::NO_CONSENT => 'No consent recorded',
            self::OPTED_OUT => 'Opted out',
            self::NO_ADDRESS => 'No address for this channel',
            self::TEMPLATE_NOT_APPROVED => 'Template not approved',
            self::RECENTLY_CONTACTED => 'Messaged recently',
            default => 'Skipped',
        };
    }
}
