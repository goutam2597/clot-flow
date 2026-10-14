<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'code' => strtoupper(fake()->unique()->bothify('CUS-####')),
            'name' => fake()->name(),
            'phone' => fake()->numerify('01#########'),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->optional()->address(),
            'city' => fake()->optional()->city(),
            'tax_number' => fake()->optional()->numerify('##########'),
            'opening_balance' => '0.00',
            'credit_limit' => '0.00',
            'type' => 'regular',
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function walkin(): static
    {
        return $this->state(fn () => ['type' => 'walkin', 'code' => null]);
    }

    public function wholesale(): static
    {
        return $this->state(fn () => ['type' => 'wholesale']);
    }

    public function atBranch(Branch|int $branch): static
    {
        return $this->state(fn () => [
            'branch_id' => $branch instanceof Branch ? $branch->id : $branch,
        ]);
    }
}
