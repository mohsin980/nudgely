<?php

namespace App\Filament\Resources\AuditLogs;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\PlatformAuditLog;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The audit trail of platform administration, read-only. There is no create, edit or delete anywhere in this resource,
 * and the policy and model refuse them as well. Built for large tables: newest-first by ID, simple pagination (no
 * COUNT over the whole table), and search and filters that use indexed columns (action, actor, target, organization,
 * outcome, time).
 */
class AuditLogResource extends Resource
{
    use RequiresPlatformPermission;

    protected static ?string $model = PlatformAuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $modelLabel = 'audit event';

    protected static ?int $navigationSort = 10;

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewAuditLogs;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['actor:id,name', 'organization:id,name']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')->columns(3)->schema([
                TextEntry::make('id')->label('Event ID'),
                TextEntry::make('created_at')->label('When')->dateTime('M j, Y g:i:s A'),
                TextEntry::make('outcome')->badge()->state(fn (PlatformAuditLog $record) => ucfirst($record->outcome))->color(fn (PlatformAuditLog $record) => self::outcomeColor($record->outcome)),
                TextEntry::make('action')->state(fn (PlatformAuditLog $record) => $record->label())->helperText(fn (PlatformAuditLog $record) => $record->action),
                TextEntry::make('actor')->state(fn (PlatformAuditLog $record) => self::actorLabel($record)),
                TextEntry::make('correlation_id')->label('Request ID')->placeholder('Not available')->copyable(),
                TextEntry::make('target')->state(fn (PlatformAuditLog $record) => self::targetLabel($record)),
                TextEntry::make('organization')->state(fn (PlatformAuditLog $record) => self::organizationLabel($record)),
                TextEntry::make('reason')->placeholder('None given')->columnSpanFull(),
            ]),
            Section::make('Details')
                ->description('Sanitized when recorded: secrets, tokens, card numbers, message content and email addresses are never stored.')
                ->schema([
                    TextEntry::make('details')->hiddenLabel()
                        ->state(fn (PlatformAuditLog $record) => $record->changes === null ? 'No further details.' : json_encode($record->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: ui-monospace, monospace; font-size: 0.85rem;']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Search action, target ID, organization ID, user ID or request ID')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, Y g:i A')->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('id', $direction)),
                TextColumn::make('action')->label('Event')->state(fn (PlatformAuditLog $record) => $record->label())
                    ->searchable(query: fn (Builder $query, string $search) => self::search($query, $search)),
                TextColumn::make('outcome')->badge()->state(fn (PlatformAuditLog $record) => ucfirst($record->outcome))->color(fn (PlatformAuditLog $record) => self::outcomeColor($record->outcome)),
                TextColumn::make('actor')->label('Actor')->state(fn (PlatformAuditLog $record) => self::actorLabel($record)),
                TextColumn::make('target')->label('Target')->state(fn (PlatformAuditLog $record) => self::targetLabel($record)),
                TextColumn::make('organization')->label('Organization')->state(fn (PlatformAuditLog $record) => self::organizationLabel($record))->toggleable(),
                TextColumn::make('correlation_id')->label('Request ID')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->options(PlatformAuditLog::LABELS),
                SelectFilter::make('outcome')->options(['success' => 'Success', 'denied' => 'Denied', 'failed' => 'Failed']),
                SelectFilter::make('actor_id')->label('Actor')->relationship('actor', 'name')->searchable(),
                Filter::make('organization')->label('Organization')
                    ->schema([TextInput::make('organization_id')->label('Organization ID')->numeric()])
                    ->query(fn (Builder $query, array $data) => filled($data['organization_id'] ?? null) ? $query->where('organization_id', (int) $data['organization_id']) : $query),
                Filter::make('period')->label('Period')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<', CarbonImmutable::parse($date)->addDay()))),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No audit events found');
    }

    /**
     * Search on indexed columns only: actions whose name or label contains the text (resolved in PHP against the
     * short list of known actions), plus exact target ID, organization ID, actor ID and request ID.
     */
    public static function search(Builder $query, string $search): Builder
    {
        $term = mb_strtolower(trim($search));
        $actions = collect(PlatformAuditLog::LABELS)
            ->filter(fn (string $label, string $action) => str_contains(mb_strtolower($label), $term) || str_contains($action, $term))->keys()->all();

        return $query->where(function (Builder $q) use ($search, $actions) {
            $q->whereIn('action', $actions)->orWhere('target_id', $search)->orWhere('correlation_id', $search);

            if (ctype_digit($search)) {
                $q->orWhere('organization_id', (int) $search)->orWhere('actor_id', (int) $search);
            }
        });
    }

    public static function actorLabel(PlatformAuditLog $log): string
    {
        return match (true) {
            $log->actor_id === null => 'System / server command',
            $log->actor !== null => $log->actor->name.' (#'.$log->actor_id.')',
            default => 'Deleted user #'.$log->actor_id,
        };
    }

    public static function targetLabel(PlatformAuditLog $log): string
    {
        return $log->target_type === null ? '—' : ucfirst(str_replace('_', ' ', $log->target_type)).($log->target_id !== null ? ' '.(ctype_digit((string) $log->target_id) ? '#' : '').$log->target_id : '');
    }

    public static function organizationLabel(PlatformAuditLog $log): string
    {
        return match (true) {
            $log->organization_id === null => '—',
            $log->organization !== null => $log->organization->name.' (#'.$log->organization_id.')',
            default => 'Deleted organization #'.$log->organization_id,
        };
    }

    public static function outcomeColor(string $outcome): string
    {
        return match ($outcome) {
            PlatformAuditLog::DENIED => 'warning',
            PlatformAuditLog::FAILED => 'danger',
            default => 'success',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
