<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderNotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $order_version
 * @property OrderNotificationType $event_type
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $processed_at
 */
#[Fillable(['order_id', 'recipient_id', 'order_version', 'event_type', 'available_at'])]
final class OrderNotificationEvent extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_version' => 'integer',
            'event_type' => OrderNotificationType::class,
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
