<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\OrderNotificationEvent;
use App\Models\User;
use App\Policies\OrderPolicy;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final readonly class OrderNotificationDeliveryService
{
    public function __construct(private OrderPolicy $orderPolicy) {}

    public function deliver(int $eventId): void
    {
        $attempts = Config::integer('orders.write_attempts');
        if ($attempts < 1) {
            throw new LogicException('Order notification delivery requires at least one transaction attempt.');
        }

        DB::transaction(function () use ($eventId): void {
            $event = OrderNotificationEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->processed_at !== null) {
                return;
            }

            $order = Order::query()->useWritePdo()->find($event->order_id);
            $recipient = User::query()->useWritePdo()->find($event->recipient_id);

            $skipReason = match (true) {
                $order === null => 'order_unavailable',
                $recipient === null, $order->assignee_id !== $recipient->id, ! $this->orderPolicy->viewAny($recipient) => 'recipient_ineligible',
                default => null,
            };

            if ($skipReason === null) {
                $notification = OrderNotification::query()->createOrFirst([
                    'event_id' => $event->id,
                    'recipient_id' => $event->recipient_id,
                ]);

                if (! $notification->exists) {
                    throw new RuntimeException('The order notification could not be saved.');
                }
            }

            if (! $event->forceFill(['processed_at' => now(), 'skip_reason' => $skipReason])->save()) {
                throw new RuntimeException('The order notification event could not be marked processed.');
            }
        }, attempts: $attempts);
    }
}
