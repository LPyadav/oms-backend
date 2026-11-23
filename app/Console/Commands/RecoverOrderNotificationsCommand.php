<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\OrderNotificationOutbox;
use Illuminate\Console\Command;

final class RecoverOrderNotificationsCommand extends Command
{
    protected $signature = 'oms:notifications:recover';

    protected $description = 'Queue pending order notification events that are due for delivery';

    public function handle(OrderNotificationOutbox $outbox): int
    {
        $queued = $outbox->dispatchPending();

        $this->info("Queued {$queued} pending order notification events.");

        return self::SUCCESS;
    }
}
