<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Unit;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'unit_id' => Unit::factory(),
            'sku' => fake()->unique()->bothify('SKU-######'),
            'name' => 'Standard',
            'price_centavos' => 10000,
            'stock' => 0,
            'threshold' => 3,
        ];
    }
}
