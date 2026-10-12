<?php

namespace App\Filament\Pages;

use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\Organization;
use App\Services\Platform\UsageMonitor;
use App\Services\Reliability\OperationsReport;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

/**
 * Usage against plan limits for every organization, plus a glance at background-work pressure. Read-only: limits
 * are enforced by the backend services (EntitlementService), this page only reports the same numbers.
 */
class UsageMonitoring extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Usage and quotas';

    protected static ?string $slug = 'usage';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewUsage;
    }

    public function getSubheading(): ?string
    {
        return 'Current-period usage against each organization\'s plan limits. Customers, automations and seats are counted as they stand now; emails and estimates are counted inside the organization\'s billing period (its calendar month, in its own timezone, without a subscription).';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Platform capacity')
                ->description('Background work right now. Counts only; no message content or job payloads.')
                ->collapsible()
                ->schema([Text::make(fn () => $this->capacityText())]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters, int|string $page, int|string $recordsPerPage, ?string $search, ?string $sortColumn, ?string $sortDirection) {
                return app(UsageMonitor::class)->list(
                    ['plan' => $filters['plan']['value'] ?? null, 'state' => $filters['state']['value'] ?? null],
                    $search, (string) $sortColumn, (string) ($sortDirection ?: 'asc'), (int) $page, (int) $recordsPerPage,
                );
            })
            ->recordTitleAttribute('name')
            ->searchable()
            ->searchPlaceholder('Search name or ID')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('name')->label('Organization')->sortable()->description(fn (array $record) => 'ID '.$record['id']),
                TextColumn::make('plan')->label('Plan')->description(fn (array $record) => $record['subscription_status'] ?? 'No subscription'),
                TextColumn::make('state')->label('Quota status')->badge()
                    ->state(fn (array $record) => $record['state']->label())
                    ->color(fn (array $record) => $record['state']->color()),
                ...collect(LimitKey::cases())->map(fn (LimitKey $key) => TextColumn::make('limit_'.$key->value)->label($key->label())
                    ->state(fn (array $record) => $this->cell($record['limits'][$key->value]))
                    ->color(fn (array $record) => $record['limits'][$key->value]['state']->color()))->all(),
                TextColumn::make('period')->label('Period ends')->state(fn (array $record) => $record['period_end']->setTimezone($record['timezone'])->format('M j, Y')),
            ])
            ->filters([
                SelectFilter::make('state')->label('Quota status')->options([
                    'attention' => 'Needs attention (nearing, at or over a limit)',
                    'near' => 'Nearing a limit',
                    'at_limit' => 'At a limit',
                    'over' => 'Over a limit',
                    'ok' => 'Within limits',
                ]),
                SelectFilter::make('plan')->options(fn () => collect(app(PlanCatalog::class)->all())->mapWithKeys(fn ($plan) => [$plan->key => $plan->name])->all()),
            ])
            ->recordActions([
                Action::make('details')->label('Details')->icon('heroicon-o-chart-bar')
                    ->url(fn (array $record) => UsageDetails::getUrl(['organizationId' => $record['id']])),
            ])
            ->emptyStateHeading('No organizations match');
    }

    /** @param array{used: int, limit: ?int, percent: ?int} $cell */
    private function cell(array $cell): string
    {
        return $cell['limit'] === null ? number_format($cell['used']).' / ∞' : number_format($cell['used']).' / '.number_format($cell['limit']).' ('.$cell['percent'].'%)';
    }

    private function capacityText(): string
    {
        try {
            $report = Cache::remember('admin:operations-report', 60, fn () => app(OperationsReport::class)->build());
            $failed = (int) ($report['queue']['failed']['total'] ?? 0);
            $emails = $report['emails_outbound_last_24h']['by_status'] ?? [];

            return sprintf(
                'Jobs waiting: %d · failed jobs: %d · emails stuck sending: %d · follow-ups overdue to process: %d · stalled automation runs: %d · outbound emails (24h): %d queued, %d failed.',
                $report['queue']['queued'] ?? 0, $failed, $report['emails_outbound_last_24h']['stuck_sending'] ?? 0,
                $report['follow_ups']['stranded_due'] ?? 0, $report['automation_runs']['stalled_runs'] ?? 0,
                $emails['queued'] ?? 0, $emails['failed'] ?? 0,
            );
        } catch (\Throwable) {
            return 'Capacity figures are unavailable right now.';
        }
    }
}
