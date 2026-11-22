<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\OrderNotificationType;
use App\Jobs\OrderNotificationJob;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\OrderNotificationEvent;
use App\Models\User;
use App\Services\Notifications\OrderNotificationDeliveryService;
use App\Services\Orders\OrderActivityService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OrderNotificationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('Order notification concurrency requires the disposable MySQL test profile.');
        }
    }

    public function test_assignment_enqueues_the_notification_only_after_the_outer_commit(): void
    {
        $database = DB::connection();
        self::assertSame(0, $database->transactionLevel());
        Config::set([
            'queue.default' => 'database',
            'queue.connections.database.connection' => $database->getName(),
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.after_commit' => false,
        ]);

        $manager = User::factory()->create()->assignRole('manager')->refresh();
        $recipient = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create(['created_by' => $manager->id]);

        $database->beginTransaction();

        try {
            $assigned = app(OrderActivityService::class)->assign($manager, (string) $order->id, $recipient->id, $order->version);

            self::assertSame(1, $database->transactionLevel());
            $this->assertDatabaseHas('order_notification_events', [
                'order_id' => $order->id,
                'order_version' => $assigned->version,
                'recipient_id' => $recipient->id,
                'event_type' => OrderNotificationType::Assigned->value,
            ]);
            $this->assertDatabaseCount('jobs', 0);

            $database->commit();
        } finally {
            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }
        }

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => Config::string('order_notifications.queue')]);
        $this->assertDatabaseCount('order_notifications', 0);
    }

    public function test_competing_workers_deliver_the_same_event_once(): void
    {
        $database = DB::connection();
        $context = constant('OMS_MYSQL_TEST_CONTEXT');
        $session = $database->selectOne(
            'SELECT DATABASE() AS schema_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id',
            [],
            false,
        );
        self::assertSame($context['database'], $session->schema_name);
        self::assertSame($context['account'], $session->account_name);
        self::assertSame(0, $database->transactionLevel());

        $recipient = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create(['assignee_id' => $recipient->id]);
        $event = OrderNotificationEvent::query()->create([
            'order_id' => $order->id,
            'order_version' => $order->version,
            'recipient_id' => $recipient->id,
            'event_type' => OrderNotificationType::Assigned,
        ]);
        $competitor = $this->deliveryProcess($event);

        try {
            $database->beginTransaction();
            (new OrderNotificationJob($event->id))->handle(app(OrderNotificationDeliveryService::class));
            $published = OrderNotification::query()->sole();

            $competitor->start();
            $this->waitForEventRowLock($competitor, $database, $context['database'], (int) $session->connection_id);
            $database->commit();

            self::assertSame(0, $competitor->wait(), $competitor->getErrorOutput());
            self::assertStringEndsWith("\nDELIVERY_COMPLETE", trim($competitor->getOutput()));
            self::assertSame($published->id, OrderNotification::query()->sole()->id);
            $this->assertDatabaseHas('order_notifications', ['event_id' => $event->id, 'recipient_id' => $recipient->id]);
            self::assertNotNull($event->refresh()->processed_at);
            self::assertNull($event->skip_reason);
        } finally {
            if ($competitor->isRunning()) {
                $competitor->stop(0);
            }

            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }
        }
    }

    private function deliveryProcess(OrderNotificationEvent $event): Process
    {
        return new Process(
            [PHP_BINARY, '-r', <<<'PHP'
                require 'tests/bootstrap-mysql.php';

                $context = constant('OMS_MYSQL_TEST_CONTEXT');
                $app = require 'bootstrap/app.php';
                if ($app->configurationIsCached()) {
                    exit(2);
                }

                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $database = $app->make('db')->connection();
                $session = $database->selectOne(
                    'SELECT DATABASE() AS schema_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id',
                    [],
                    false,
                );
                if ($session->schema_name !== $context['database'] || $session->account_name !== $context['account']) {
                    exit(2);
                }

                echo $session->connection_id, PHP_EOL;
                fflush(STDOUT);

                $eventId = (int) getenv('OMS_TEST_NOTIFICATION_EVENT_ID');
                (new App\Jobs\OrderNotificationJob($eventId))
                    ->handle($app->make(App\Services\Notifications\OrderNotificationDeliveryService::class));
                echo 'DELIVERY_COMPLETE', PHP_EOL;
                PHP],
            base_path(),
            ['OMS_TEST_NOTIFICATION_EVENT_ID' => (string) $event->id],
            timeout: 20,
        );
    }

    private function waitForEventRowLock(Process $competitor, Connection $database, string $schema, int $parentId): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $competitor->checkTimeout();
            if (preg_match('/\A(\d+)\R/', $competitor->getOutput(), $matches) === 1) {
                $childId = (int) $matches[1];
                self::assertNotSame($parentId, $childId);
                foreach ($database->select('SHOW FULL PROCESSLIST', [], false) as $thread) {
                    $sql = strtolower((string) $thread->Info);
                    if ((int) $thread->Id === $childId
                        && $thread->db === $schema
                        && str_contains($sql, 'order_notification_events')
                        && str_contains($sql, 'for update')) {
                        return;
                    }
                }
            }

            if (! $competitor->isRunning()) {
                self::fail('The worker exited before requesting the event row lock. '.$competitor->getErrorOutput());
            }

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        self::fail('The competing worker event-lock query did not appear before the timeout.');
    }
}
