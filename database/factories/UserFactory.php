<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'phone' => fake()->optional()->phoneNumber(),
            'status' => 'active',
            'theme' => 'light',
            'locale' => null,
            'branch_id' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'inactive']);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'suspended']);
    }

    public function dark(): static
    {
        return $this->state(fn (array $attributes) => ['theme' => 'dark']);
    }

    public function atBranch(Branch|int $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branch instanceof Branch ? $branch->id : $branch,
        ]);
    }
}
