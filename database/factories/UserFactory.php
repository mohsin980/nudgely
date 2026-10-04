<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'role' => OrganizationRole::Staff,
            'status' => MemberStatus::Active,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * The organization's owner (each organization has exactly one).
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => OrganizationRole::Owner]);
    }

    /**
     * Alias of owner(): the person who runs the business account.
     */
    public function admin(): static
    {
        return $this->owner();
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => ['role' => OrganizationRole::Manager]);
    }

    public function staff(): static
    {
        return $this->state(fn (array $attributes) => ['role' => OrganizationRole::Staff]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => MemberStatus::Suspended, 'suspended_at' => now()]);
    }
}
