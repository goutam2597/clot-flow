<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Supplier> */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SUP-####')),
            'name' => fake()->company(),
            'company' => fake()->company(),
            'phone' => fake()->numerify('01#########'),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->optional()->address(),
            'city' => fake()->optional()->city(),
            'tax_number' => fake()->optional()->numerify('##########'),
            'opening_balance' => '0.00',
            'notes' => null,
            'is_active' => true,
        ];
    }
}
