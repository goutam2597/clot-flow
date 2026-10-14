<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'brand_id' => null,
            'unit_id' => Unit::factory(),
            'tax_rate_id' => null,
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-???')),
            'barcode' => fake()->unique()->numerify('##########'),
            'type' => 'simple',
            'description' => fake()->optional()->sentence(),
            'image_path' => null,
            'cost_price' => '10.0000',
            'selling_price' => '15.0000',
            'track_stock' => true,
            'allow_negative_stock' => false,
            'reorder_level' => 5,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }

    public function service(): static
    {
        // Services are sold without stock movement.
        return $this->state(fn () => ['track_stock' => false, 'reorder_level' => 0]);
    }

    public function priced(float $cost, float $price): static
    {
        return $this->state(fn () => [
            'cost_price' => number_format($cost, 4, '.', ''),
            'selling_price' => number_format($price, 4, '.', ''),
        ]);
    }

    public function withTax(TaxRate $rate): static
    {
        return $this->state(fn () => ['tax_rate_id' => $rate->id]);
    }

    public function allowNegativeStock(): static
    {
        return $this->state(fn () => ['allow_negative_stock' => true]);
    }
}
