<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OrderLifecycleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('Order lock regressions require the disposable MySQL test profile.');
        }
    }

    public function test_competing_confirmations_cannot_claim_the_same_stock(): void
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

        $actor = User::factory()->create()->assignRole('manager')->refresh();
        $product = Product::factory()->create(['price' => '25.00', 'tax_rate' => '0.00', 'stock_quantity' => 10]);
        $winner = $this->pendingOrder($actor, $product);
        $loser = $this->pendingOrder($actor, $product);
        $claim = $this->competingClaim($actor, $loser, $product);

        try {
            $database->beginTransaction();
            $confirmed = app(OrderLifecycleService::class)->transition(
                $actor,
                (string) $winner->id,
                OrderStatus::Confirmed,
                1,
                null,
            );
            self::assertSame(OrderStatus::Confirmed, $confirmed->status);

            $claim->start();
            $this->waitForProductRowLock($claim, $database, $context['database'], (int) $session->connection_id);

            $database->commit();

            self::assertSame(0, $claim->wait());
            self::assertStringContainsString('ORDER_STOCK_INSUFFICIENT', $claim->getOutput());
            $this->assertStockClaimOutcome($winner, $loser, $product);
        } finally {
            if ($claim->isRunning()) {
                $claim->stop(0);
            }

            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }

            // The stock migration refuses to discard linked movement history on rollback.
            $database->table('stock_movements')
                ->where('product_id', $product->id)
                ->whereIn('order_id', [$winner->id, $loser->id])
                ->delete();
        }
    }

    private function assertStockClaimOutcome(Order $winner, Order $loser, Product $product): void
    {
        self::assertSame(OrderStatus::Confirmed, $winner->refresh()->status);
        self::assertSame(2, $winner->version);
        self::assertSame(OrderStatus::Pending, $loser->refresh()->status);
        self::assertSame(1, $loser->version);
        self::assertSame(3, $product->refresh()->stock_quantity);
        self::assertSame(1, $product->stock_version);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $winner->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity_before' => 10,
            'quantity_after' => 3,
            'quantity_delta' => -7,
        ]);
        $this->assertDatabaseCount('order_status_histories', 1);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $winner->id,
            'previous_status' => OrderStatus::Pending->value,
            'new_status' => OrderStatus::Confirmed->value,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'order',
            'target_id' => $winner->id,
            'action' => 'order.status_changed',
        ]);
    }

    private function pendingOrder(User $actor, Product $product): Order
    {
        $order = Order::factory()->create([
            'created_by' => $actor->id,
            'status' => OrderStatus::Pending,
            'subtotal_minor' => 17_500,
            'grand_total_minor' => 17_500,
        ]);
        OrderItem::factory()->for($order)->for($product)->create([
            'quantity' => 7,
            'subtotal_minor' => 17_500,
            'line_total_minor' => 17_500,
        ]);

        return $order;
    }

    private function competingClaim(User $actor, Order $order, Product $product): Process
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

                $actor = App\Models\User::query()->findOrFail((int) getenv('OMS_TEST_ORDER_ACTOR_ID'));
                try {
                    $app->make(App\Services\Orders\OrderLifecycleService::class)->transition(
                        $actor,
                        (string) getenv('OMS_TEST_ORDER_ID'),
                        App\Enums\OrderStatus::Confirmed,
                        1,
                        null,
                    );
                } catch (Illuminate\Validation\ValidationException $exception) {
                    $expected = Illuminate\Support\Facades\Lang::string('api.orders.insufficient_stock', [
                        'sku' => (string) getenv('OMS_TEST_ORDER_PRODUCT_SKU'),
                    ]);

                    if ($exception->errors() === ['items' => [$expected]]) {
                        echo 'ORDER_STOCK_INSUFFICIENT', PHP_EOL;
                        exit(0);
                    }
                }

                exit(1);
                PHP],
            base_path(),
            [
                'OMS_TEST_ORDER_ACTOR_ID' => (string) $actor->id,
                'OMS_TEST_ORDER_ID' => (string) $order->id,
                'OMS_TEST_ORDER_PRODUCT_SKU' => $product->sku,
            ],
            timeout: 20,
        );
    }

    private function waitForProductRowLock(
        Process $claim,
        Connection $database,
        string $schema,
        int $parentId,
    ): void {
        $deadline = hrtime(true) + 10_000_000_000;

        do {
            $claim->checkTimeout();

            if (preg_match('/\A(\d+)\R/', $claim->getOutput(), $matches) === 1) {
                $childId = (int) $matches[1];
                self::assertNotSame($parentId, $childId);

                foreach ($database->select('SHOW FULL PROCESSLIST', [], false) as $thread) {
                    $sql = strtolower((string) $thread->Info);
                    if ((int) $thread->Id === $childId
                        && $thread->db === $schema
                        && str_contains($sql, 'products')
                        && str_contains($sql, 'for update')) {
                        return;
                    }
                }
            }

            if (! $claim->isRunning()) {
                self::fail('The competing claim exited before requesting the product row lock.');
            }

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        self::fail('The competing product row-lock query did not appear before the timeout.');
    }
}
