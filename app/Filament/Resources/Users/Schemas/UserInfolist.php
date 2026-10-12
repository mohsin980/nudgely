<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only account details. Shows identity, organization membership and access state only: never passwords,
 * hashes, reset or remember tokens, sessions or provider credentials.
 */
final class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('email'),
                TextEntry::make('status')->badge()->state(fn (User $record) => $record->status->label()),
                TextEntry::make('email_verified_at')->label('Email verified')->dateTime()->placeholder('Not verified'),
                TextEntry::make('created_at')->label('Created')->dateTime(),
                TextEntry::make('last_active_at')->label('Last active')->since()->placeholder('Never'),
                TextEntry::make('suspended_at')->label('Deactivated since')->dateTime()->visible(fn (User $record) => $record->suspended_at !== null),
            ]),

            Section::make('Organization membership')->columns(3)->schema([
                TextEntry::make('organization.name')->label('Organization')->placeholder('None (platform account)')
                    ->url(fn (User $record) => $record->organization_id && AdminAccess::allows(auth()->user(), PlatformPermission::ViewOrganizations, audit: false)
                        ? OrganizationResource::getUrl('view', ['record' => $record->organization_id]) : null),
                TextEntry::make('role')->label('Business role')->state(fn (User $record) => $record->organization_id === null ? '—' : $record->role->label()),
            ]),

            Section::make('Platform access')
                ->description('Read-only. Platform roles are granted only on the server with the platform-admin:grant command.')
                ->schema([
                    TextEntry::make('platform_role')->label('Platform role')
                        ->state(fn (User $record) => $record->isPlatformAdmin() ? ($record->roles()->pluck('name')->implode(', ') ?: 'Platform administrator (no role)') : 'None'),
                ]),

            Section::make('Account history')
                ->description('Deactivations and reactivations, with the internal reason. Not visible to the business.')
                ->visible(fn () => AdminAccess::allows(auth()->user(), PlatformPermission::ViewAuditLogs, audit: false))
                ->schema([
                    RepeatableEntry::make('history')->hiddenLabel()
                        ->state(fn (User $record) => PlatformAuditLog::query()->with('actor:id,name')->where('subject_user_id', $record->id)->latest('id')->limit(10)->get()
                            ->map(fn ($log) => ['when' => $log->created_at?->toDayDateTimeString(), 'event' => $log->label(), 'by' => $log->actor?->name ?? 'Unknown', 'reason' => $log->reason])->all())
                        ->columns(4)
                        ->schema([TextEntry::make('when'), TextEntry::make('event'), TextEntry::make('by'), TextEntry::make('reason')])
                        ->placeholder('No changes yet.'),
                ]),
        ]);
    }
}
