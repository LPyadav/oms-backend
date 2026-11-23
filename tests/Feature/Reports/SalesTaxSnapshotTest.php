<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SalesTaxSnapshotTest extends TestCase
{
    use DatabaseMigrations;

    private const string WRITER_CONNECTION = 'sales_tax_snapshot_writer';

    private const string ENDPOINT = '/api/reports/sales-tax?date_from=2026-11-20&date_to=2026-11-20';

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('The report snapshot regression requires the disposable MySQL test profile.');
        }
    }

    /** @return array<string, array{bool}> */
    public static function reportScopes(): array
    {
        return [
            'unfiltered' => [false],
            'product filtered later page' => [true],
        ];
    }

    #[DataProvider('reportScopes')]
    public function test_report_rows_and_totals_share_a_snapshot_across_a_concurrent_commit(bool $withProductFilter): void
    {
        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'dashboard.timezone' => 'UTC',
        ]);

        $actor = User::factory()->create()->assignRole('manager');
        $this->withToken($actor->createToken('report-snapshot')->plainTextToken);
        $before = Order::factory()->create([
            'order_date' => '2026-11-10',
            'status' => OrderStatus::Completed,
            'completed_at' => '2026-11-20 12:00:00',
            'subtotal_minor' => 100,
            'tax_total_minor' => 10,
            'grand_total_minor' => 110,
        ]);
        $endpoint = self::ENDPOINT;
        $productSnapshot = null;
        $newer = null;
        if ($withProductFilter) {
            $item = OrderItem::factory()->createOne([
                'order_id' => $before->id,
                'unit_price_minor' => 100,
                'subtotal_minor' => 100,
                'tax_rate_basis_points' => 1000,
                'tax_minor' => 10,
                'line_total_minor' => 110,
            ]);
            $productSnapshot = $item->only(['product_id', 'product_name', 'product_sku']);
            $newer = Order::factory()->createOne([
                'customer_id' => $before->customer_id,
                'created_by' => $before->created_by,
                'order_date' => '2026-11-10',
                'status' => OrderStatus::Completed,
                'completed_at' => '2026-11-20 12:30:00',
                'subtotal_minor' => 200,
                'grand_total_minor' => 200,
            ]);
            OrderItem::factory()->createOne([
                ...$productSnapshot,
                'order_id' => $newer->id,
                'unit_price_minor' => 200,
                'subtotal_minor' => 200,
                'line_total_minor' => 200,
            ]);
            $endpoint .= '&product_id='.$item->product_id.'&page=2&per_page=1';
        }

        $newOrderNumber = 'ORD-'.Str::ulid();

        $reader = DB::connection();
        Config::set(
            'database.connections.'.self::WRITER_CONNECTION,
            Config::array('database.connections.'.$reader->getName()),
        );
        $writer = DB::connection(self::WRITER_CONNECTION);

        $dispatcher = $reader->getEventDispatcher();
        self::assertNotNull($dispatcher);

        try {
            $identityQuery = 'SELECT DATABASE() AS schema_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id';
            $readIdentity = $reader->selectOne($identityQuery, [], false);
            $writeIdentity = $writer->selectOne($identityQuery, [], false);
            self::assertSame($readIdentity->schema_name, $writeIdentity->schema_name);
            self::assertSame($readIdentity->account_name, $writeIdentity->account_name);
            self::assertNotSame($readIdentity->connection_id, $writeIdentity->connection_id);

            $committed = false;
            $reader->setEventDispatcher(clone $dispatcher);
            $reader->listen(static function (QueryExecuted $query) use (&$committed, $reader, $writer, $before, $newOrderNumber, $productSnapshot): void {
                if ($committed || $query->connection !== $reader || preg_match('/\Aselect\b.*\b(?:from|join)\s+\x60?(?:orders|order_items)\x60?(?:\s|\z)/is', $query->sql) !== 1) {
                    return;
                }

                $writer->transaction(static function () use ($writer, $before, $newOrderNumber, $productSnapshot): void {
                    $writer->table('orders')->where('id', $before->id)->update([
                        'tax_total_minor' => 40,
                        'grand_total_minor' => 140,
                    ]);
                    $newOrderId = $writer->table('orders')->insertGetId([
                        'order_number' => $newOrderNumber,
                        'customer_id' => $before->customer_id,
                        'created_by' => $before->created_by,
                        'order_date' => '2026-11-10',
                        'status' => OrderStatus::Completed->value,
                        'completed_at' => '2026-11-20 13:00:00',
                        'currency' => 'USD',
                        'subtotal_minor' => 900,
                        'discount_total_minor' => 0,
                        'order_discount_minor' => 0,
                        'tax_total_minor' => 0,
                        'grand_total_minor' => 900,
                    ]);

                    if ($productSnapshot !== null) {
                        $writer->table('order_items')->where('order_id', $before->id)->update([
                            'tax_rate_basis_points' => 4000,
                            'tax_minor' => 40,
                            'line_total_minor' => 140,
                        ]);
                        $writer->table('order_items')->insert([
                            ...$productSnapshot,
                            'order_id' => $newOrderId,
                            'position' => 1,
                            'quantity' => 1,
                            'unit_price_minor' => 900,
                            'subtotal_minor' => 900,
                            'item_discount_minor' => 0,
                            'allocated_order_discount_minor' => 0,
                            'tax_rate_basis_points' => 0,
                            'tax_minor' => 0,
                            'line_total_minor' => 900,
                        ]);
                    }
                });
                $committed = true;
            });

            $this->getJson($endpoint)->assertOk()
                ->assertJsonPath('data.orders.*.id', [$before->id])
                ->assertJsonPath('data.orders.0.tax_total_minor', 10)
                ->assertJsonPath('data.orders.0.grand_total_minor', 110)
                ->assertJsonPath('data.totals.order_count', $withProductFilter ? 2 : 1)
                ->assertJsonPath('data.totals.by_currency.0.tax_total_minor', '10')
                ->assertJsonPath('data.totals.by_currency.0.grand_total_minor', $withProductFilter ? '310' : '110')
                ->assertJsonPath('meta.current_page', $withProductFilter ? 2 : 1)
                ->assertJsonPath('meta.total', $withProductFilter ? 2 : 1);

            self::assertTrue($committed, 'The writer must commit after the report reads the orders table.');

            $nextSnapshot = $this->getJson($endpoint)->assertOk()
                ->assertJsonPath('data.totals.order_count', $withProductFilter ? 3 : 2)
                ->assertJsonPath('data.totals.by_currency.0.tax_total_minor', '40')
                ->assertJsonPath('data.totals.by_currency.0.grand_total_minor', $withProductFilter ? '1240' : '1040')
                ->assertJsonPath('meta.total', $withProductFilter ? 3 : 2);

            if ($newer !== null) {
                $nextSnapshot->assertJsonPath('data.orders.*.id', [$newer->id])
                    ->assertJsonPath('data.orders.0.grand_total_minor', 200);
            } else {
                $nextSnapshot->assertJsonPath('data.orders.0.order_number', $newOrderNumber)
                    ->assertJsonPath('data.orders.*.tax_total_minor', [0, 40])
                    ->assertJsonPath('data.orders.*.grand_total_minor', [900, 140]);
            }
        } finally {
            $reader->setEventDispatcher($dispatcher);
            DB::purge(self::WRITER_CONNECTION);
        }
    }
}
