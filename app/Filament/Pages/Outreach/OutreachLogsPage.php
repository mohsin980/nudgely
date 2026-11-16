<?php

namespace App\Filament\Pages\Outreach;

use App\Enums\Outreach\OutreachChannel;
use App\Enums\Outreach\OutreachMessageStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\OutreachMessage;
use App\Services\Outreach\OutreachEligibility;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every message the team has attempted: sent, failed or skipped, with the reason. Read-only.
 */
class OutreachLogsPage extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Outreach logs';

    protected static ?string $slug = 'outreach/logs';

    protected string $view = 'filament.pages.outreach.table-page';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    public function getSubheading(): ?string
    {
        return 'Every email and WhatsApp message the team attempted, with its outcome. Recipients are shown in full to people with this access.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OutreachMessage::query()->with(['campaign.template'])->latest('id'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('channel')->badge()->color(fn ($state) => $state->value === 'email' ? 'primary' : 'success')->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('recipient')->searchable(),
                TextColumn::make('campaign.template.name')->label('Template'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    OutreachMessageStatus::Sent => 'success',
                    OutreachMessageStatus::Failed => 'danger',
                    OutreachMessageStatus::Skipped => 'gray',
                    default => 'warning',
                })->formatStateUsing(fn ($state) => ucfirst($state->value)),
                TextColumn::make('skip_reason')->label('Reason')
                    ->formatStateUsing(fn ($state) => $state ? OutreachEligibility::label($state) : null)->placeholder('—'),
                TextColumn::make('error')->label('Error')->limit(60)->placeholder('—'),
                TextColumn::make('sent_at')->label('Sent at')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('channel')->options(collect(OutreachChannel::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                SelectFilter::make('status')->options(collect(OutreachMessageStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])),
            ])
            ->paginated([25, 50, 100]);
    }
}
