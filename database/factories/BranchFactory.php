<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Branch> */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name . ' Branch',
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'address_line' => fake()->optional()->streetAddress(),
            'city' => $name,
            'country' => fake()->country(),
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
