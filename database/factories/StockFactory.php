<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Warehouse> */
class StockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'product_id' => \App\Models\Product::factory(),
            'product_variant_id' => null,
            'quantity' => '0.0000',
            'avg_cost' => '0.0000',
        ];
    }

    public function quantity(float $qty, float $avgCost = 0): static
    {
        return $this->state(fn () => [
            'quantity' => number_format($qty, 4, '.', ''),
            'avg_cost' => number_format($avgCost, 4, '.', ''),
        ]);
    }
}
