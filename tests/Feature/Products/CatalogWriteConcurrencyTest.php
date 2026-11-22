<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Constants\ApiErrorCode;
use App\Models\Product;
use App\Models\User;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogWriteConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const string LOCK_CONNECTION = 'catalog_lock_holder';

    private const array ADJUSTMENT = [
        'stock_quantity' => 5,
        'expected_stock_version' => 0,
        'reason' => 'Physical count',
    ];

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('Catalog lock regressions require the disposable MySQL test profile.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'catalog.write_attempts' => 2,
            'catalog.retry_after_seconds' => 1,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_shared_authorization_locks_allow_independent_stock_adjustments_for_the_same_admin(): void
    {
        $actor = User::factory()->create()->assignRole('admin');
        $lockedProduct = Product::factory()->create();
        $target = Product::factory()->create();
        $this->withToken($actor->createToken('Catalog concurrency test')->plainTextToken);

        $this->withLockHolder(function (Connection $holder) use ($actor, $lockedProduct, $target): void {
            self::assertNotNull($holder->table('roles')
                ->where('name', 'admin')
                ->where('guard_name', 'web')
                ->sharedLock()
                ->first());
            self::assertNotNull($holder->table('users')->where('id', $actor->id)->sharedLock()->first());
            self::assertNotNull($holder->table('products')->where('id', $lockedProduct->id)->lockForUpdate()->first());

            $this->patchJson('/api/products/'.$target->id.'/stock', self::ADJUSTMENT)
                ->assertOk()
                ->assertJsonPath('data.product.stock_quantity', 5)
                ->assertJsonPath('data.product.stock_version', 1);
        });

        $this->assertDatabaseHas('products', [
            'id' => $lockedProduct->id,
            'stock_quantity' => 0,
            'stock_version' => 0,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $target->id,
            'actor_id' => $actor->id,
            'quantity_delta' => 5,
            'stock_version' => 1,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_exhausted_product_lock_retries_return_a_retryable_error_without_mutating_stock(): void
    {
        $actor = User::factory()->create()->assignRole('admin');
        $product = Product::factory()->create();
        $url = '/api/products/'.$product->id.'/stock';
        $this->withToken($actor->createToken('Catalog concurrency test')->plainTextToken);

        $this->withLockHolder(function (Connection $holder) use ($product, $url): void {
            self::assertNotNull($holder->table('products')->where('id', $product->id)->lockForUpdate()->first());

            $writer = DB::connection();
            $attempts = 0;
            Event::listen(TransactionBeginning::class, static function (TransactionBeginning $event) use ($writer, &$attempts): void {
                if ($event->connection === $writer) {
                    $attempts++;
                }
            });

            $response = $this->patchJson($url, self::ADJUSTMENT)
                ->assertServiceUnavailable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('data', null)
                ->assertJsonPath('errors.code', ApiErrorCode::WRITE_CONTENTION)
                ->assertHeader('Retry-After', '1');

            $requestId = $response->headers->get('X-Request-ID');
            self::assertTrue(Str::isUuid($requestId));
            $response->assertJsonPath('meta.request_id', $requestId);
            self::assertSame(2, $attempts);

            $this->assertDatabaseHas('products', [
                'id' => $product->id,
                'stock_quantity' => 0,
                'stock_version' => 0,
            ]);
            $this->assertDatabaseCount('stock_movements', 0);
        });

        $this->patchJson($url, self::ADJUSTMENT)
            ->assertOk()
            ->assertJsonPath('data.product.stock_quantity', 5)
            ->assertJsonPath('data.product.stock_version', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity_delta' => 5,
            'stock_version' => 1,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    /**
     * @param  Closure(Connection): void  $callback
     */
    private function withLockHolder(Closure $callback): void
    {
        $writer = DB::connection();
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
                $holder->transaction(static function () use ($callback, $holder): void {
                    $callback($holder);
                });
            } finally {
                $writer->statement('SET SESSION innodb_lock_wait_timeout = ?', [(int) $writerSession->lock_timeout]);
            }
        } finally {
            DB::purge(self::LOCK_CONNECTION);
        }
    }
}
