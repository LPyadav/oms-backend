<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** @phpstan-import-type CreateOrderData from OrderService */
final class OrderCreationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('Order creation races require the disposable MySQL test profile.');
        }
    }

    public function test_concurrent_requests_with_the_same_actor_and_key_return_one_complete_order(): void
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

        Config::set('orders.currency', 'USD');
        $actor = User::factory()->create()->assignRole('manager')->refresh();
        $employee = User::factory()->create()->assignRole('staff');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['price' => '25.00', 'tax_rate' => '0.00']);
        $data = [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-22',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'assignee_id' => $employee->id,
            'note' => 'Make one delivery only.',
        ];
        $key = 'concurrent-order-creation';
        $retry = $this->retryProcess($actor, $data, $key);

        try {
            $database->beginTransaction();
            $winner = app(OrderService::class)->create($actor, $data, $key);
            $retry->start();

            $this->waitForKeyWrite($retry, $database, $context['database'], (int) $session->connection_id);
            $database->commit();

            self::assertSame(0, $retry->wait(), $retry->getErrorOutput());
            $lines = preg_split('/\R/', trim($retry->getOutput()));
            self::assertIsArray($lines);
            self::assertCount(2, $lines);
            self::assertSame([
                'id' => $winner->id,
                'product_ids' => [$product->id],
                'notes' => [$data['note']],
                'assignment_history_count' => 1,
            ], json_decode($lines[1], associative: true, flags: JSON_THROW_ON_ERROR));

            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 1);
            $this->assertDatabaseCount('order_notes', 1);
            $this->assertDatabaseCount('order_creation_keys', 1);
            $this->assertDatabaseCount('audit_logs', 2);
            $this->assertDatabaseHas('order_creation_keys', ['actor_id' => $actor->id, 'order_id' => $winner->id]);
            $this->assertDatabaseHas('audit_logs', ['target_id' => $winner->id, 'action' => 'order.created']);
            $this->assertDatabaseHas('audit_logs', ['target_id' => $winner->id, 'action' => 'order.assigned']);
        } finally {
            if ($retry->isRunning()) {
                $retry->stop(0);
            }

            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }
        }
    }

    /** @param CreateOrderData $data */
    private function retryProcess(User $actor, array $data, string $key): Process
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
                echo $session->connection_id, PHP_EOL;
                fflush(STDOUT);

                $actor = App\Models\User::query()->findOrFail((int) getenv('OMS_TEST_ORDER_ACTOR_ID'));
                $data = json_decode((string) getenv('OMS_TEST_ORDER_CREATE_BODY'), associative: true, flags: JSON_THROW_ON_ERROR);
                $key = (string) getenv('OMS_TEST_ORDER_CREATION_KEY');
                $order = $app->make(App\Services\Orders\OrderService::class)->create($actor, $data, $key);

                echo json_encode([
                    'id' => $order->id,
                    'product_ids' => $order->items->pluck('product_id')->all(),
                    'notes' => $order->notes->pluck('body')->all(),
                    'assignment_history_count' => $order->assignmentHistory->count(),
                ], JSON_THROW_ON_ERROR), PHP_EOL;
                PHP],
            base_path(),
            [
                'OMS_TEST_ORDER_ACTOR_ID' => (string) $actor->id,
                'OMS_TEST_ORDER_CREATE_BODY' => json_encode($data, JSON_THROW_ON_ERROR),
                'OMS_TEST_ORDER_CREATION_KEY' => $key,
            ],
            timeout: 20,
        );
    }

    private function waitForKeyWrite(Process $retry, Connection $database, string $schema, int $parentId): void
    {
        $deadline = hrtime(true) + 10_000_000_000;

        do {
            $retry->checkTimeout();

            if (preg_match('/\A(\d+)\R/', $retry->getOutput(), $matches) === 1) {
                $childId = (int) $matches[1];
                self::assertNotSame($parentId, $childId);
                foreach ($database->select('SHOW FULL PROCESSLIST', [], false) as $thread) {
                    $sql = strtolower((string) $thread->Info);
                    if ((int) $thread->Id === $childId
                        && $thread->db === $schema
                        && str_contains($sql, 'order_creation_keys')
                        && (str_contains($sql, 'insert') || str_contains($sql, 'for update'))) {
                        return;
                    }
                }
            }

            if (! $retry->isRunning()) {
                self::fail('The retry exited before requesting the key row lock. '.$retry->getErrorOutput());
            }

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        self::fail('The competing key-row query did not appear before the timeout.');
    }
}
