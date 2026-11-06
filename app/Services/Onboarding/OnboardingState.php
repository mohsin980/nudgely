<?php

namespace App\Services\Onboarding;

use App\Enums\Onboarding\OnboardingStep;

/**
 * Where a business is in onboarding, read from what actually exists (never from the browser).
 */
final class OnboardingState
{
    /**
     * @param  array<string, 'done'|'skipped'|'pending'>  $steps  step value => status
     */
    public function __construct(public readonly array $steps, public readonly OnboardingStep $current) {}

    public function status(OnboardingStep $step): string
    {
        return $this->steps[$step->value] ?? 'pending';
    }

    /**
     * Whether this business goes through the step at all (e.g. no WhatsApp step for email-only businesses).
     */
    public function has(OnboardingStep $step): bool
    {
        return array_key_exists($step->value, $this->steps);
    }

    /**
     * @return list<OnboardingStep> the business's steps, in order
     */
    public function stepList(): array
    {
        return array_map(OnboardingStep::from(...), array_keys($this->steps));
    }

    public function isDone(OnboardingStep $step): bool
    {
        return $this->status($step) === 'done';
    }

    /**
     * Share of the steps that are really done (skipped steps are not progress).
     */
    public function percent(): int
    {
        $total = count($this->steps);
        $done = count(array_filter($this->steps, fn (string $status) => $status === 'done'));

        return (int) round($done / max(1, $total) * 100);
    }

    /**
     * The headings with their state: done only when every step under it is done.
     *
     * @return list<array{label: string, status: 'done'|'skipped'|'pending', current: bool, step: string}> step is the group's first step, where the group opens
     */
    public function groups(): array
    {
        $groups = [];

        foreach (array_keys($this->steps) as $value) {
            $step = OnboardingStep::from($value);
            $label = $step->group();
            $status = $this->status($step);
            $groups[$label] ??= ['label' => $label, 'statuses' => [], 'current' => false, 'step' => $step->value];
            $groups[$label]['statuses'][] = $status;
            $groups[$label]['current'] = $groups[$label]['current'] || $step === $this->current;
        }

        return array_values(array_map(fn (array $g) => [
            'label' => $g['label'],
            'status' => ! in_array('pending', $g['statuses'], true) && ! in_array('skipped', $g['statuses'], true) ? 'done'
                : (in_array('pending', $g['statuses'], true) ? 'pending' : 'skipped'),
            'current' => $g['current'],
            'step' => $g['step'],
        ], $groups));
    }

    public function isFinished(): bool
    {
        return $this->current === OnboardingStep::Completed;
    }
}
