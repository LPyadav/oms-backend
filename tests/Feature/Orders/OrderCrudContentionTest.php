<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class OrderCrudContentionTest extends TestCase
{
    use DatabaseMigrations;

    private const string LOCK_CONNECTION = 'order_crud_lock_holder';

    private const int WRITE_ATTEMPTS = 2;

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('Order CRUD lock regressions require the disposable MySQL test profile.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'orders.currency' => 'USD',
            'orders.write_attempts' => self::WRITE_ATTEMPTS,
            'orders.retry_after_seconds' => 3,
        ]);
    }

    public function test_exhausted_creation_releases_the_idempotency_key_for_retry(): void
    {
        $actor = User::factory()->create()->assignRole('manager');
        $this->withToken($actor->createToken('Order contention test')->plainTextToken);

        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $payload = [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-22',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'note' => 'Confirm the delivery time.',
        ];
        $headers = ['Idempotency-Key' => 'contention-create-retry'];

        $this->assertExhaustedWrite(
            'products',
            $product->id,
            fn (): TestResponse => $this->postJson('/api/orders', $payload, $headers),
        );

        $this->assertDatabaseCount('order_creation_keys', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_notes', 0);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->postJson('/api/orders', $payload, $headers)->assertCreated();

        $order = Order::query()->sole();
        $this->assertDatabaseHas('order_creation_keys', ['actor_id' => $actor->id, 'order_id' => $order->id]);
        $this->assertDatabaseCount('order_creation_keys', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_notes', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_exhausted_update_preserves_order_items_and_version(): void
    {
        $actor = User::factory()->create()->assignRole('manager');
        $this->withToken($actor->createToken('Order contention test')->plainTextToken);

        $order = Order::factory()->create([
            'created_by' => $actor->id,
            'subtotal_minor' => 2_500,
            'grand_total_minor' => 2_500,
        ]);
        $priorItem = OrderItem::factory()->for($order)->create();
        $product = Product::factory()->create(['price' => '30.00', 'tax_rate' => '0.00']);
        $payload = [
            'expected_version' => 1,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];
        $path = '/api/orders/'.$order->id;

        $this->assertExhaustedWrite(
            'products',
            $product->id,
            fn (): TestResponse => $this->patchJson($path, $payload),
        );

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'version' => 1, 'grand_total_minor' => 2_500]);
        $this->assertDatabaseHas('order_items', ['id' => $priorItem->id, 'quantity' => 1]);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->patchJson($path, $payload)
            ->assertOk()
            ->assertJsonPath('data.order.version', 2)
            ->assertJsonPath('data.order.grand_total_minor', 6_000);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2]);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_exhausted_delete_preserves_the_active_order_and_history(): void
    {
        $actor = User::factory()->create()->assignRole('admin');
        $this->withToken($actor->createToken('Order contention test')->plainTextToken);

        $order = Order::factory()->create(['created_by' => $actor->id]);
        $item = OrderItem::factory()->for($order)->create();
        $path = '/api/orders/'.$order->id.'?expected_version=1';

        $this->assertExhaustedWrite(
            'order_items',
            $item->id,
            fn (): TestResponse => $this->deleteJson($path),
        );

        $this->assertNotSoftDeleted('orders', ['id' => $order->id, 'version' => 1]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->deleteJson($path)->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $order->id, 'version' => 2]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /** @param Closure(): TestResponse<Response> $write */
    private function assertExhaustedWrite(string $table, int $id, Closure $write): void
    {
        $writer = DB::connection();
        self::assertSame(0, $writer->transactionLevel());
        Config::set('database.connections.'.self::LOCK_CONNECTION, $writer->getConfig());

        try {
            $holder = DB::connection(self::LOCK_CONNECTION);
            $statement = 'SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name,'
                .' CONNECTION_ID() AS connection_id, @@SESSION.innodb_lock_wait_timeout AS lock_timeout';
            $writerSession = $writer->selectOne($statement, [], false);
            $holderSession = $holder->selectOne($statement, [], false);
            $context = constant('OMS_MYSQL_TEST_CONTEXT');

            self::assertSame($context['database'], $holderSession->database_name);
            self::assertSame($context['account'], $holderSession->account_name);
            self::assertNotSame($writerSession->connection_id, $holderSession->connection_id);

            $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $holder->transaction(function () use ($holder, $table, $id, $writer, $write): void {
                    self::assertNotNull($holder->table($table)->where('id', $id)->lockForUpdate()->first());

                    $attempts = 0;
                    Event::listen(TransactionBeginning::class, static function (TransactionBeginning $event) use ($writer, &$attempts): void {
                        if ($event->connection === $writer) {
                            $attempts++;
                        }
                    });

                    $response = $write()
                        ->assertServiceUnavailable()
                        ->assertHeader('Retry-After', '3')
                        ->assertJsonPath('success', false)
                        ->assertJsonPath('data', null)
                        ->assertJsonPath('errors.code', ApiErrorCode::WRITE_CONTENTION);

                    $requestId = $response->headers->get('X-Request-ID');
                    self::assertTrue(Str::isUuid($requestId));
                    $response->assertJsonPath('meta.request_id', $requestId);

                    self::assertSame(self::WRITE_ATTEMPTS, $attempts);
                    self::assertSame(0, $writer->transactionLevel());
                });
            } finally {
                $writer->statement('SET SESSION innodb_lock_wait_timeout = ?', [(int) $writerSession->lock_timeout]);
            }
        } finally {
            DB::purge(self::LOCK_CONNECTION);
        }
    }
}
