<?php

namespace App\Filament\Pages\Outreach;

use App\Enums\Outreach\OutreachChannel;
use App\Enums\Outreach\OutreachTemplateStatus;
use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\OutreachCategory;
use App\Models\OutreachTemplate;
use App\Services\Outreach\OutreachRenderer;
use App\Services\Outreach\OutreachTemplates;
use App\Support\Admin\AdminAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 * Message templates. A template is written, submitted, then approved or rejected by a super admin before any
 * campaign can use it. Approved templates go back to draft when edited.
 */
class OutreachTemplatesPage extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Outreach templates';

    protected static ?string $slug = 'outreach/templates';

    protected string $view = 'filament.pages.outreach.table-page';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    public function getSubheading(): ?string
    {
        return 'Write a message, submit it, and wait for a super admin to approve it. Use '.implode(' or ', OutreachRenderer::placeholders()).' to personalise.';
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New template')
                ->icon('heroicon-o-plus')
                ->visible(fn () => $this->canWrite())
                ->schema($this->fields())
                ->action(function (array $data) {
                    $this->run(fn () => app(OutreachTemplates::class)->create(auth()->user(), $data), 'Template saved as a draft');
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OutreachTemplate::query()->with(['category', 'creator', 'reviewer'])->latest())
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('channel')->badge()->color(fn ($state) => $state->value === 'email' ? 'primary' : 'success')->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    OutreachTemplateStatus::Approved => 'success',
                    OutreachTemplateStatus::Pending => 'warning',
                    OutreachTemplateStatus::Rejected => 'danger',
                    default => 'gray',
                })->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('creator.name')->label('Written by'),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('review_note')->label('Note')->limit(60)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->visible(fn () => $this->canWrite())
                    ->fillForm(fn (OutreachTemplate $record) => $record->only(['outreach_category_id', 'channel', 'name', 'subject', 'body', 'whatsapp_template_name', 'whatsapp_language']))
                    ->schema($this->fields())
                    ->action(function (array $data, OutreachTemplate $record) {
                        $this->run(fn () => app(OutreachTemplates::class)->update(auth()->user(), $record, $data), 'Template saved');
                    }),
                Action::make('submit')
                    ->label('Submit for approval')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (OutreachTemplate $record) => $this->canWrite() && in_array($record->status, [OutreachTemplateStatus::Draft, OutreachTemplateStatus::Rejected], true))
                    ->requiresConfirmation()
                    ->action(function (OutreachTemplate $record) {
                        $this->run(fn () => app(OutreachTemplates::class)->submit(auth()->user(), $record), 'Submitted for approval');
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->visible(fn (OutreachTemplate $record) => $this->canApprove() && $record->status === OutreachTemplateStatus::Pending)
                    ->requiresConfirmation()
                    ->action(function (OutreachTemplate $record) {
                        $this->run(fn () => app(OutreachTemplates::class)->approve(auth()->user(), $record), 'Template approved');
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->visible(fn (OutreachTemplate $record) => $this->canApprove() && $record->status === OutreachTemplateStatus::Pending)
                    ->schema([Textarea::make('note')->label('Why it is rejected')->required()->maxLength(500)])
                    ->action(function (array $data, OutreachTemplate $record) {
                        $this->run(fn () => app(OutreachTemplates::class)->reject(auth()->user(), $record, $data['note']), 'Template rejected');
                    }),
            ]);
    }

    /** @return list<Component> */
    private function fields(): array
    {
        return [
            Select::make('outreach_category_id')->label('Category')->required()
                ->options(fn () => OutreachCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
            Select::make('channel')->required()
                ->options(collect(OutreachChannel::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('subject')->maxLength(200)->helperText('Email only.'),
            Textarea::make('body')->required()->rows(6)->maxLength(4000)
                ->helperText('Placeholders: '.implode(', ', OutreachRenderer::placeholders()).'. Email adds an unsubscribe link automatically.'),
            TextInput::make('whatsapp_template_name')->maxLength(120)->helperText('WhatsApp only: the template name Meta has approved for this message.'),
            TextInput::make('whatsapp_language')->maxLength(16)->default('en')->helperText('WhatsApp only, for example en or en_US.'),
        ];
    }

    private function canWrite(): bool
    {
        return AdminAccess::allows(auth()->user(), PlatformPermission::ManageOutreachTemplates, audit: false);
    }

    private function canApprove(): bool
    {
        return AdminAccess::allows(auth()->user(), PlatformPermission::ApproveOutreachTemplates, audit: false);
    }

    /** Runs a service call and reports its result as a notification, instead of an unhandled error. */
    private function run(\Closure $call, string $success): void
    {
        try {
            $call();
        } catch (AuthorizationException|ValidationException $e) {
            Notification::make()->title('Not saved')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }
}
