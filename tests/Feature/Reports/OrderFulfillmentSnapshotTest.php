<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderFulfillmentSnapshotTest extends TestCase
{
    use DatabaseMigrations;

    private const string WRITER_CONNECTION = 'fulfillment_snapshot_writer';

    private const string ENDPOINT = '/api/reports/orders?date_from=2026-11-20&date_to=2026-11-20';

    protected function beforeRefreshingDatabase(): void
    {
        if (! defined('OMS_MYSQL_TEST_CONTEXT')) {
            self::markTestSkipped('The report snapshot regression requires the disposable MySQL test profile.');
        }
    }

    public function test_report_rows_and_totals_share_a_snapshot_across_a_concurrent_commit(): void
    {
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));

        $actor = User::factory()->create()->assignRole('manager');
        $this->withToken($actor->createToken('report-snapshot')->plainTextToken);
        $before = Order::factory()->create([
            'order_date' => '2026-11-20',
            'subtotal_minor' => 100,
            'grand_total_minor' => 100,
        ]);
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
            $reader->listen(static function (QueryExecuted $query) use (&$committed, $reader, $writer, $before, $newOrderNumber): void {
                if ($committed || $query->connection !== $reader || preg_match('/\Aselect\b.*\bfrom\s+\x60orders\x60(?:\s|\z)/is', $query->sql) !== 1) {
                    return;
                }

                $writer->transaction(static function () use ($writer, $before, $newOrderNumber): void {
                    $writer->table('orders')->where('id', $before->id)->update([
                        'subtotal_minor' => 400,
                        'grand_total_minor' => 400,
                    ]);
                    $writer->table('orders')->insert([
                        'order_number' => $newOrderNumber,
                        'customer_id' => $before->customer_id,
                        'created_by' => $before->created_by,
                        'order_date' => '2026-11-20',
                        'currency' => 'USD',
                        'subtotal_minor' => 900,
                        'discount_total_minor' => 0,
                        'order_discount_minor' => 0,
                        'tax_total_minor' => 0,
                        'grand_total_minor' => 900,
                    ]);
                });
                $committed = true;
            });

            $this->getJson(self::ENDPOINT)->assertOk()
                ->assertJsonPath('data.orders.*.id', [$before->id])
                ->assertJsonPath('data.orders.0.grand_total_minor', 100)
                ->assertJsonPath('data.totals.order_count', 1)
                ->assertJsonPath('data.totals.by_currency.0.grand_total_minor', '100')
                ->assertJsonPath('meta.total', 1);

            self::assertTrue($committed, 'The writer must commit after the report reads the orders table.');

            $this->getJson(self::ENDPOINT)->assertOk()
                ->assertJsonPath('data.orders.0.order_number', $newOrderNumber)
                ->assertJsonPath('data.orders.*.grand_total_minor', [900, 400])
                ->assertJsonPath('data.totals.order_count', 2)
                ->assertJsonPath('data.totals.by_currency.0.grand_total_minor', '1300')
                ->assertJsonPath('meta.total', 2);
        } finally {
            $reader->setEventDispatcher($dispatcher);
            DB::purge(self::WRITER_CONNECTION);
        }
    }
}
