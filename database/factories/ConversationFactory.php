<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
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
            'subject' => 'HVAC Estimate',
            'status' => ConversationStatus::Open,
        ];
    }
}
