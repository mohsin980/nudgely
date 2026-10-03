<?php

namespace Database\Factories;

use App\Enums\EstimateStatus;
use App\Models\Customer;
use App\Models\Estimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft estimate without items. Use EstimateService in tests that care about amounts.
 *
 * @extends Factory<Estimate>
 */
class EstimateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'organization_id' => fn (array $attributes) => Customer::find($attributes['customer_id'])->organization_id,
            'estimate_number' => 'EST-'.fake()->unique()->numberBetween(5000, 999999),
            'status' => EstimateStatus::Draft,
            'title' => 'AC Installation',
            'currency' => 'USD',
        ];
    }
}
