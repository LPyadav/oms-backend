<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    /**
     * @return array{id: int, sku: string, name: string, price: string, tax_rate: string, stock_quantity: int, stock_version: int, is_active: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'price' => $this->price,
            'tax_rate' => $this->tax_rate,
            'stock_quantity' => $this->stock_quantity,
            'stock_version' => $this->stock_version,
            'is_active' => $this->is_active,
        ];
    }
}
