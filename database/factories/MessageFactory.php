<?php

namespace Database\Factories;

use App\Enums\EmailProvider;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\EmailConnection;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_connection_id' => EmailConnection::factory()->verified(),
            'organization_id' => fn (array $attributes) => EmailConnection::find($attributes['email_connection_id'])->organization_id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'provider' => EmailProvider::Postmark,
            'from_address' => fn (array $attributes) => EmailConnection::find($attributes['email_connection_id'])->sender_email,
            'from_name' => fn (array $attributes) => EmailConnection::find($attributes['email_connection_id'])->sender_name,
            'to_address' => fake()->safeEmail(),
            'to_name' => fake()->name(),
            'subject' => 'Your estimate',
            'body_text' => 'Thanks for your interest.',
            'body_html' => '<p>Thanks for your interest.</p>',
            'status' => MessageStatus::Queued,
        ];
    }
}
