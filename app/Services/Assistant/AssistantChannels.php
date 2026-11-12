<?php

namespace App\Services\Assistant;

use App\Models\AssistantProfile;
use App\Models\Organization;
use App\Services\Billing\EntitlementService;

/**
 * Which channels the AI assistant may reply on. A channel is used only when the business has turned it on AND has
 * bought it: WhatsApp is included with the assistant; email replies need the email channel (feature `assistant_email`).
 * A business that bought only WhatsApp never gets an AI email, even if an email address is on file.
 */
final class AssistantChannels
{
    public const WHATSAPP = 'whatsapp';

    public const EMAIL = 'email';

    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * The channels the assistant can reply on for this business right now.
     *
     * @return list<string>
     */
    public function enabled(Organization $organization, AssistantProfile $profile): array
    {
        $turnedOn = $profile->channels ?? [self::WHATSAPP];

        return array_values(array_filter(
            [self::WHATSAPP, self::EMAIL],
            fn (string $channel) => in_array($channel, $turnedOn, true) && $this->bought($organization, $channel),
        ));
    }

    public function allows(Organization $organization, AssistantProfile $profile, string $channel): bool
    {
        return in_array($channel, $this->enabled($organization, $profile), true);
    }

    private function bought(Organization $organization, string $channel): bool
    {
        return match ($channel) {
            self::WHATSAPP => true,
            self::EMAIL => $this->entitlements->hasFeature($organization, 'assistant_email'),
            default => false,
        };
    }
}
