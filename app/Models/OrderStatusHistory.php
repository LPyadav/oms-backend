<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property OrderStatus $previous_status
 * @property OrderStatus $new_status
 */
#[Fillable(['actor_id', 'previous_status', 'new_status', 'remarks'])]
final class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => OrderStatus::class,
            'new_status' => OrderStatus::class,
        ];
    }
}
