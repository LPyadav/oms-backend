<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('CAT-????-######')),
            'name' => fake()->words(3, true),
            'price' => fake()->randomElement(['25.00', '49.50', '99.95']),
            'tax_rate' => fake()->randomElement(['0.00', '5.00', '18.00']),
            'stock_quantity' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
