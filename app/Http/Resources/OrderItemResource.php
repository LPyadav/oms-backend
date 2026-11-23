<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
final class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'position' => $this->position,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'subtotal_minor' => $this->subtotal_minor,
            'item_discount_minor' => $this->item_discount_minor,
            'allocated_order_discount_minor' => $this->allocated_order_discount_minor,
            'tax_rate_basis_points' => $this->tax_rate_basis_points,
            'tax_minor' => $this->tax_minor,
            'line_total_minor' => $this->line_total_minor,
        ];
    }
}
