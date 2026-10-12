<?php

namespace App\Services\Platform;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Platform\PlanSettingsException;
use App\Models\Organization;
use App\Models\PlanSetting;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\UsageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes what a plan allows, from the Super Admin panel.
 *
 * Editable: the display name and the five limits (all enforced server-side by EntitlementService / UsageService).
 * Not editable here: price, interval, features and the provider price ID. Those are defined in config/billing.php
 * and (for what is charged) owned by Stripe; no billing-provider call is ever made from this service, and no
 * subscription row is touched, so existing subscribers keep their billing exactly as it was.
 *
 * What a change means for existing subscribers: limit changes apply immediately to everyone on that plan. Raising
 * a limit gives room at once. Lowering never deletes or disables anything: organizations already above the new
 * limit keep their data and are only stopped from adding more until they are back under it.
 */
class PlanSettingsService
{
    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public const MAX_LIMIT = 1_000_000;

    /** How many subscriber organizations are inspected when previewing a lowered limit. */
    private const PREVIEW_CAP = 200;

    public function __construct(private readonly PlanCatalog $plans, private readonly UsageService $usage) {}

    /**
     * Check a proposed name and limits for one plan and return them cleaned.
     *
     * @param  array<string, mixed>  $limits  LimitKey value => whole number, or null/'' for unlimited
     * @return array{name: string, limits: array<string, int|null>}
     *
     * @throws PlanSettingsException
     * @throws ValidationException
     */
    public function validate(string $planKey, string $name, array $limits): array
    {
        $plan = $this->plans->find($planKey) ?? throw PlanSettingsException::unknownPlan();
        $errors = [];

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 40 || preg_match('/[<>]/', $name)) {
            $errors['name'] = 'The name must be 2 to 40 characters and cannot contain < or >.';
        } elseif (collect($this->plans->all())->contains(fn (Plan $other) => $other->key !== $planKey && mb_strtolower($other->name) === mb_strtolower($name))) {
            $errors['name'] = 'Another plan already uses that name.';
        }

        $clean = [];

        foreach (LimitKey::cases() as $key) {
            $raw = $limits[$key->value] ?? null;

            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $clean[$key->value] = null;

                continue;
            }

            // Number inputs arrive as floats (2500.0): fine when they are whole numbers.
            if (is_float($raw) && $raw == floor($raw) && abs($raw) < PHP_INT_MAX) {
                $raw = (int) $raw;
            }

            if (is_string($raw) && ! preg_match('/^\d+$/', trim($raw)) || (! is_string($raw) && ! is_int($raw))) {
                $errors["limits.{$key->value}"] = "{$key->label()} must be a whole number, or empty for unlimited.";

                continue;
            }

            $value = (int) $raw;

            if ($value > self::MAX_LIMIT) {
                $errors["limits.{$key->value}"] = $key->label().' cannot be more than '.number_format(self::MAX_LIMIT).'. Leave it empty for unlimited.';
            } elseif ($key === LimitKey::TeamMembers && $value < 1) {
                $errors["limits.{$key->value}"] = 'A plan must allow at least 1 team member, or the business owner would be locked out.';
            } else {
                $clean[$key->value] = $value;
            }
        }

        if ($errors === []) {
            $errors = $this->ladderErrors($plan, $clean);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['name' => $name, 'limits' => $clean];
    }

    /**
     * What saving these limits would mean for people already on the plan (read-only).
     *
     * @param  array<string, int|null>  $limits
     * @return array{subscribers: int, trialing: int, over: array<string, int>, capped: bool}
     */
    public function impact(string $planKey, array $limits): array
    {
        $plan = $this->plans->find($planKey) ?? throw PlanSettingsException::unknownPlan();

        $granting = array_map(fn (SubscriptionStatus $status) => $status->value, array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->grantsAccess()));
        $subscribers = Subscription::query()->current()->where('plan', $planKey)->whereIn('status', $granting);

        $trialing = $planKey === config('billing.signup_trial_plan')
            ? Organization::query()->where('trial_ends_at', '>', now())->whereDoesntHave('subscriptions', fn ($q) => $q->current())->count()
            : 0;

        $lowered = collect(LimitKey::cases())->filter(function (LimitKey $key) use ($plan, $limits) {
            $old = $plan->limit($key);
            $new = $limits[$key->value] ?? null;

            return $new !== null && ($old === null || $new < $old);
        });

        $over = $lowered->mapWithKeys(fn (LimitKey $key) => [$key->value => 0])->all();
        $capped = false;

        if ($lowered->isNotEmpty()) {
            $organizations = Organization::query()->whereIn('id', (clone $subscribers)->select('organization_id'))->limit(self::PREVIEW_CAP + 1)->get();
            $capped = $organizations->count() > self::PREVIEW_CAP;

            foreach ($organizations->take(self::PREVIEW_CAP) as $organization) {
                foreach ($lowered as $key) {
                    if ($this->usage->usage($organization, $key) > $limits[$key->value]) {
                        $over[$key->value]++;
                    }
                }
            }
        }

        return ['subscribers' => (clone $subscribers)->count(), 'trialing' => $trialing, 'over' => $over, 'capped' => $capped];
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array{subscribers: int, trialing: int, over: array<string, int>, capped: bool}
     *
     * @throws AuthorizationException
     * @throws PlanSettingsException
     * @throws ValidationException
     */
    public function update(string $planKey, string $name, array $limits, User $actor, string $reason): array
    {
        Gate::forUser($actor)->authorize('manage_plans');

        $reason = $this->cleanReason($reason);
        $clean = $this->validate($planKey, $name, $limits);
        $plan = $this->plans->find($planKey);

        $changes = $this->diff($plan, $clean['name'], $clean['limits']);

        if ($changes === []) {
            throw PlanSettingsException::noChanges();
        }

        $impact = $this->impact($planKey, $clean['limits']);

        DB::transaction(function () use ($planKey, $clean, $actor, $reason, $changes) {
            $configured = $this->plans->configured()[$planKey];

            $setting = PlanSetting::query()->where('plan_key', $planKey)->lockForUpdate()->first() ?? new PlanSetting;
            $setting->forceFill([
                'plan_key' => $planKey,
                'name' => $clean['name'] === $configured->name ? null : $clean['name'],
                'limits' => $clean['limits'],
                'updated_by' => $actor->id,
            ])->save();

            PlatformAuditLog::record('plan_updated', $actor, null, $reason, null, ['plan' => $planKey, 'fields' => $changes]);
        });

        $this->plans->refresh();

        return $impact;
    }

    /**
     * Drop the administrator override so the plan goes back to its configured name and limits.
     *
     * @throws AuthorizationException
     * @throws PlanSettingsException
     */
    public function reset(string $planKey, User $actor, string $reason): void
    {
        Gate::forUser($actor)->authorize('manage_plans');

        $reason = $this->cleanReason($reason);
        $configured = $this->plans->configured()[$planKey] ?? throw PlanSettingsException::unknownPlan();
        $current = $this->plans->find($planKey);

        $changes = $this->diff($current, $configured->name, $configured->limits);

        DB::transaction(function () use ($planKey, $actor, $reason, $changes) {
            PlanSetting::query()->where('plan_key', $planKey)->delete();
            PlatformAuditLog::record('plan_reset', $actor, null, $reason, null, ['plan' => $planKey, 'fields' => $changes]);
        });

        $this->plans->refresh();
    }

    /**
     * Field => [from, to] for everything that differs from the plan as it is now.
     *
     * @param  array<string, int|null>  $limits
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(Plan $plan, string $name, array $limits): array
    {
        $changes = [];

        if ($plan->name !== $name) {
            $changes['name'] = ['from' => $plan->name, 'to' => $name];
        }

        foreach (LimitKey::cases() as $key) {
            if ($plan->limit($key) !== ($limits[$key->value] ?? null)) {
                $changes["limits.{$key->value}"] = ['from' => $plan->limit($key), 'to' => $limits[$key->value] ?? null];
            }
        }

        return $changes;
    }

    /**
     * A dearer plan must not allow less than a cheaper one, or an upgrade would be a downgrade.
     *
     * @param  array<string, int|null>  $limits
     * @return array<string, string>
     */
    private function ladderErrors(Plan $edited, array $limits): array
    {
        $ladder = collect($this->plans->all())
            ->map(fn (Plan $plan) => $plan->key === $edited->key ? new Plan($plan->key, $plan->name, $plan->priceCents, $plan->interval, $limits, $plan->features, $plan->providerPriceId) : $plan)
            ->sortBy(fn (Plan $plan) => $plan->priceCents)->values();

        $errors = [];

        for ($i = 1; $i < $ladder->count(); $i++) {
            [$cheaper, $dearer] = [$ladder[$i - 1], $ladder[$i]];

            if ($cheaper->priceCents === $dearer->priceCents) {
                continue;
            }

            foreach (LimitKey::cases() as $key) {
                $low = $cheaper->limit($key);
                $high = $dearer->limit($key);

                if ($low !== null && $high !== null && $high < $low || $low === null && $high !== null) {
                    $errors["limits.{$key->value}"] = "{$dearer->name} must allow at least as many {$key->noun()} as {$cheaper->name}; otherwise upgrading would reduce what a business gets.";
                }
            }
        }

        return $errors;
    }

    /**
     * @throws PlanSettingsException
     */
    private function cleanReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $length = mb_strlen($reason);

        if ($length < self::MIN_REASON || $length > self::MAX_REASON) {
            throw PlanSettingsException::invalidReason(self::MIN_REASON, self::MAX_REASON);
        }

        return $reason;
    }
}
