<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPriceMinor = 2500;

        return [
            'order_id' => Order::factory()->state([
                'subtotal_minor' => $unitPriceMinor,
                'grand_total_minor' => $unitPriceMinor,
            ]),
            'product_id' => Product::factory()->state(['price' => '25.00', 'tax_rate' => '0.00']),
            'position' => 1,
            'quantity' => 1,
            'unit_price_minor' => $unitPriceMinor,
            'subtotal_minor' => $unitPriceMinor,
            'item_discount_minor' => 0,
            'allocated_order_discount_minor' => 0,
            'tax_rate_basis_points' => 0,
            'tax_minor' => 0,
            'line_total_minor' => $unitPriceMinor,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (OrderItem $item): void {
            $attributes = $item->getAttributes();
            if (isset($attributes['product_name'], $attributes['product_sku'])) {
                return;
            }

            $product = $item->product()->firstOrFail();

            $item->forceFill([
                'product_name' => $attributes['product_name'] ?? $product->name,
                'product_sku' => $attributes['product_sku'] ?? $product->sku,
            ]);
        });
    }
}
