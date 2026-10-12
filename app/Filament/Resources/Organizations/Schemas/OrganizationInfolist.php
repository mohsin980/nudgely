<?php

namespace App\Filament\Resources\Organizations\Schemas;

use App\Enums\Platform\PlatformPermission;
use App\Models\Organization;
use App\Services\Billing\EntitlementService;
use App\Support\Admin\AdminAccess;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only organization details. Only business facts, plan and usage numbers, and the platform history are shown:
 * no credentials, tokens, email connections, customer records or message content.
 */
final class OrganizationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $can = fn (PlatformPermission $permission): bool => AdminAccess::allows(auth()->user(), $permission, audit: false);

        return $schema->components([
            Section::make('Business')->columns(3)->schema([
                TextEntry::make('id')->label('ID'),
                TextEntry::make('name'),
                TextEntry::make('status')->badge()
                    ->state(fn (Organization $record) => $record->status()->label())
                    ->color(fn (Organization $record) => $record->status()->color()),
                TextEntry::make('created_at')->label('Created')->dateTime(),
                TextEntry::make('suspended_at')->label('Suspended since')->dateTime()->visible(fn (Organization $record) => $record->isSuspended()),
                TextEntry::make('users_count')->label('Users'),
                TextEntry::make('timezone')->placeholder('Not set'),
                TextEntry::make('currency'),
                TextEntry::make('trial_ends_at')->label('Sign-up trial ends')->dateTime()->placeholder('No trial'),
            ]),

            Section::make('Subscription')->columns(3)
                ->visible(fn () => $can(PlatformPermission::ViewSubscriptions))
                ->schema([
                    TextEntry::make('plan')->label('Plan')
                        ->state(fn (Organization $record) => $record->currentSubscription?->planDefinition()?->name ?? ($record->currentSubscription?->plan ?? 'Free (no subscription)')),
                    TextEntry::make('subscription_status')->label('Status')->badge()
                        ->state(fn (Organization $record) => $record->currentSubscription?->status->label() ?? 'None'),
                    TextEntry::make('provider')->label('Billing provider')
                        ->state(fn (Organization $record) => $record->currentSubscription?->provider ?? '—'),
                    TextEntry::make('period_end')->label('Current period ends')
                        ->state(fn (Organization $record) => $record->currentSubscription?->current_period_end)->dateTime()->placeholder('—'),
                    TextEntry::make('cancels')->label('Cancels at period end')
                        ->state(fn (Organization $record) => $record->currentSubscription === null ? '—' : ($record->currentSubscription->cancel_at_period_end ? 'Yes' : 'No')),
                ]),

            Section::make('Usage')
                ->description('Against the organization\'s current plan limits.')
                ->visible(fn () => $can(PlatformPermission::ViewUsage))
                ->schema([
                    KeyValueEntry::make('usage')->hiddenLabel()->keyLabel('Measure')->valueLabel('Used / limit')
                        ->state(fn (Organization $record) => collect(app(EntitlementService::class)->summary($record))
                            ->mapWithKeys(fn (array $row) => [$row['label'] => $row['used'].' / '.($row['limit'] ?? 'unlimited')])->all()),
                ]),

            Section::make('Platform history')
                ->description('Suspensions and reactivations, with the internal reason. Not visible to the business.')
                ->visible(fn () => $can(PlatformPermission::ViewAuditLogs))
                ->schema([
                    RepeatableEntry::make('history')->hiddenLabel()
                        ->state(fn (Organization $record) => $record->platformAuditLogs()->with('actor:id,name')->latest('id')->limit(10)->get()
                            ->map(fn ($log) => ['when' => $log->created_at?->toDayDateTimeString(), 'event' => $log->label(), 'by' => $log->actor?->name ?? 'Unknown', 'reason' => $log->reason])->all())
                        ->columns(4)
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('event'),
                            TextEntry::make('by'),
                            TextEntry::make('reason'),
                        ])
                        ->placeholder('No suspensions yet.'),
                ]),
        ]);
    }
}
