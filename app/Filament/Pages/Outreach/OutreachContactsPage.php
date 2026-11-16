<?php

namespace App\Filament\Pages\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\OutreachCategory;
use App\Models\OutreachContact;
use App\Services\Outreach\OutreachContactImporter;
use App\Services\Outreach\OutreachCsvImport;
use App\Support\Admin\AdminAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Contacts the team may message, with their consent record. Bulk import from CSV: each row needs a consent source.
 */
class OutreachContactsPage extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Outreach contacts';

    protected static ?string $slug = 'outreach/contacts';

    protected string $view = 'filament.pages.outreach.table-page';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    public function getSubheading(): ?string
    {
        return 'Only contacts with a recorded consent source can be messaged. CSV columns: email, phone (international format), name, consent_source, consent_date (optional).';
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => AdminAccess::allows(auth()->user(), PlatformPermission::ManageOutreachContacts, audit: false))
                ->schema([
                    Select::make('outreach_category_id')->label('Category')->required()
                        ->options(fn () => OutreachCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                    FileUpload::make('file')->label('CSV file')->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', '.csv'])
                        ->disk('local')->directory('outreach-imports')->visibility('private'),
                ])
                ->action(function (array $data) {
                    $this->importFile($data);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OutreachContact::query()->with('category')->latest())
            ->columns([
                TextColumn::make('name')->searchable()->placeholder('—'),
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('consent_source')->label('Consent source')->limit(40),
                TextColumn::make('consent_at')->label('Consent date')->dateTime()->placeholder('None'),
                TextColumn::make('email_opted_out_at')->label('Email stopped')->dateTime()->placeholder('Subscribed')->badge()->color(fn ($state) => $state ? 'danger' : 'success'),
                TextColumn::make('whatsapp_opted_out_at')->label('WhatsApp stopped')->dateTime()->placeholder('Subscribed')->badge()->color(fn ($state) => $state ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('stop_email')
                    ->label('Stop email')
                    ->color('gray')
                    ->visible(fn (OutreachContact $record) => $this->canManage() && $record->email_opted_out_at === null)
                    ->requiresConfirmation()
                    ->action(function (OutreachContact $record) {
                        $record->forceFill(['email_opted_out_at' => now()])->save();
                        Notification::make()->title('Email stopped for this contact')->success()->send();
                    }),
                Action::make('stop_whatsapp')
                    ->label('Stop WhatsApp')
                    ->color('gray')
                    ->visible(fn (OutreachContact $record) => $this->canManage() && $record->whatsapp_opted_out_at === null)
                    ->requiresConfirmation()
                    ->action(function (OutreachContact $record) {
                        $record->forceFill(['whatsapp_opted_out_at' => now()])->save();
                        Notification::make()->title('WhatsApp stopped for this contact')->success()->send();
                    }),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    private function importFile(array $data): void
    {
        // Filament hands back either the stored path or a list containing it, depending on the upload settings.
        $stored = is_array($data['file']) ? (string) ($data['file'][0] ?? '') : (string) $data['file'];

        if ($stored === '') {
            Notification::make()->title('Choose a CSV file first.')->danger()->send();

            return;
        }

        $path = Storage::disk('local')->path($stored);

        try {
            $parsed = app(OutreachCsvImport::class)->parse($path);

            if ($parsed['missing'] !== []) {
                throw new RuntimeException('The file needs these columns: '.implode(', ', $parsed['missing']).'.');
            }

            $category = OutreachCategory::query()->findOrFail($data['outreach_category_id']);
            $result = app(OutreachContactImporter::class)->import(auth()->user(), $category, $parsed['rows']);
        } catch (AuthorizationException|ValidationException|RuntimeException $e) {
            Notification::make()->title('Import stopped')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage())->danger()->send();

            return;
        } finally {
            Storage::disk('local')->delete($stored);
        }

        $errors = count($result['errors']);
        $summary = "{$result['created']} added, {$result['skipped']} already existed";
        $summary .= $errors > 0 ? ", {$errors} rows need fixing (first: row {$result['errors'][0]['row']}: {$result['errors'][0]['message']})" : '.';

        Notification::make()->title('Import finished')->body($summary)->success()->send();
    }

    private function canManage(): bool
    {
        return AdminAccess::allows(auth()->user(), PlatformPermission::ManageOutreachContacts, audit: false);
    }
}
