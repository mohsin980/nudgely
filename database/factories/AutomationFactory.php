<?php

namespace Database\Factories;

use App\Enums\Automation\AutomationStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Models\Automation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Customer Ready to Book',
            'description' => null,
            'status' => AutomationStatus::Draft,
            'trigger_type' => AutomationTriggerType::CustomerReplyClassified,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => AutomationStatus::Active]);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => AutomationStatus::Paused]);
    }
}
