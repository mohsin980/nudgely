<?php

namespace App\Filament\Pages;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Platform\PlatformPermission;
use App\Exceptions\Platform\PlanSettingsException;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\PlanSetting;
use App\Models\Subscription;
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
 * Plans: name, description, prices in USD and PKR, the Stripe price for each currency, features, limits and whether
 * the plan is sold. Administrators can create plans here. Every save is checked, and Stripe prices are verified
 * against Stripe before they are saved (see PackageService).
 */
class Plans extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Plans';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ViewPlans;
    }

    public function getSubheading(): ?string
    {
        return 'Changes apply immediately to every business on the plan, including existing subscribers. A Stripe price cannot be changed, so a new price ID is used and the old one keeps serving the businesses already on it.';
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
                TextColumn::make('price_usd')->label('USD / month'),
                TextColumn::make('price_pkr')->label('PKR / month'),
                TextColumn::make('provider_usd')->label('Stripe price (USD)'),
                TextColumn::make('provider_pkr')->label('Stripe price (PKR)'),
                TextColumn::make('features')->label('Features')->wrap(),
                ...collect(LimitKey::cases())->map(fn (LimitKey $key) => TextColumn::make('limit_'.$key->value)->label($key->label()))->all(),
                TextColumn::make('subscribers')->label('Active subscribers'),
                TextColumn::make('customized')->label('Source')->badge()->color(fn (string $state) => $state === 'Configured' ? 'gray' : 'info'),
            ])
            ->recordActions([$this->editAction(), $this->resetAction()])
            ->emptyStateHeading('No plans are configured');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $catalog = app(PlanCatalog::class);
        $saved = PlanSetting::query()->where('kind', PlanSetting::KIND_PLAN)->pluck('plan_key')->all();
        $counts = Subscription::query()->current()
            ->whereIn('status', array_map(fn (SubscriptionStatus $s) => $s->value, array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->grantsAccess())))
            ->selectRaw('plan, count(*) as total')->groupBy('plan')->pluck('total', 'plan');

        return collect($catalog->all())->mapWithKeys(function (Plan $plan) use ($saved, $counts, $catalog) {
            $row = [
                'key' => $plan->key,
                'name' => $plan->name,
                'description' => $plan->description,
                'status' => $plan->active ? 'Sold' : 'Not sold',
                'price_usd' => $plan->isFree() ? 'Free' : $this->amount($plan->priceFor('USD'), 'USD'),
                'price_pkr' => $this->amount($plan->priceFor('PKR'), 'PKR'),
                'provider_usd' => $plan->isFree() ? '—' : ($plan->providerPriceId ?: 'Not set'),
                'provider_pkr' => $plan->isFree() ? '—' : ($plan->providerPriceIdFor('PKR') ?: 'Not set'),
                'features' => collect($plan->features)->map(fn ($f) => config("billing.feature_labels.{$f}", $f))->implode(', ') ?: '—',
                'subscribers' => number_format((int) ($counts[$plan->key] ?? 0)).($plan->key === $catalog->default()->key ? ' (+ every business without a paid plan)' : ''),
                'customized' => in_array($plan->key, $saved, true) ? 'Saved' : 'Configured',
            ];

            foreach (LimitKey::cases() as $key) {
                $row['limit_'.$key->value] = $plan->limit($key) === null ? 'Unlimited' : number_format($plan->limit($key));
            }

            return [$plan->key => $row];
        })->all();
    }

    private function amount(?int $minor, string $currency): string
    {
        return $minor === null ? 'Not sold in '.$currency : Money::format($minor, $currency);
    }

    private function canManage(): bool
    {
        return auth()->user()?->can(PlatformPermission::ManagePlans->value) ?? false;
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('New plan')
            ->icon('heroicon-o-plus')
            ->visible(fn () => $this->canManage())
            ->modalHeading('New plan')
            ->modalSubmitActionLabel('Create plan')
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
            ->fillForm(fn (array $record): array => $this->formValues(app(PlanCatalog::class)->get($record['key'])))
            ->schema(fn (array $record) => $this->packageSchema(creating: false))
            ->action(fn (array $record, array $data, Action $action) => $this->save($action, $record['key'], $data, creating: false));
    }

    private function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset to configured')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (array $record) => $this->canManage() && $record['customized'] === 'Saved' && isset(config('billing.plans')[$record['key']]))
            ->requiresConfirmation()
            ->modalHeading('Reset this plan?')
            ->modalDescription('The plan goes back to its configured values. Its old Stripe prices keep serving the businesses already on them.')
            ->schema([
                Textarea::make('reason')->label('Reason (kept in the internal audit log)')->required()
                    ->minLength(PlanSettingsService::MIN_REASON)->maxLength(PlanSettingsService::MAX_REASON)->rows(3),
            ])
            ->action(function (array $record, array $data, Action $action) {
                try {
                    app(PackageService::class)->reset(auth()->user(), PackageService::KIND_PLAN, $record['key'], (string) $data['reason']);
                } catch (AuthorizationException|PlanSettingsException $e) {
                    Notification::make()->title($e instanceof AuthorizationException ? 'You are not allowed to do this.' : $e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Plan reset to the configured values')->success()->send();
                $action->success();
            });
    }

    /**
     * The form, shared by "New plan" and "Edit".
     *
     * @return array<Component>
     */
    private function packageSchema(bool $creating): array
    {
        return [
            Section::make('Details')->schema([
                TextInput::make('key')->label('Key')->required()->visible($creating)
                    ->helperText('Lowercase letters, numbers and underscores. It cannot be changed later.')
                    ->regex('/^[a-z][a-z0-9_]{1,31}$/')->maxLength(32),
                TextInput::make('name')->label('Name')->required()->maxLength(40),
                Textarea::make('description')->label('Description (shown on the plan)')->rows(3)->maxLength(500),
                Toggle::make('active')->label('Sold (new businesses can choose it)')->default(true),
            ])->columns(2),
            Section::make('Prices (per month)')->schema([
                TextInput::make('price_usd')->label('USD price')->required()->numeric()->minValue(0)->step('0.01')->prefix('$'),
                TextInput::make('price_pkr')->label('PKR price (for visitors from Pakistan)')->numeric()->minValue(0)->step('1')->prefix('PKR'),
                TextInput::make('provider_usd')->label('Stripe price ID (USD)')->maxLength(100)->placeholder('price_...'),
                TextInput::make('provider_pkr')->label('Stripe price ID (PKR)')->maxLength(100)->placeholder('price_...'),
            ])->columns(2)->description('Each Stripe price is checked against Stripe before it is saved: it must be active, monthly, in the same currency, and charge the amount entered.'),
            Section::make('Features')->schema([
                CheckboxList::make('features')->label('Included features')->columns(2)
                    ->options(config('billing.feature_labels', [])),
            ]),
            Section::make('Limits')->schema(collect(LimitKey::cases())->map(fn (LimitKey $key) => TextInput::make('limit_'.$key->value)
                ->label($key->label())->numeric()->minValue(0)->maxValue(PlanSettingsService::MAX_LIMIT)
                ->helperText('Leave empty for unlimited.'))->all())->columns(2),
            Textarea::make('reason')->label('Reason (kept in the internal audit log)')->required()
                ->minLength(PlanSettingsService::MIN_REASON)->maxLength(PlanSettingsService::MAX_REASON)->rows(2),
        ];
    }

    /** @return array<string, mixed> */
    private function formValues(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'description' => $plan->description,
            'active' => $plan->active,
            'price_usd' => $plan->priceCents / 100,
            'price_pkr' => $plan->priceFor('PKR') === null ? null : $plan->priceFor('PKR') / 100,
            'provider_usd' => $plan->providerPriceId,
            'provider_pkr' => $plan->providerPriceIdFor('PKR'),
            'features' => $plan->features,
            ...collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => ['limit_'.$key->value => $plan->limit($key)])->all(),
        ];
    }

    /**
     * Form values in the shape PackageService takes: amounts in minor units (cents, paisa).
     *
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
            'prices' => ['USD' => $minor($data['price_usd'] ?? null), 'PKR' => $minor($data['price_pkr'] ?? null)],
            'provider_price_ids' => ['USD' => $data['provider_usd'] ?? null, 'PKR' => $data['provider_pkr'] ?? null],
            'features' => $data['features'] ?? [],
            'limits' => collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => $data['limit_'.$key->value] ?? null])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Action $action, ?string $key, array $data, bool $creating): void
    {
        try {
            $changes = app(PackageService::class)->save(auth()->user(), PackageService::KIND_PLAN, $key,
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

        Notification::make()->title($creating ? 'Plan created' : 'Plan saved')
            ->body(count($changes) > 0 ? 'Changed: '.implode(', ', array_keys($changes)).'.' : 'Nothing changed.')
            ->success()->send();
        $action->success();
    }
}
