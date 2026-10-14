<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'name' => ucfirst($name),
            'code' => strtoupper(fake()->unique()->lexify('CAT-??')),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
