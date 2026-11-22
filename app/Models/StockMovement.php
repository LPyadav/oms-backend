<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'product_id',
    'actor_id',
    'type',
    'quantity_before',
    'quantity_after',
    'quantity_delta',
    'stock_version',
    'reason',
])]
final class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const MAX_REASON_LENGTH = 255;

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_OPENING_BALANCE = 'opening_balance';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'actor_id' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'quantity_delta' => 'integer',
            'stock_version' => 'integer',
        ];
    }
}
