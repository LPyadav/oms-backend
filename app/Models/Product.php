<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $price
 * @property string $tax_rate
 * @property int $stock_quantity
 */
#[Fillable(['sku', 'name', 'price', 'tax_rate', 'stock_quantity', 'is_active'])]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'stock_quantity' => 'integer',
            'stock_version' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
