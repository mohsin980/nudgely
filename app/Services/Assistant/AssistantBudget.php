<?php

namespace App\Services\Assistant;

use App\Models\Organization;
use App\Services\Billing\EntitlementService;
use App\Services\Team\ActivityNotifications;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many AI assistant replies a business may get in one hour, by plan. A busy hour then can't flood the AI
 * provider or run costs up; when the hour's budget is used, the chat goes to a person (see WhatsAppAssistant).
 */
class AssistantBudget
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ActivityNotifications $notifications,
    ) {}

    /**
     * Use one reply from this hour's budget. False when the budget is used up.
     */
    public function take(Organization $organization): bool
    {
        $limit = $this->limit($organization);
        $key = 'ai-budget:'.$organization->id.':'.now()->format('YmdH');

        if (! RateLimiter::attempt($key, $limit, fn () => true, 3600)) {
            $this->notifications->aiLimit($organization, "The AI assistant has used this hour's {$limit} replies, so new chats go to your team until the hour resets.", 'ai-budget:'.$organization->id.':'.now()->format('YmdH').':100');

            return false;
        }

        $warnAt = (int) ceil($limit * $this->warnPercent() / 100);

        if (RateLimiter::attempts($key) === $warnAt) {
            $this->notifications->aiLimit($organization, "The AI assistant has used {$this->warnPercent()}% of this hour's {$limit} replies.", 'ai-budget:'.$organization->id.':'.now()->format('YmdH').':'.$this->warnPercent());
        }

        return true;
    }

    public function limit(Organization $organization): int
    {
        $plan = $this->entitlements->plan($organization)->key;

        return (int) (config('ai.hourly_replies.'.$plan) ?? config('ai.hourly_replies.free'));
    }

    private function warnPercent(): int
    {
        return (int) config('ai.hourly_warn_percent', 80);
    }
}
