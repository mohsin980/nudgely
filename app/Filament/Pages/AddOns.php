<?php

namespace App\Filament\Pages;

use App\Billing\AddonCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Platform\PlatformPermission;
use App\Exceptions\Platform\PlanSettingsException;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\PlanSetting;
use App\Services\Platform\PackageService;
use App\Services\Platform\PlanSettingsService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Add-ons: optional extras bought on top of a plan. Each one adds to the plan's limits and can switch on features.
 * Administrators can create add-ons here, set their prices and Stripe prices, and choose the plans they are sold with.
 */
class AddOns extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Add-ons';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewPlans;
    }

    public function getSubheading(): ?string
    {
        return 'Add-ons are bought on top of a plan. Their limits are extra amounts added to the plan\'s own limits. Prices and Stripe prices are checked the same way as plans.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->createAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (array $record) => $record['description']),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'Sold' ? 'success' : 'gray'),
                TextColumn::make('applies_to')->label('Sold with plans')->wrap(),
                TextColumn::make('price_usd')->label('USD / month'),
                TextColumn::make('price_pkr')->label('PKR / month'),
                TextColumn::make('provider_usd')->label('Stripe price (USD)'),
                TextColumn::make('provider_pkr')->label('Stripe price (PKR)'),
                TextColumn::make('adds')->label('Adds')->wrap(),
                TextColumn::make('source')->label('Source')->badge()->color(fn (string $state) => $state === 'Configured' ? 'gray' : 'info'),
            ])
            ->recordActions([$this->editAction()])
            ->emptyStateHeading('No add-ons yet');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $saved = PlanSetting::query()->where('kind', PlanSetting::KIND_ADDON)->pluck('plan_key')->all();

        return collect(app(AddonCatalog::class)->all())->mapWithKeys(function (array $addon, string $key) use ($saved) {
            $ids = $addon['provider_price_ids'] ?? [];

            return [$key => [
                'key' => $key,
                'name' => $addon['name'],
                'description' => $addon['description'] ?? '',
                'status' => ($addon['active'] ?? true) ? 'Sold' : 'Not sold',
                'applies_to' => collect($addon['applies_to'] ?? [])->map(fn ($p) => config("billing.plans.{$p}.name", $p))->implode(', ') ?: '—',
                'price_usd' => Money::format((int) $addon['price_cents'], 'USD'),
                'price_pkr' => isset($addon['prices']['PKR']) ? Money::format((int) $addon['prices']['PKR'], 'PKR') : 'Not sold in PKR',
                'provider_usd' => $addon['provider_price_id'] ?: 'Not set',
                'provider_pkr' => $ids['PKR'] ?? 'Not set',
                'adds' => collect($addon['limits'] ?? [])->map(fn ($amount, $limit) => '+'.number_format((int) $amount).' '.(LimitKey::tryFrom($limit)?->label() ?? $limit))
                    ->merge(collect($addon['features'] ?? [])->map(fn ($f) => config("billing.feature_labels.{$f}", $f)))->implode(', ') ?: '—',
                'source' => in_array($key, $saved, true) ? 'Saved' : 'Configured',
            ]];
        })->all();
    }

    private function canManage(): bool
    {
        return auth()->user()?->can(PlatformPermission::ManagePlans->value) ?? false;
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('New add-on')
            ->icon('heroicon-o-plus')
            ->visible(fn () => $this->canManage())
            ->modalHeading('New add-on')
            ->modalSubmitActionLabel('Create add-on')
            ->schema($this->packageSchema(creating: true))
            ->action(fn (array $data, Action $action) => $this->save($action, null, $data, creating: true));
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn () => $this->canManage())
            ->modalHeading(fn (array $record) => 'Edit '.$record['name'])
            ->modalSubmitActionLabel('Save changes')
            ->fillForm(fn (array $record): array => $this->formValues($record['key']))
            ->schema(fn (array $record) => $this->packageSchema(creating: false))
            ->action(fn (array $record, array $data, Action $action) => $this->save($action, $record['key'], $data, creating: false));
    }

    /**
     * @return array<Component>
     */
    private function packageSchema(bool $creating): array
    {
        $planOptions = collect(config('billing.plans', []))->mapWithKeys(fn (array $plan, string $key) => [$key => $plan['name'] ?? $key])->all();

        return [
            Section::make('Details')->schema([
                TextInput::make('key')->label('Key')->required()->visible($creating)
                    ->helperText('Lowercase letters, numbers and underscores. It cannot be changed later.')
                    ->regex('/^[a-z][a-z0-9_]{1,31}$/')->maxLength(32),
                TextInput::make('name')->label('Name')->required()->maxLength(40),
                Textarea::make('description')->label('Description (shown to the business)')->rows(3)->maxLength(500),
                Toggle::make('active')->label('Sold (businesses can buy it)')->default(true),
            ])->columns(2),
            Section::make('Sold with')->schema([
                CheckboxList::make('applies_to')->label('Plans this add-on can be bought with')->required()->columns(2)->options($planOptions),
            ]),
            Section::make('Prices (per month)')->schema([
                TextInput::make('price_usd')->label('USD price')->required()->numeric()->minValue(0.01)->step('0.01')->prefix('$'),
                TextInput::make('price_pkr')->label('PKR price (for visitors from Pakistan)')->numeric()->minValue(0)->step('1')->prefix('PKR'),
                TextInput::make('provider_usd')->label('Stripe price ID (USD)')->maxLength(100)->placeholder('price_...'),
                TextInput::make('provider_pkr')->label('Stripe price ID (PKR)')->maxLength(100)->placeholder('price_...'),
            ])->columns(2)->description('Each Stripe price is checked against Stripe before it is saved: it must be active, monthly, in the same currency, and charge the amount entered.'),
            Section::make('Adds to the plan')->schema([
                CheckboxList::make('features')->label('Features switched on')->columns(2)->options(config('billing.feature_labels', [])),
                ...collect(LimitKey::cases())->map(fn (LimitKey $key) => TextInput::make('limit_'.$key->value)
                    ->label('Extra '.strtolower($key->label()))->numeric()->minValue(0)->maxValue(PlanSettingsService::MAX_LIMIT)
                    ->helperText('Added to the plan\'s limit. Leave empty for none.'))->all(),
            ])->columns(2),
            Textarea::make('reason')->label('Reason (kept in the internal audit log)')->required()
                ->minLength(PlanSettingsService::MIN_REASON)->maxLength(PlanSettingsService::MAX_REASON)->rows(2),
        ];
    }

    /** @return array<string, mixed> */
    private function formValues(string $key): array
    {
        $addon = app(AddonCatalog::class)->find($key) ?? [];

        return [
            'name' => $addon['name'] ?? '',
            'description' => $addon['description'] ?? '',
            'active' => (bool) ($addon['active'] ?? true),
            'applies_to' => $addon['applies_to'] ?? [],
            'price_usd' => isset($addon['price_cents']) ? $addon['price_cents'] / 100 : null,
            'price_pkr' => isset($addon['prices']['PKR']) ? $addon['prices']['PKR'] / 100 : null,
            'provider_usd' => $addon['provider_price_id'] ?? null,
            'provider_pkr' => $addon['provider_price_ids']['PKR'] ?? null,
            'features' => $addon['features'] ?? [],
            ...collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $limit) => ['limit_'.$limit->value => $addon['limits'][$limit->value] ?? null])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function packageData(array $data, bool $creating): array
    {
        $minor = fn ($value) => $value === null || $value === '' ? null : (int) round((float) $value * 100);

        return [
            'key' => $creating ? (string) $data['key'] : null,
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
            'applies_to' => $data['applies_to'] ?? [],
            'prices' => ['USD' => $minor($data['price_usd'] ?? null), 'PKR' => $minor($data['price_pkr'] ?? null)],
            'provider_price_ids' => ['USD' => $data['provider_usd'] ?? null, 'PKR' => $data['provider_pkr'] ?? null],
            'features' => $data['features'] ?? [],
            'limits' => collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $limit) => [$limit->value => $data['limit_'.$limit->value] ?? null])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Action $action, ?string $key, array $data, bool $creating): void
    {
        try {
            $changes = app(PackageService::class)->save(auth()->user(), PackageService::KIND_ADDON, $key,
                $this->packageData($data, $creating), (string) $data['reason']);
        } catch (AuthorizationException) {
            Notification::make()->title('You are not allowed to do this.')->danger()->send();
            $action->halt();

            return;
        } catch (PlanSettingsException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return;
        } catch (ValidationException $e) {
            Notification::make()->title('Not saved')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()->title($creating ? 'Add-on created' : 'Add-on saved')
            ->body(count($changes) > 0 ? 'Changed: '.implode(', ', array_keys($changes)).'.' : 'Nothing changed.')
            ->success()->send();
        $action->success();
    }
}
