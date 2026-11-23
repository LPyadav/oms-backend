<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\OrderNotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;

final class OrderNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue(Config::string('order_notifications.queue'));
    }

    public function tries(): int
    {
        return Config::integer('order_notifications.job_attempts');
    }

    public function backoff(): int
    {
        return Config::integer('order_notifications.job_backoff_seconds');
    }

    public function handle(OrderNotificationDeliveryService $delivery): void
    {
        $delivery->deliver($this->eventId);
    }
}
