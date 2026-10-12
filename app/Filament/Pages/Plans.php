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
use App\Services\Platform\PlanSettingsService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Plans and their limits. Prices, intervals and provider price IDs are shown but cannot be changed here: they come
 * from config/billing.php and (what is charged) the billing provider. An administrator may edit a plan's display name
 * and limits; see PlanSettingsService for what that means for existing subscribers.
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
        return 'Price, interval and billing-provider IDs are read-only: they are set in the billing configuration and owned by the provider. Limits and display names can be edited and apply to existing subscribers immediately.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'Available' ? 'success' : 'warning'),
                TextColumn::make('interval')->label('Billing interval'),
                TextColumn::make('price')->label('Price (set in billing config)'),
                TextColumn::make('provider')->label('Provider price ID'),
                ...collect(LimitKey::cases())->map(fn (LimitKey $key) => TextColumn::make('limit_'.$key->value)->label($key->label()))->all(),
                TextColumn::make('subscribers')->label('Active subscribers'),
                TextColumn::make('customized')->label('Limits')->badge()->color(fn (string $state) => $state === 'Edited' ? 'info' : 'gray'),
            ])
            ->recordActions([$this->editAction(), $this->resetAction()])
            ->emptyStateHeading('No plans are configured');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $catalog = app(PlanCatalog::class);
        $customized = PlanSetting::query()->pluck('plan_key')->all();
        $counts = Subscription::query()->current()
            ->whereIn('status', array_map(fn (SubscriptionStatus $s) => $s->value, array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->grantsAccess())))
            ->selectRaw('plan, count(*) as total')->groupBy('plan')->pluck('total', 'plan');
        $stripe = config('billing.provider') === 'stripe';
        $currency = (string) config('billing.currency', 'USD');

        return collect($catalog->all())->mapWithKeys(function (Plan $plan) use ($customized, $counts, $stripe, $currency, $catalog) {
            $row = [
                'key' => $plan->key,
                'name' => $plan->name,
                'status' => $plan->isFree() || ! $stripe || filled($plan->providerPriceId) ? 'Available' : 'No provider price ID',
                'interval' => ucfirst($plan->interval->value).'ly',
                'price' => $plan->isFree() ? 'Free' : Money::format($plan->priceCents, $currency),
                'provider' => $plan->isFree() ? '—' : ($plan->providerPriceId ?: 'Not set'),
                'subscribers' => number_format((int) ($counts[$plan->key] ?? 0)).($plan->key === $catalog->default()->key ? ' (+ every business without a paid plan)' : ''),
                'customized' => in_array($plan->key, $customized, true) ? 'Edited' : 'As configured',
            ];

            foreach (LimitKey::cases() as $key) {
                $row['limit_'.$key->value] = $plan->limit($key) === null ? 'Unlimited' : number_format($plan->limit($key));
            }

            return [$plan->key => $row];
        })->all();
    }

    private function canManage(): bool
    {
        return auth()->user()?->can(PlatformPermission::ManagePlans->value) ?? false;
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit limits')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn () => $this->canManage())
            ->modalHeading(fn (array $record) => 'Edit '.$record['name'])
            ->modalSubmitActionLabel('Save changes')
            ->fillForm(function (array $record): array {
                $plan = app(PlanCatalog::class)->get($record['key']);

                return ['name' => $plan->name, ...collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => ['limit_'.$key->value => $plan->limit($key)])->all()];
            })
            ->schema(fn (array $record) => [
                Text::make('Price, interval and the provider price ID cannot be changed here. This does not touch Stripe or any existing subscription.'),
                TextInput::make('name')->label('Display name')->required()->maxLength(40)->live(onBlur: true),
                ...collect(LimitKey::cases())->map(fn (LimitKey $key) => TextInput::make('limit_'.$key->value)
                    ->label($key->label())->numeric()->minValue(0)->maxValue(PlanSettingsService::MAX_LIMIT)
                    ->helperText('Leave empty for unlimited.')->live(onBlur: true))->all(),
                Text::make(fn (Get $get) => $this->impactText($record['key'], $get))->color('warning'),
                Textarea::make('reason')->label('Reason (kept in the internal audit log)')->required()
                    ->minLength(PlanSettingsService::MIN_REASON)->maxLength(PlanSettingsService::MAX_REASON)->rows(3),
            ])
            ->action(function (array $record, array $data, Action $action) {
                try {
                    $impact = app(PlanSettingsService::class)->update($record['key'], (string) $data['name'], $this->limitsFrom($data), auth()->user(), (string) $data['reason']);
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

                Notification::make()->title('Plan updated')->body($this->describe($impact))->success()->send();
                $action->success();
            });
    }

    private function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset to configured')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (array $record) => $this->canManage() && $record['customized'] === 'Edited')
            ->requiresConfirmation()
            ->modalHeading('Reset this plan?')
            ->modalDescription('The name and limits go back to the billing configuration. Existing subscribers see the change immediately.')
            ->schema([
                Textarea::make('reason')->label('Reason (kept in the internal audit log)')->required()
                    ->minLength(PlanSettingsService::MIN_REASON)->maxLength(PlanSettingsService::MAX_REASON)->rows(3),
            ])
            ->action(function (array $record, array $data, Action $action) {
                try {
                    app(PlanSettingsService::class)->reset($record['key'], auth()->user(), (string) $data['reason']);
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function limitsFrom(array $data): array
    {
        return collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => $data['limit_'.$key->value] ?? null])->all();
    }

    private function impactText(string $planKey, Get $get): string
    {
        try {
            $service = app(PlanSettingsService::class);
            $limits = $service->validate($planKey, (string) $get('name'), $this->limitsFrom(collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $k) => ['limit_'.$k->value => $get('limit_'.$k->value)])->all()))['limits'];

            return 'Effect: '.$this->describe($service->impact($planKey, $limits));
        } catch (\Throwable) {
            return 'Fix the highlighted values to see the effect on existing subscribers.';
        }
    }

    /** @param array{subscribers: int, trialing: int, over: array<string, int>, capped: bool} $impact */
    private function describe(array $impact): string
    {
        $text = "Applies immediately to {$impact['subscribers']} active subscriber(s)".($impact['trialing'] > 0 ? " and {$impact['trialing']} sign-up trial(s)" : '').'. Nothing is deleted and no billing is changed.';

        foreach ($impact['over'] as $key => $count) {
            if ($count > 0) {
                $text .= " {$count} organization(s) are already above the new ".LimitKey::from($key)->label().' limit and cannot add more until they are under it.';
            }
        }

        return $text.($impact['capped'] ? ' (Only the first 200 subscribers were checked.)' : '');
    }
}
