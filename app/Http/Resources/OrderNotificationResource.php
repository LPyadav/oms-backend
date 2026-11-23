<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @mixin OrderNotification */
final class OrderNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->event;
        $order = $event?->order;

        if ($event === null || $order === null) {
            throw new LogicException('A notification response requires its order event.');
        }

        return [
            'id' => $this->id,
            'event_type' => $event->event_type->value,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
