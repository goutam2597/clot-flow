<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Warehouse> */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->words(2, true) . ' Warehouse',
            'code' => strtoupper(fake()->unique()->lexify('WH-???')),
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->address(),
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
