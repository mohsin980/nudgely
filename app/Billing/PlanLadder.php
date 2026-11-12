<?php

namespace App\Billing;

use App\Enums\Billing\LimitKey;

/**
 * The rule that keeps upgrades worth paying for: a dearer plan must allow at least as much of every limit as the
 * next cheaper one. Otherwise a business that upgrades could end up with less than it had.
 */
final class PlanLadder
{
    /**
     * @param  list<Plan>  $plans  Every plan as it would be after the change.
     * @return array<string, string> Limit field => message, empty when the ladder holds.
     */
    public static function errors(array $plans): array
    {
        $ladder = collect($plans)->sortBy(fn (Plan $plan) => $plan->priceCents)->values();
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
                    $errors["limits.{$key->value}"] = "{$dearer->name} must allow at least as many {$key->noun()} as {$cheaper->name}; otherwise upgrading would reduce what the business gets.";
                }
            }
        }

        return $errors;
    }
}
