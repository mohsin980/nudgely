<?php

namespace App\Filament\Pages\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\OutreachCategory;
use App\Support\Admin\AdminAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Business types the outreach is aimed at (HVAC, cafe, and so on). Contacts and templates belong to one category.
 */
class OutreachCategoriesPage extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Outreach categories';

    protected static ?string $slug = 'outreach/categories';

    protected string $view = 'filament.pages.outreach.table-page';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewOutreach;
    }

    public function getSubheading(): ?string
    {
        return 'Categories group contacts and templates by the kind of business they serve, for example HVAC or Cafe.';
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New category')
                ->icon('heroicon-o-plus')
                ->visible(fn () => AdminAccess::allows(auth()->user(), PlatformPermission::ManageOutreachCategories, audit: false))
                ->schema($this->fields())
                ->action(function (array $data) {
                    OutreachCategory::query()->create($this->attributes($data));
                    Notification::make()->title('Category created')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(OutreachCategory::query()->withCount(['contacts', 'templates'])->orderBy('name'))
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('slug')->fontFamily('mono'),
                TextColumn::make('contacts_count')->label('Contacts')->numeric(),
                TextColumn::make('templates_count')->label('Templates')->numeric(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn () => AdminAccess::allows(auth()->user(), PlatformPermission::ManageOutreachCategories, audit: false))
                    ->fillForm(fn (OutreachCategory $record) => $record->only(['name', 'slug', 'description', 'is_active']))
                    ->schema($this->fields())
                    ->action(function (array $data, OutreachCategory $record) {
                        $record->forceFill($this->attributes($data))->save();
                        Notification::make()->title('Category saved')->success()->send();
                    }),
            ]);
    }

    /** @return list<Component> */
    private function fields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(80),
            TextInput::make('slug')->required()->maxLength(80)->alphaDash()->helperText('Short name used in CSV imports, for example hvac.'),
            Textarea::make('description')->maxLength(500)->rows(2),
            Toggle::make('is_active')->label('Active')->default(true),
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'slug' => Str::slug($data['slug'] ?? $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
