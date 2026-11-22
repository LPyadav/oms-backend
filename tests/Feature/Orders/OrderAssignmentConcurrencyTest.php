<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderActivityService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OrderAssignmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('Order assignment races require the disposable MySQL test profile.');
        }
    }

    public function test_reassignment_prevents_a_waiting_former_assignee_from_adding_a_note(): void
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

        $manager = User::factory()->create()->assignRole('manager')->refresh();
        $former = User::factory()->create()->assignRole('staff');
        $replacement = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create([
            'created_by' => $manager->id,
            'assignee_id' => $former->id,
        ]);
        $note = $this->noteProcess($former, $order);

        try {
            $database->beginTransaction();
            app(OrderActivityService::class)->assign($manager, (string) $order->id, $replacement->id, 1);

            $note->start();
            $this->waitForOrderRowLock($note, $database, $context['database'], (int) $session->connection_id);
            $database->commit();

            self::assertSame(0, $note->wait(), $note->getErrorOutput());
            self::assertStringEndsWith("\nORDER_NOT_FOUND", trim($note->getOutput()));

            $order->refresh();
            self::assertSame($replacement->id, $order->assignee_id);
            self::assertSame(2, $order->version);
            $this->assertDatabaseCount('order_notes', 0);

            $audit = AuditLog::query()->sole();
            self::assertSame('order.assigned', $audit->action);
            self::assertSame('order', $audit->target_type);
            self::assertSame($order->id, $audit->target_id);
            self::assertSame($manager->id, $audit->actor_id);
            self::assertSame($former->id, $audit->before_values['assignee_id'] ?? null);
            self::assertSame(1, $audit->before_values['version'] ?? null);
            self::assertSame($replacement->id, $audit->after_values['assignee_id']);
            self::assertSame(2, $audit->after_values['version']);
        } finally {
            if ($note->isRunning()) {
                $note->stop(0);
            }

            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }
        }
    }

    private function noteProcess(User $former, Order $order): Process
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

                $database->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $actor = App\Models\User::query()->findOrFail((int) getenv('OMS_TEST_ORDER_ACTOR_ID'));
                $orderId = (string) getenv('OMS_TEST_ORDER_ID');

                // The committed view still shows the old assignment before the manager commits.
                $visible = $app->make(App\Services\Orders\OrderService::class)->find($actor, $orderId);
                if ($visible->assignee_id !== $actor->id) {
                    exit(1);
                }

                echo $session->connection_id, PHP_EOL;
                fflush(STDOUT);

                try {
                    $app->make(App\Services\Orders\OrderActivityService::class)->addNote($actor, $orderId, 'Note after reassignment.');
                } catch (Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                    if ($exception->getModel() === App\Models\Order::class) {
                        echo 'ORDER_NOT_FOUND', PHP_EOL;
                        exit(0);
                    }

                    throw $exception;
                }

                exit(1);
                PHP],
            base_path(),
            [
                'OMS_TEST_ORDER_ACTOR_ID' => (string) $former->id,
                'OMS_TEST_ORDER_ID' => (string) $order->id,
            ],
            timeout: 20,
        );
    }

    private function waitForOrderRowLock(Process $note, Connection $database, string $schema, int $parentId): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $note->checkTimeout();
            if (preg_match('/\A(\d+)\R/', $note->getOutput(), $matches) === 1) {
                $childId = (int) $matches[1];
                self::assertNotSame($parentId, $childId);
                foreach ($database->select('SHOW FULL PROCESSLIST', [], false) as $thread) {
                    $sql = strtolower((string) $thread->Info);
                    if ((int) $thread->Id === $childId
                        && $thread->db === $schema
                        && str_contains($sql, 'orders')
                        && str_contains($sql, 'for update')) {
                        return;
                    }
                }
            }

            if (! $note->isRunning()) {
                self::fail('The former assignee exited before requesting the order lock. '.$note->getErrorOutput());
            }

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        self::fail('The former assignee order-lock query did not appear before the timeout.');
    }
}
