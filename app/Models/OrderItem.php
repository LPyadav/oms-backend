<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $position
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $subtotal_minor
 * @property int $item_discount_minor
 * @property int $allocated_order_discount_minor
 * @property int $tax_rate_basis_points
 * @property int $tax_minor
 * @property int $line_total_minor
 */
#[Fillable(['product_id', 'quantity', 'item_discount_minor'])]
final class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'item_discount_minor' => 'integer',
            'allocated_order_discount_minor' => 'integer',
            'tax_rate_basis_points' => 'integer',
            'tax_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }
}
