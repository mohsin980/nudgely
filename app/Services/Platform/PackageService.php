<?php

namespace App\Services\Platform;

use App\Billing\AddonCatalog;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\PlanLadder;
use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\LimitKey;
use App\Exceptions\Platform\PlanSettingsException;
use App\Models\PlanSetting;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Billing\BillingProviderManager;
use App\Services\Platform\Concerns\AuditsDeniedAttempts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plans and add-ons ("packages") created and edited by an administrator: name, description, prices in each
 * currency, the Stripe price for each currency, features, limits and whether they are sold.
 *
 * Every save is checked before it is written: values must make sense, Stripe price IDs must not be used by another
 * package, and each Stripe price must be an active monthly price with the same currency and amount. A changed
 * Stripe price keeps its old ID as a legacy ID, so businesses already on it stay on their plan.
 */
final class PackageService
{
    use AuditsDeniedAttempts;

    public const KIND_PLAN = 'plan';

    public const KIND_ADDON = 'addon';

    private const CURRENCIES = ['USD', 'PKR'];

    private const PRICE_ID = '/^price_[A-Za-z0-9_]+$/';

    public function __construct(
        private readonly PlanCatalog $plans,
        private readonly AddonCatalog $addons,
        private readonly BillingProviderManager $providers,
    ) {}

    /**
     * Create a package (when $key is null, $data['key'] names it) or change one.
     *
     * @param  array<string, mixed>  $data  name, description, active, prices (USD, PKR), provider_price_ids (USD, PKR),
     *                                      features, limits (plans: LimitKey => int|null; add-ons: increments),
     *                                      applies_to (add-ons), key (new packages only)
     * @return array<string, array{from: mixed, to: mixed}> The fields that changed.
     *
     * @throws AuthorizationException|PlanSettingsException|ValidationException
     */
    public function save(User $actor, string $kind, ?string $key, array $data, string $reason): array
    {
        $this->assertKind($kind);
        $this->authorizeAudited($actor, 'manage_plans', [], 'package_saved', ['type' => $kind, 'id' => $key ?? 'new']);

        $reason = $this->cleanReason($reason);
        $key ??= (string) ($data['key'] ?? '');
        $before = $key === '' ? null : $this->current($kind, $key);
        $clean = $this->validate($kind, $key, $data, $before);

        $changes = $this->diff($before['fields'] ?? [], $clean['fields']);

        if ($before !== null && $changes === []) {
            throw PlanSettingsException::noChanges();
        }

        $this->verifyStripePrices($kind, $key, $clean, $before);

        DB::transaction(function () use ($kind, $key, $clean, $actor, $reason, $changes) {
            $row = PlanSetting::query()->where('plan_key', $key)->lockForUpdate()->first() ?? new PlanSetting;
            $row->forceFill([
                'plan_key' => $key,
                'kind' => $kind,
                'name' => $clean['fields']['name'],
                'description' => $clean['fields']['description'],
                'active' => $clean['fields']['active'],
                'prices' => $clean['fields']['prices'],
                'provider_price_ids' => $clean['fields']['provider_price_ids'],
                'legacy_provider_price_ids' => $clean['legacy'],
                'features' => $clean['fields']['features'],
                'limits' => $clean['fields']['limits'],
                'applies_to' => $clean['fields']['applies_to'] ?? null,
                'updated_by' => $actor->id,
            ])->save();

            PlatformAuditLog::record('package_saved', $actor, null, $reason, null,
                ['package' => $key, 'kind' => $kind, 'fields' => $changes], $kind === self::KIND_ADDON ? 'addon' : 'plan', $key);
        });

        $this->refresh();

        return $changes;
    }

    /**
     * Put a configured package back to its configuration. Its old Stripe price IDs are kept as legacy IDs, so
     * subscribers on them are not lost. Packages that exist only in the admin panel are deactivated instead.
     *
     * @throws AuthorizationException|PlanSettingsException
     */
    public function reset(User $actor, string $kind, string $key, string $reason): void
    {
        $this->assertKind($kind);
        $this->authorizeAudited($actor, 'manage_plans', [], 'package_reset', ['type' => $kind, 'id' => $key]);

        $reason = $this->cleanReason($reason);
        $isConfigured = $kind === self::KIND_PLAN
            ? isset(config('billing.plans')[$key])
            : isset(config('billing.addons')[$key]);

        if (! $isConfigured) {
            throw PlanSettingsException::unknownPlan();
        }

        DB::transaction(function () use ($kind, $key, $actor, $reason) {
            $row = PlanSetting::query()->where('plan_key', $key)->lockForUpdate()->first();

            if ($row === null) {
                return;
            }

            $legacy = array_values(array_unique([...($row->legacy_provider_price_ids ?? []), ...array_values($row->provider_price_ids ?? [])]));

            if ($legacy === []) {
                $row->delete();
            } else {
                $row->forceFill([
                    'name' => null, 'description' => null, 'active' => true, 'prices' => null, 'provider_price_ids' => null,
                    'features' => null, 'limits' => null, 'applies_to' => null, 'legacy_provider_price_ids' => $legacy,
                    'updated_by' => $actor->id,
                ])->save();
            }

            PlatformAuditLog::record('package_reset', $actor, null, $reason, null, ['package' => $key, 'kind' => $kind], $kind === self::KIND_ADDON ? 'addon' : 'plan', $key);
        });

        $this->refresh();
    }

    /**
     * Every problem with the values, as field => messages. Returns the clean values to store.
     *
     * @param  array<string, mixed>|null  $before  The package as it is now, or null for a new one.
     * @return array{fields: array<string, mixed>, legacy: list<string>}
     *
     * @throws ValidationException
     */
    private function validate(string $kind, string $key, array $data, ?array $before): array
    {
        $errors = [];
        $isPlan = $kind === self::KIND_PLAN;

        if ($before === null && ! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key)) {
            $errors['key'][] = 'Use lowercase letters, numbers and underscores, starting with a letter (2 to 32 characters).';
        } elseif ($before === null && $this->keyTaken($key)) {
            $errors['key'][] = 'A plan or add-on with this key already exists.';
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 40) {
            $errors['name'][] = 'The name is required and can be at most 40 characters.';
        }

        $description = trim((string) ($data['description'] ?? ''));

        if (mb_strlen($description) > 500) {
            $errors['description'][] = 'The description can be at most 500 characters.';
        }

        $prices = $this->cleanPrices($data['prices'] ?? [], $isPlan, $errors);
        $providerIds = $this->cleanProviderIds($data['provider_price_ids'] ?? [], $errors);
        $features = $this->cleanFeatures($data['features'] ?? [], $errors);
        $active = (bool) ($data['active'] ?? true);

        if (! $active && $key === $this->plans->default()->key) {
            $errors['active'][] = 'The default plan cannot be turned off.';
        }

        $limits = $isPlan ? $this->cleanPlanLimits($data['limits'] ?? [], $errors) : [];
        $appliesTo = $isPlan ? null : $this->cleanAppliesTo($data['applies_to'] ?? [], $errors);
        $increments = $isPlan ? [] : $this->cleanIncrements($data['limits'] ?? [], $appliesTo ?? [], $errors);

        foreach ($providerIds as $currency => $id) {
            if (($this->idsInUse($key)[$id] ?? null) !== null) {
                $errors["provider_price_ids.{$currency}"][] = "The Stripe price {$id} is already used by another plan or add-on.";
            }
        }

        if ($isPlan && $errors === [] && isset($prices['USD'])) {
            $errors = PlanLadder::errors([
                ...collect($this->plans->all())->reject(fn (Plan $plan) => $plan->key === $key)->values()->all(),
                new Plan($key, $name, $prices['USD'], BillingInterval::Month, $limits, $features, null),
            ]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $legacy = $this->legacyIds($before, $providerIds);

        return [
            'fields' => [
                'name' => $name,
                'description' => $description === '' ? null : $description,
                'active' => $active,
                'prices' => $prices,
                'provider_price_ids' => $providerIds,
                'features' => $features,
                'limits' => $isPlan ? $limits : $increments,
                'applies_to' => $appliesTo,
            ],
            'legacy' => $legacy,
        ];
    }

    /**
     * Minor units per currency. USD is required; PKR is optional.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return array<string, int>
     */
    private function cleanPrices(array $input, bool $isPlan, array &$errors): array
    {
        $clean = [];

        foreach (self::CURRENCIES as $currency) {
            $value = $input[$currency] ?? null;

            if ($value === null || $value === '') {
                if ($currency === 'USD') {
                    $errors['prices.USD'][] = 'The USD price is required.';
                }

                continue;
            }

            if (! (is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 0) {
                $errors["prices.{$currency}"][] = "The {$currency} price must be a whole amount in minor units.";

                continue;
            }

            $clean[$currency] = (int) $value;
        }

        if (isset($clean['USD']) && ! $isPlan && $clean['USD'] <= 0) {
            $errors['prices.USD'][] = 'An add-on needs a price above zero.';
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>
     */
    private function cleanProviderIds(array $input, array &$errors): array
    {
        $clean = [];

        foreach (self::CURRENCIES as $currency) {
            $id = trim((string) ($input[$currency] ?? ''));

            if ($id === '') {
                continue;
            }

            if (! preg_match(self::PRICE_ID, $id)) {
                $errors["provider_price_ids.{$currency}"][] = 'A Stripe price ID starts with "price_".';

                continue;
            }

            $clean[$currency] = $id;
        }

        return $clean;
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function cleanFeatures(array $input, array &$errors): array
    {
        $known = array_keys(config('billing.feature_labels', []));
        $features = array_values(array_unique(array_filter(array_map('strval', $input))));

        foreach ($features as $feature) {
            if (! in_array($feature, $known, true)) {
                $errors['features'][] = "Unknown feature [{$feature}].";
            }
        }

        return $features;
    }

    /**
     * Every limit must be set: a whole number, or null for unlimited.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return array<string, int|null>
     */
    private function cleanPlanLimits(array $input, array &$errors): array
    {
        $limits = [];

        foreach (LimitKey::cases() as $limit) {
            $value = $input[$limit->value] ?? null;

            if ($value === null || $value === '') {
                $limits[$limit->value] = null;

                continue;
            }

            if (! is_numeric($value) || (int) $value != $value || (int) $value < 0 || (int) $value > PlanSettingsService::MAX_LIMIT) {
                $errors["limits.{$limit->value}"][] = $limit->label().' must be a whole number, or empty for unlimited.';

                continue;
            }

            $limits[$limit->value] = (int) $value;
        }

        return $limits;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function cleanAppliesTo(array $input, array &$errors): array
    {
        $planKeys = array_keys($this->plans->all());
        $appliesTo = array_values(array_unique(array_filter(array_map('strval', $input))));

        if ($appliesTo === []) {
            $errors['applies_to'][] = 'Choose at least one plan this add-on can be bought with.';
        }

        foreach ($appliesTo as $planKey) {
            if (! in_array($planKey, $planKeys, true)) {
                $errors['applies_to'][] = "Unknown plan [{$planKey}].";
            }
        }

        return $appliesTo;
    }

    /**
     * An add-on's limits are extra amounts. Each one must exist on every plan the add-on can be bought with.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $appliesTo
     * @param  array<string, list<string>>  $errors
     * @return array<string, int>
     */
    private function cleanIncrements(array $input, array $appliesTo, array &$errors): array
    {
        $increments = [];

        foreach (LimitKey::cases() as $limit) {
            $value = $input[$limit->value] ?? null;

            if ($value === null || $value === '' || (int) $value === 0) {
                continue;
            }

            if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > PlanSettingsService::MAX_LIMIT) {
                $errors["limits.{$limit->value}"][] = $limit->label().' extra must be a whole number above zero.';

                continue;
            }

            foreach ($appliesTo as $planKey) {
                $plan = $this->plans->find($planKey);

                if ($plan !== null && ! array_key_exists($limit->value, $plan->limits)) {
                    $errors["limits.{$limit->value}"][] = $limit->label()." does not exist on the {$plan->name} plan.";
                }
            }

            $increments[$limit->value] = (int) $value;
        }

        if ($increments === []) {
            $errors['limits'][] = 'An add-on must add at least one limit.';
        }

        return $increments;
    }

    /**
     * The package as it is saved now, or null when it doesn't exist yet.
     *
     * @return array{fields: array<string, mixed>, legacy: list<string>}|null
     */
    private function current(string $kind, string $key): ?array
    {
        if ($kind === self::KIND_PLAN) {
            $plan = $this->plans->find($key);

            return $plan === null ? null : [
                'fields' => [
                    'name' => $plan->name,
                    'description' => $plan->description === '' ? null : $plan->description,
                    'active' => $plan->active,
                    'prices' => ['USD' => $plan->priceCents, ...$plan->prices],
                    'provider_price_ids' => array_filter(['USD' => $plan->providerPriceId, ...$plan->providerPriceIds]),
                    'features' => $plan->features,
                    'limits' => array_map(fn ($value) => $value, $plan->limits),
                    'applies_to' => null,
                ],
                'legacy' => $plan->legacyProviderPriceIds,
            ];
        }

        $addon = $this->addons->find($key);

        if ($addon === null) {
            return null;
        }

        return [
            'fields' => [
                'name' => $addon['name'],
                'description' => blank($addon['description'] ?? null) ? null : $addon['description'],
                'active' => (bool) ($addon['active'] ?? true),
                'prices' => ['USD' => $addon['price_cents'], ...($addon['prices'] ?? [])],
                'provider_price_ids' => array_filter(['USD' => $addon['provider_price_id'] ?? null, ...($addon['provider_price_ids'] ?? [])]),
                'features' => $addon['features'] ?? [],
                'limits' => $addon['limits'] ?? [],
                'applies_to' => $addon['applies_to'] ?? [],
            ],
            'legacy' => $addon['legacy_provider_price_ids'] ?? [],
        ];
    }

    /**
     * Fields that differ between the saved package and the new values: field => [from, to].
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $from, array $to): array
    {
        $changes = [];

        foreach ($to as $field => $value) {
            if (($from[$field] ?? null) != $value) {
                $changes[$field] = ['from' => $from[$field] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * Every Stripe price the package now uses that is new to it must be checked against Stripe.
     *
     * @param  array{fields: array<string, mixed>, legacy: list<string>}  $clean
     * @param  array{fields: array<string, mixed>, legacy: list<string>}|null  $before
     *
     * @throws ValidationException
     */
    private function verifyStripePrices(string $kind, string $key, array $clean, ?array $before): void
    {
        if (config('billing.provider') !== 'stripe') {
            return;
        }

        $provider = $this->providers->driver();
        $errors = [];

        foreach ($clean['fields']['provider_price_ids'] as $currency => $id) {
            if (($before['fields']['provider_price_ids'][$currency] ?? null) === $id) {
                continue;
            }

            $amount = $currency === 'USD'
                ? (int) $clean['fields']['prices']['USD']
                : (int) ($clean['fields']['prices'][$currency] ?? -1);

            $message = $amount < 0
                ? "Set the {$currency} price before its Stripe price ID."
                : $provider->verifyPrice($id, $currency, $amount);

            if ($message !== null) {
                $errors["provider_price_ids.{$currency}"][] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Old Stripe prices are kept as legacy IDs when a package changes to a new one.
     *
     * @param  array{fields: array<string, mixed>, legacy: list<string>}|null  $before
     * @param  array<string, string>  $providerIds
     * @return list<string>
     */
    private function legacyIds(?array $before, array $providerIds): array
    {
        $legacy = $before['legacy'] ?? [];

        foreach ($before['fields']['provider_price_ids'] ?? [] as $currency => $oldId) {
            if (($providerIds[$currency] ?? null) !== $oldId) {
                $legacy[] = $oldId;
            }
        }

        return array_values(array_unique($legacy));
    }

    /**
     * Every Stripe price ID already used by a package other than this one, keyed by ID.
     *
     * @return array<string, true>
     */
    private function idsInUse(string $key): array
    {
        $ids = [];

        foreach ([...$this->plansAsPackages(), ...$this->addonsAsPackages()] as $package) {
            if ($package['key'] === $key) {
                continue;
            }

            foreach ($package['ids'] as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<array{key: string, ids: list<string>}>
     */
    private function plansAsPackages(): array
    {
        return collect($this->plans->all())->map(fn (Plan $plan, string $key) => [
            'key' => $key,
            'ids' => array_values(array_filter([$plan->providerPriceId, ...array_values($plan->providerPriceIds), ...$plan->legacyProviderPriceIds])),
        ])->values()->all();
    }

    /**
     * @return list<array{key: string, ids: list<string>}>
     */
    private function addonsAsPackages(): array
    {
        return collect($this->addons->all())->map(fn (array $addon, string $key) => [
            'key' => $key,
            'ids' => array_values(array_filter([$addon['provider_price_id'] ?? null, ...array_values($addon['provider_price_ids'] ?? []), ...($addon['legacy_provider_price_ids'] ?? [])])),
        ])->values()->all();
    }

    private function keyTaken(string $key): bool
    {
        return isset($this->plans->all()[$key]) || isset($this->addons->all()[$key]);
    }

    private function refresh(): void
    {
        $this->plans->refresh();
        $this->addons->refresh();
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, [self::KIND_PLAN, self::KIND_ADDON], true)) {
            throw new \InvalidArgumentException("Unknown package kind [{$kind}].");
        }
    }

    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < PlanSettingsService::MIN_REASON || mb_strlen($reason) > PlanSettingsService::MAX_REASON) {
            throw PlanSettingsException::invalidReason(PlanSettingsService::MIN_REASON, PlanSettingsService::MAX_REASON);
        }

        return $reason;
    }
}
