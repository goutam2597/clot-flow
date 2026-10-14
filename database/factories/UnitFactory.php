<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Unit> */
class UnitFactory extends Factory
{
    /**
     * A small, realistic set of units of measure.
     *
     * `short_code` stays unique via a per-process counter rather than Faker's
     * `unique()`: the vocabulary here is deliberately short, and a suite that
     * creates a few hundred products would otherwise exhaust the pool.
     */
    protected static int $sequence = 0;

    protected static array $vocabulary = [
        ['name' => 'Each', 'short_code' => 'EA', 'decimal_places' => 0],
        ['name' => 'Box', 'short_code' => 'BOX', 'decimal_places' => 0],
        ['name' => 'Pack', 'short_code' => 'PK', 'decimal_places' => 0],
        ['name' => 'Kilogram', 'short_code' => 'KG', 'decimal_places' => 3],
        ['name' => 'Gram', 'short_code' => 'G', 'decimal_places' => 2],
        ['name' => 'Litre', 'short_code' => 'L', 'decimal_places' => 2],
        ['name' => 'Millilitre', 'short_code' => 'ML', 'decimal_places' => 1],
        ['name' => 'Metre', 'short_code' => 'M', 'decimal_places' => 2],
        ['name' => 'Dozen', 'short_code' => 'DOZ', 'decimal_places' => 0],
    ];

    public function definition(): array
    {
        $index = static::$sequence++ % count(static::$vocabulary);
        $unit = static::$vocabulary[$index];

        return [
            'name' => $unit['name'],
            'short_code' => $unit['short_code'],
            'decimal_places' => $unit['decimal_places'],
            'is_active' => true,
        ];
    }

    /** A unit that allows fractional quantities, e.g. 1.5 kg. */
    public function fractional(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Kilogram',
            'short_code' => 'KG',
            'decimal_places' => 3,
        ]);
    }
}
