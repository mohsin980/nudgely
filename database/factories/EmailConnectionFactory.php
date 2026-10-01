<?php

namespace Database\Factories;

use App\Enums\EmailProvider;
use App\Enums\EmailVerificationStatus;
use App\Models\EmailConnection;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailConnection>
 */
class EmailConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $business = fake()->unique()->lastName().' '.fake()->randomElement(['HVAC', 'Plumbing', 'Roofing', 'Electric', 'Landscaping']);
        $domain = Str::slug($business, '').'.com';

        return [
            'organization_id' => Organization::factory(),
            'provider' => EmailProvider::Postmark,
            'domain' => $domain,
            'sender_email' => 'sales@'.$domain,
            'sender_name' => $business,
            'provider_domain_id' => null,
            'verification_status' => EmailVerificationStatus::Pending,
            'is_default' => false,
            'verified_at' => null,
        ];
    }

    /**
     * Indicate that the connection's domain has been verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => EmailVerificationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    /**
     * Indicate that the connection is the organization's default.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
