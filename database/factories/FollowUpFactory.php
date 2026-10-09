<?php

namespace Database\Factories;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Models\Conversation;
use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    /**
     * A manual follow-up on a conversation, due in two days. Organization and customer
     * follow the conversation, so the three always match.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'organization_id' => fn (array $attributes) => Conversation::find($attributes['conversation_id'])->organization_id,
            'customer_id' => fn (array $attributes) => Conversation::find($attributes['conversation_id'])->customer_id,
            'type' => FollowUpType::Manual,
            'status' => FollowUpStatus::Pending,
            'due_at' => now()->addDays(2),
            'notes' => 'Follow up about the estimate',
        ];
    }

    public function automated(): static
    {
        return $this->state(fn () => [
            'type' => FollowUpType::Automated,
            'notes' => null,
            'subject' => 'Following up on your estimate',
            'body' => "Hi {{customer.first_name}},\n\nJust checking in.\n\n{{business.name}}",
        ]);
    }

    public function due(): static
    {
        return $this->state(fn () => ['status' => FollowUpStatus::Due, 'due_at' => now()->subMinute()]);
    }
}
