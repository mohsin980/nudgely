<?php

namespace App\Filament\Pages\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Outreach\OutreachAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Super admins give or take outreach permissions for any active platform administrator. Only outreach permissions
 * appear here; platform roles are managed elsewhere.
 */
class OutreachAccessPage extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = 'Outreach access';

    protected static ?string $slug = 'outreach/access';

    protected string $view = 'filament.pages.outreach.table-page';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ManageOutreachAccess;
    }

    public function getSubheading(): ?string
    {
        return 'Tick what each administrator may do with outreach. Every change is written to the audit log.';
    }

    public function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('name')->weight('bold'),
            TextColumn::make('email'),
        ];

        foreach (OutreachAccess::GRANTABLE as $permission) {
            $columns[] = IconColumn::make($permission->value)
                ->label(str($permission->value)->replace('_', ' ')->ucfirst()->toString())
                ->boolean()
                ->state(fn (User $record) => $record->hasDirectPermission($permission->value));
        }

        return $table
            ->query(User::query()->whereIn('id', PlatformAdmin::query()->select('user_id'))->orderBy('name'))
            ->columns($columns)
            ->recordActions([
                Action::make('edit')
                    ->label('Edit access')
                    ->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (User $record) => [
                        'permissions' => collect(OutreachAccess::GRANTABLE)
                            ->filter(fn (PlatformPermission $p) => $record->hasDirectPermission($p->value))
                            ->map(fn (PlatformPermission $p) => $p->value)->values()->all(),
                    ])
                    ->schema([
                        CheckboxList::make('permissions')->label('Outreach permissions')
                            ->options(collect(OutreachAccess::GRANTABLE)->mapWithKeys(fn (PlatformPermission $p) => [$p->value => str($p->value)->replace('_', ' ')->ucfirst()->toString()]))
                            ->columns(2),
                    ])
                    ->action(function (array $data, User $record) {
                        $this->apply($record, $data['permissions'] ?? []);
                    }),
            ]);
    }

    /** @param  list<string>  $wanted */
    private function apply(User $target, array $wanted): void
    {
        $service = app(OutreachAccess::class);

        try {
            foreach (OutreachAccess::GRANTABLE as $permission) {
                $has = $target->hasDirectPermission($permission->value);
                $should = in_array($permission->value, $wanted, true);

                if ($has !== $should) {
                    $service->set(auth()->user(), $target, $permission, $should);
                }
            }
        } catch (AuthorizationException|ValidationException $e) {
            Notification::make()->title('Not saved')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Access saved')->success()->send();
    }
}
