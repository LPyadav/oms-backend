<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $read_at */
#[Fillable(['event_id', 'recipient_id'])]
final class OrderNotification extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<OrderNotificationEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderNotificationEvent::class);
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
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
