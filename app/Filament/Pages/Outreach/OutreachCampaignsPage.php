<?php

namespace App\Filament\Pages\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\OutreachCampaign;
use App\Models\OutreachTemplate;
use App\Services\Outreach\OutreachCampaigns;
use App\Support\Admin\AdminAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Campaigns: an approved template sent to one category. Starting one queues a job; progress updates as messages go.
 */
class OutreachCampaignsPage extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|\UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Outreach campaigns';

    protected static ?string $slug = 'outreach/campaigns';

    protected string $view = 'filament.pages.outreach.table-page';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    public function getSubheading(): ?string
    {
        return 'Only approved templates can be sent. Each campaign checks consent and opt-outs for every contact before sending, and records each outcome in the logs.';
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label('Start campaign')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn () => AdminAccess::allows(auth()->user(), PlatformPermission::SendOutreach, audit: false))
                ->schema([
                    Select::make('outreach_template_id')->label('Approved template')->required()
                        ->options(fn () => OutreachTemplate::query()->where('status', 'approved')->with('category')->get()
                            ->mapWithKeys(fn (OutreachTemplate $t) => [$t->id => "{$t->name} · {$t->channel->label()} · {$t->category->name}"])),
                ])
                ->requiresConfirmation()
                ->modalDescription('Eligible contacts in the template\'s category will be messaged. Contacts without consent, who opted out, or who were messaged recently are skipped.')
                ->action(function (array $data) {
                    try {
                        $template = OutreachTemplate::query()->with('category')->findOrFail($data['outreach_template_id']);
                        app(OutreachCampaigns::class)->start(auth()->user(), $template, $template->category);
                    } catch (AuthorizationException|ValidationException $e) {
                        Notification::make()->title('Not started')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Campaign queued. Progress appears here as messages are sent.')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OutreachCampaign::query()->with(['template', 'category', 'creator'])->latest())
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime(),
                TextColumn::make('template.name')->label('Template')->weight('bold'),
                TextColumn::make('channel')->badge()->color(fn ($state) => $state->value === 'email' ? 'primary' : 'success')->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state->value) { 'completed' => 'success', 'running' => 'info', default => 'gray' })->formatStateUsing(fn ($state) => ucfirst($state->value)),
                TextColumn::make('queued_count')->label('Queued')->numeric(),
                TextColumn::make('sent_count')->label('Sent')->numeric(),
                TextColumn::make('failed_count')->label('Failed')->numeric(),
                TextColumn::make('skipped_count')->label('Skipped at start')->numeric(),
                TextColumn::make('creator.name')->label('Started by'),
            ])
            ->poll('15s');
    }
}
