<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderService;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OrderCrudConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('Order CRUD races require the disposable MySQL test profile.');
        }
    }

    public function test_same_version_edits_apply_only_the_first_write(): void
    {
        [$manager, $contender, $order, $product] = $this->pendingOrder();

        $this->assertRequestLosesRace(
            static function () use ($manager, $order, $product): void {
                app(OrderService::class)->update($manager, (string) $order->id, [
                    'expected_version' => 1,
                    'priority' => OrderPriority::High->value,
                    'items' => [['product_id' => $product->id, 'quantity' => 4]],
                ]);
            },
            $contender,
            'PATCH',
            '/api/orders/'.$order->id,
            [
                'expected_version' => 1,
                'priority' => OrderPriority::Urgent->value,
                'items' => [['product_id' => $product->id, 'quantity' => 5]],
            ],
        );

        self::assertSame(OrderPriority::High, $order->refresh()->priority);
        self::assertSame(OrderStatus::Pending, $order->status);
        $this->assertOrderItems($order, $product, quantity: 4, version: 2);
        self::assertSame(10, $product->refresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $manager->id,
            'target_type' => 'order',
            'target_id' => $order->id,
            'action' => 'order.updated',
        ]);
    }

    public function test_confirmation_keeps_its_items_when_a_waiting_edit_is_stale(): void
    {
        [$manager, $contender, $order, $product] = $this->pendingOrder();

        try {
            $this->assertRequestLosesRace(
                static function () use ($manager, $order): void {
                    app(OrderLifecycleService::class)->transition(
                        $manager,
                        (string) $order->id,
                        OrderStatus::Confirmed,
                        1,
                        null,
                    );
                },
                $contender,
                'PATCH',
                '/api/orders/'.$order->id,
                [
                    'expected_version' => 1,
                    'items' => [['product_id' => $product->id, 'quantity' => 5]],
                ],
            );

            $this->assertOrderItems($order, $product, quantity: 2, version: 2);
            $this->assertConfirmationInventory($manager, $order, $product, quantity: 2);
            $this->assertDatabaseCount('audit_logs', 1);
        } finally {
            $this->removeStockMovementsForRollback($order);
        }
    }

    public function test_confirmation_waiting_on_an_edit_must_retry_at_the_new_version(): void
    {
        [$manager, $contender, $order, $product] = $this->pendingOrder();

        try {
            $this->assertRequestLosesRace(
                static function () use ($manager, $order, $product): void {
                    app(OrderService::class)->update($manager, (string) $order->id, [
                        'expected_version' => 1,
                        'items' => [['product_id' => $product->id, 'quantity' => 4]],
                    ]);
                },
                $contender,
                'POST',
                '/api/orders/'.$order->id.'/status',
                ['expected_version' => 1, 'status' => OrderStatus::Confirmed->value],
            );

            $this->assertOrderItems($order, $product, quantity: 4, version: 2);
            self::assertSame(OrderStatus::Pending, $order->status);
            self::assertSame(10, $product->refresh()->stock_quantity);
            self::assertSame(0, $product->stock_version);
            $this->assertDatabaseCount('stock_movements', 0);
            $this->assertDatabaseCount('order_status_histories', 0);
            $this->assertDatabaseCount('audit_logs', 1);

            app(OrderLifecycleService::class)->transition(
                $contender,
                (string) $order->id,
                OrderStatus::Confirmed,
                2,
                null,
            );

            $this->assertOrderItems($order, $product, quantity: 4, version: 3);
            $this->assertConfirmationInventory($contender, $order, $product, quantity: 4);
            $this->assertDatabaseCount('audit_logs', 2);
        } finally {
            $this->removeStockMovementsForRollback($order);
        }
    }

    /** @return array{User, User, Order, Product} */
    private function pendingOrder(): array
    {
        $manager = User::factory()->create()->assignRole('manager')->refresh();
        $contender = User::factory()->create()->assignRole('manager')->refresh();
        $product = Product::factory()->create(['price' => '25.00', 'tax_rate' => '0.00', 'stock_quantity' => 10]);
        $order = Order::factory()->create([
            'created_by' => $manager->id,
            'status' => OrderStatus::Pending,
            'subtotal_minor' => 5_000,
            'grand_total_minor' => 5_000,
        ]);
        OrderItem::factory()->for($order)->for($product)->create([
            'quantity' => 2,
            'subtotal_minor' => 5_000,
            'line_total_minor' => 5_000,
        ]);

        return [$manager, $contender, $order, $product];
    }

    private function assertOrderItems(Order $order, Product $product, int $quantity, int $version): void
    {
        $order->refresh();
        self::assertSame($version, $order->version);
        self::assertSame(2_500 * $quantity, $order->grand_total_minor);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'line_total_minor' => 2_500 * $quantity,
        ]);
    }

    private function assertConfirmationInventory(User $actor, Order $order, Product $product, int $quantity): void
    {
        self::assertSame(OrderStatus::Confirmed, $order->status);
        self::assertSame(10 - $quantity, $product->refresh()->stock_quantity);
        self::assertSame(1, $product->stock_version);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity_before' => 10,
            'quantity_after' => 10 - $quantity,
            'quantity_delta' => -$quantity,
        ]);
        $this->assertDatabaseCount('order_status_histories', 1);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'actor_id' => $actor->id,
            'previous_status' => OrderStatus::Pending->value,
            'new_status' => OrderStatus::Confirmed->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $actor->id,
            'target_type' => 'order',
            'target_id' => $order->id,
            'action' => 'order.status_changed',
        ]);
    }

    private function removeStockMovementsForRollback(Order $order): void
    {
        // The stock migration protects linked movement history during rollback.
        DB::table('stock_movements')->where('order_id', $order->id)->delete();
    }

    /**
     * @param  Closure(): void  $winner
     * @param  array<string, mixed>  $body
     */
    private function assertRequestLosesRace(Closure $winner, User $contender, string $method, string $path, array $body): void
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

        $request = $this->competingRequest(
            $contender->createToken('order-race-test')->plainTextToken,
            $method,
            $path,
            $body,
        );

        try {
            $database->beginTransaction();
            $winner();
            $request->start();
            $this->waitForOrderRowLock($request, $database, $context['database'], (int) $session->connection_id);
            $database->commit();

            self::assertSame(0, $request->wait(), $request->getErrorOutput());
            $lines = preg_split('/\R/', trim($request->getOutput()));
            self::assertIsArray($lines);
            self::assertCount(2, $lines);
            self::assertSame([
                'status' => Response::HTTP_CONFLICT,
                'code' => ApiErrorCode::ORDER_VERSION_CONFLICT,
                'version' => 2,
            ], json_decode($lines[1], associative: true, flags: JSON_THROW_ON_ERROR));
        } finally {
            if ($request->isRunning()) {
                $request->stop(0);
            }

            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }
        }
    }

    /** @param array<string, mixed> $body */
    private function competingRequest(string $token, string $method, string $path, array $body): Process
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

                $app->make('config')->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
                $request = Illuminate\Http\Request::create(
                    (string) getenv('OMS_TEST_ORDER_RACE_PATH'),
                    (string) getenv('OMS_TEST_ORDER_RACE_METHOD'),
                    server: [
                        'HTTP_ACCEPT' => 'application/json',
                        'HTTP_AUTHORIZATION' => 'Bearer '.getenv('OMS_TEST_ORDER_RACE_TOKEN'),
                        'CONTENT_TYPE' => 'application/json',
                    ],
                    content: (string) getenv('OMS_TEST_ORDER_RACE_BODY'),
                );
                $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                $body = json_decode($response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

                echo json_encode([
                    'status' => $response->getStatusCode(),
                    'code' => $body['errors']['code'] ?? null,
                    'version' => $body['meta']['version'] ?? null,
                ], JSON_THROW_ON_ERROR), PHP_EOL;
                PHP],
            base_path(),
            [
                'OMS_TEST_ORDER_RACE_TOKEN' => $token,
                'OMS_TEST_ORDER_RACE_METHOD' => $method,
                'OMS_TEST_ORDER_RACE_PATH' => $path,
                'OMS_TEST_ORDER_RACE_BODY' => json_encode($body, JSON_THROW_ON_ERROR),
            ],
            timeout: 20,
        );
    }

    private function waitForOrderRowLock(Process $request, Connection $database, string $schema, int $parentId): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $request->checkTimeout();
            if (preg_match('/\A(\d+)\R/', $request->getOutput(), $matches) === 1) {
                $childId = (int) $matches[1];
                self::assertNotSame($parentId, $childId);
                foreach ($database->select('SHOW FULL PROCESSLIST', [], false) as $thread) {
                    $sql = strtolower((string) $thread->Info);
                    if ((int) $thread->Id === $childId
                        && $thread->db === $schema
                        && str_contains($sql, 'from `orders`')
                        && str_ends_with($sql, 'for update')) {
                        return;
                    }
                }
            }

            if (! $request->isRunning()) {
                self::fail('The competing request exited before locking the order row. '.$request->getErrorOutput());
            }

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        self::fail('The competing order-row query did not appear before the timeout.');
    }
}
