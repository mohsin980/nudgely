<?php

namespace App\Jobs\Billing;

use App\Enums\Team\NotificationType;
use App\Models\Organization;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the organization's owner about a billing event, in the app and/or by email as their
 * preferences allow. Queued so webhooks and page requests don't wait for email; safe to retry
 * because TeamNotifier notifies once per $key.
 */
class NotifyOwnerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $organizationId,
        public readonly string $type,
        public readonly string $message,
        public readonly string $url,
        public readonly string $key,
        public readonly ?string $action = null,
    ) {}

    public function handle(TeamDirectory $team, TeamNotifier $notifier): void
    {
        $organization = Organization::query()->find($this->organizationId);
        $owner = $organization === null ? null : $team->owner($organization->id);

        if ($organization !== null && $owner !== null) {
            $notifier->notify($organization, NotificationType::from($this->type), [$owner], $this->message, $this->url, $this->key, data: array_filter(['action' => $this->action]));
        }
    }
}
