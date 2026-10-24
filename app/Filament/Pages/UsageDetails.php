<?php

namespace App\Filament\Pages;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\Organization;
use App\Services\Platform\UsageMonitor;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * One organization's usage in detail: this plan period, the last 30 days and lifetime. Opened from the usage list.
 */
class UsageDetails extends Page
{
    use RequiresPlatformPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'usage/{organizationId}';

    protected string $view = 'filament.admin.usage-page';

    #[Locked]
    public int $organizationId;

    /** @var array<string, mixed> */
    #[Locked]
    public array $row = [];

    /** @var array<string, array<string, int|null>> */
    #[Locked]
    public array $activity = [];

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewUsage;
    }

    public function mount(int|string $organizationId): void
    {
        $organization = Organization::query()->findOrFail((int) $organizationId);
        $monitor = app(UsageMonitor::class);

        $this->organizationId = $organization->id;
        $this->row = $monitor->rows(collect([$organization]))[$organization->id];
        $this->activity = $monitor->activity($organization);
    }

    public function getTitle(): string
    {
        return ($this->row['name'] ?? 'Organization').': usage';
    }
}
