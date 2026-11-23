<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\OrderNotificationType;
use App\Jobs\OrderNotificationJob;
use App\Models\Order;
use App\Models\OrderNotificationEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

final class OrderNotificationOutbox
{
    public function record(Order $order, OrderNotificationType $type): void
    {
        if ($order->assignee_id === null) {
            return;
        }

        $connection = $order->getConnection();
        if (! $order->exists || $connection->transactionLevel() === 0) {
            throw new LogicException('Order notification events require a persisted order in a write transaction.');
        }

        $event = OrderNotificationEvent::query()->createOrFirst(
            [
                'order_id' => $order->id,
                'order_version' => $order->version,
                'event_type' => $type,
            ],
            [
                'recipient_id' => $order->assignee_id,
                'available_at' => now()->addSeconds(Config::integer('order_notifications.recovery_delay_seconds')),
            ],
        );

        if (! $event->exists) {
            throw new RuntimeException('The order notification event could not be recorded.');
        }

        if ($event->wasRecentlyCreated) {
            $eventId = $event->id;

            $connection->afterCommit(function () use ($eventId): void {
                $this->dispatch($eventId);
            });
        }
    }

    public function dispatchPending(): int
    {
        $now = now()->toImmutable();
        $retryAt = $now->addSeconds(Config::integer('order_notifications.recovery_delay_seconds'));
        $events = OrderNotificationEvent::query()
            ->useWritePdo()
            ->whereNull('processed_at')
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(Config::integer('order_notifications.recovery_batch_size'))
            ->get(['id']);

        $queued = 0;

        foreach ($events as $event) {
            // Defer the next retry before dispatch so competing recovery runs do not flood the queue.
            $claimed = OrderNotificationEvent::query()
                ->whereKey($event->id)
                ->whereNull('processed_at')
                ->where('available_at', '<=', $now)
                ->update(['available_at' => $retryAt]);

            if ($claimed === 1 && $this->dispatch($event->id)) {
                $queued++;
            }
        }

        return $queued;
    }

    private function dispatch(int $eventId): bool
    {
        try {
            Bus::dispatch(new OrderNotificationJob($eventId));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Order notification dispatch failed. The event is pending recovery.', [
                'event_id' => $eventId,
                'exception_type' => $exception::class,
            ]);

            return false;
        }
    }
}
