<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.Str::ulid(),
            'customer_id' => Customer::factory(),
            'assignee_id' => null,
            'created_by' => User::factory(),
            'order_date' => now()->toDateString(),
            'priority' => OrderPriority::Normal,
            'status' => OrderStatus::Draft,
            'currency' => 'USD',
            'subtotal_minor' => 0,
            'order_discount_minor' => 0,
            'discount_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => 0,
            'version' => 1,
            'completed_at' => null,
        ];
    }
}
