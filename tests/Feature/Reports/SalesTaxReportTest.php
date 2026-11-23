<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class SalesTaxReportTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/reports/sales-tax';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'dashboard.timezone' => 'UTC',
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_report_uses_local_completion_dates_across_the_dst_fallback(): void
    {
        Config::set('dashboard.timezone', 'America/New_York');
        $this->authenticateAs('manager');

        $orders = $this->completedOrders()->state([
            'order_date' => '2026-10-15',
            'created_at' => '2026-10-01 00:00:00',
        ]);

        $orders->createOne(['completed_at' => '2026-11-01 03:59:59']);
        $first = $orders->createOne(['completed_at' => '2026-11-01 04:00:00']);
        $firstRepeatedHour = $orders->createOne(['completed_at' => '2026-11-01 05:30:00']);
        $secondRepeatedHour = $orders->createOne(['completed_at' => '2026-11-01 06:30:00']);
        $last = $orders->createOne(['completed_at' => '2026-11-02 04:59:59']);
        $orders->createOne(['completed_at' => '2026-11-02 05:00:00']);

        $orders->createOne(['status' => OrderStatus::Cancelled, 'completed_at' => '2026-11-01 12:00:00']);
        $orders->createOne(['completed_at' => null, 'order_date' => '2026-11-01']);
        $orders->createOne(['completed_at' => '2026-11-01 12:00:00'])->delete();

        $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-01'])->assertOk()
            ->assertJsonPath('data.orders.*.id', [$last->id, $secondRepeatedHour->id, $firstRepeatedHour->id, $first->id])
            ->assertJsonPath('data.orders.0.completed_at', static fn (mixed $value): bool => is_string($value)
                && preg_match('/\A2026-11-02T04:59:59(?:\.\d+)?Z\z/', $value) === 1)
            ->assertJsonPath('data.totals.order_count', 4)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.date_from', '2026-11-01')
            ->assertJsonPath('meta.date_to', '2026-11-01')
            ->assertJsonPath('meta.timezone', 'America/New_York');
    }

    public function test_report_filters_and_totals_completions_by_local_date_across_a_midnight_rollback(): void
    {
        Config::set('dashboard.timezone', 'America/St_Johns');
        $this->authenticateAs('manager');

        $orders = $this->completedOrders()->state([
            'customer_id' => Customer::factory()->createOne()->id,
            'order_date' => '1988-10-29',
        ]);

        $orderIds = [];
        // At 01:31 UTC, the local clock moves from October 30 back to October 29.
        foreach ([
            '1988-10-29 01:29:59' => 10000,
            '1988-10-30 01:29:59' => 100,
            '1988-10-30 01:30:00' => 200,
            '1988-10-30 01:31:00' => 300,
            '1988-10-30 03:29:59' => 400,
            '1988-10-30 03:30:00' => 500,
            '1988-10-31 03:29:59' => 600,
            '1988-10-31 03:30:00' => 20000,
        ] as $completedAt => $totalMinor) {
            $orderIds[$totalMinor] = $orders->createOne([
                'completed_at' => $completedAt,
                'subtotal_minor' => $totalMinor,
                'grand_total_minor' => $totalMinor,
            ])->id;
        }

        $cases = [
            ['1988-10-29', '1988-10-29', [400, 300, 100], '800'],
            ['1988-10-30', '1988-10-30', [600, 500, 200], '1300'],
            ['1988-10-29', '1988-10-30', [600, 500, 400, 300, 200, 100], '2100'],
        ];
        foreach ($cases as [$from, $to, $rowAmounts, $grandTotal]) {
            $this->report(['date_from' => $from, 'date_to' => $to])
                ->assertOk()
                ->assertJsonPath('data.orders.*.id', array_map(static fn (int $amount): int => $orderIds[$amount], $rowAmounts))
                ->assertJsonPath('data.totals.order_count', count($rowAmounts))
                ->assertJsonPath('data.totals.by_currency.0.grand_total_minor', $grandTotal)
                ->assertJsonPath('meta.total', count($rowAmounts));
        }
    }

    public function test_catalog_filters_select_whole_orders_and_keep_saved_tax_and_discounts(): void
    {
        $actor = User::factory()->create()->givePermissionTo('reports.view', 'orders.view.any');
        $this->withToken($actor->createToken('sales-tax-report')->plainTextToken);

        $customer = Customer::factory()->create(['name' => 'Historical customer', 'email' => 'private@example.test']);
        $product = Product::factory()->create(['price' => '60.00', 'tax_rate' => '5.00']);
        $otherProduct = Product::factory()->create(['price' => '40.00', 'tax_rate' => '12.00']);
        $orders = $this->completedOrders()->state(['customer_id' => $customer->id]);
        $matched = $orders->createOne([
            'subtotal_minor' => 10000,
            'order_discount_minor' => 800,
            'discount_total_minor' => 2000,
            'order_tax_rate_basis_points' => 1800,
            'tax_total_minor' => 1440,
            'grand_total_minor' => 9440,
        ]);

        OrderItem::factory()->create([
            'order_id' => $matched->id,
            'product_id' => $product->id,
            'position' => 1,
            'unit_price_minor' => 6000,
            'subtotal_minor' => 6000,
            'item_discount_minor' => 1000,
            'allocated_order_discount_minor' => 455,
            'base_tax_rate_basis_points' => 500,
            'tax_rate_basis_points' => 1800,
            'tax_minor' => 818,
            'line_total_minor' => 5363,
        ]);
        OrderItem::factory()->create([
            'order_id' => $matched->id,
            'product_id' => $otherProduct->id,
            'position' => 2,
            'unit_price_minor' => 4000,
            'subtotal_minor' => 4000,
            'item_discount_minor' => 200,
            'allocated_order_discount_minor' => 345,
            'base_tax_rate_basis_points' => 1200,
            'tax_rate_basis_points' => 1800,
            'tax_minor' => 622,
            'line_total_minor' => 4077,
        ]);

        OrderItem::factory()->create([
            'order_id' => $orders->createOne()->id,
            'product_id' => $otherProduct->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $orders->createOne(['customer_id' => Customer::factory()->create()->id])->id,
            'product_id' => $product->id,
        ]);

        $product->update(['price' => '75.00', 'tax_rate' => '28.00', 'is_active' => false]);
        $otherProduct->update(['price' => '100.00', 'tax_rate' => '0.00']);

        $this->report([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'customer_id' => $customer->id,
            'product_id' => $product->id,
        ])->assertOk()
            ->assertJsonPath('data.orders.*.id', [$matched->id])
            ->assertJsonPath('data.orders.0.order_number', $matched->order_number)
            ->assertJsonPath('data.orders.0.customer', ['id' => $customer->id, 'name' => $customer->name])
            ->assertJsonMissingPath('data.orders.0.customer.email')
            ->assertJsonPath('data.orders.0.currency', 'USD')
            ->assertJsonPath('data.orders.0.subtotal_minor', 10000)
            ->assertJsonPath('data.orders.0.item_discount_minor', 1200)
            ->assertJsonPath('data.orders.0.order_discount_minor', 800)
            ->assertJsonPath('data.orders.0.net_total_minor', 8000)
            ->assertJsonPath('data.orders.0.tax_total_minor', 1440)
            ->assertJsonPath('data.orders.0.grand_total_minor', 9440)
            ->assertJsonPath('data.totals.order_count', 1)
            ->assertJsonCount(1, 'data.totals.by_currency')
            ->assertJsonPath('data.totals.by_currency.0.currency', 'USD')
            ->assertJsonPath('data.totals.by_currency.0.order_count', 1)
            ->assertJsonPath('data.totals.by_currency.0.subtotal_minor', '10000')
            ->assertJsonPath('data.totals.by_currency.0.item_discount_minor', '1200')
            ->assertJsonPath('data.totals.by_currency.0.order_discount_minor', '800')
            ->assertJsonPath('data.totals.by_currency.0.net_total_minor', '8000')
            ->assertJsonPath('data.totals.by_currency.0.tax_total_minor', '1440')
            ->assertJsonPath('data.totals.by_currency.0.grand_total_minor', '9440');
    }

    public function test_product_filtered_annual_totals_respect_the_full_order_scope_across_pages(): void
    {
        $this->authenticateAs('manager');

        $customer = Customer::factory()->createOne();
        $product = Product::factory()->createOne();
        $otherProduct = Product::factory()->createOne();
        $orders = $this->completedOrders()->state(['customer_id' => $customer->id]);
        $matchedItems = OrderItem::factory()->state(['product_id' => $product->id]);
        $otherItems = OrderItem::factory()->state(['product_id' => $otherProduct->id]);

        $largeTotal = 9_007_199_254_740_991;
        $usd = $orders->state(['subtotal_minor' => $largeTotal, 'grand_total_minor' => $largeTotal]);
        $firstUsd = $usd->createOne(['completed_at' => '2025-11-20 00:00:00']);
        $lastUsd = $usd->createOne(['completed_at' => '2026-11-20 23:59:59']);

        foreach ([$firstUsd, $lastUsd] as $order) {
            $matchedItems->createOne(['order_id' => $order->id]);
            $otherItems->createOne([
                'order_id' => $order->id,
                'position' => 2,
                'unit_price_minor' => $largeTotal - 2500,
                'subtotal_minor' => $largeTotal - 2500,
                'line_total_minor' => $largeTotal - 2500,
            ]);
        }

        $eur = $orders->createOne([
            'currency' => 'EUR',
            'subtotal_minor' => 5000,
            'discount_total_minor' => 500,
            'order_discount_minor' => 200,
            'order_tax_rate_basis_points' => 2000,
            'tax_total_minor' => 900,
            'grand_total_minor' => 5400,
        ]);
        $matchedItems->createOne([
            'order_id' => $eur->id,
            'item_discount_minor' => 300,
            'allocated_order_discount_minor' => 100,
            'tax_rate_basis_points' => 2000,
            'tax_minor' => 420,
            'line_total_minor' => 2520,
        ]);
        $otherItems->createOne([
            'order_id' => $eur->id,
            'position' => 2,
            'allocated_order_discount_minor' => 100,
            'tax_rate_basis_points' => 2000,
            'tax_minor' => 480,
            'line_total_minor' => 2880,
        ]);

        $sameTimeOrders = $orders->state(['created_by' => $firstUsd->created_by])->createMany(22);
        foreach ($sameTimeOrders as $order) {
            $matchedItems->createOne(['order_id' => $order->id]);
        }

        foreach ([
            ['customer_id' => Customer::factory()->createOne()->id],
            ['completed_at' => '2025-11-19 23:59:59'],
            ['completed_at' => '2026-11-21 00:00:00'],
            ['status' => OrderStatus::Cancelled],
            ['deleted_at' => '2026-11-21 00:00:00'],
        ] as $attributes) {
            $matchedItems->createOne(['order_id' => $orders->createOne($attributes)->id]);
        }
        $otherItems->createOne(['order_id' => $orders->createOne()->id]);

        $query = [
            'date_from' => '2025-11-20',
            'date_to' => '2026-11-20',
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'per_page' => 2,
        ];
        $currencyTotals = [
            [
                'currency' => 'EUR',
                'order_count' => 1,
                'subtotal_minor' => '5000',
                'item_discount_minor' => '300',
                'order_discount_minor' => '200',
                'net_total_minor' => '4500',
                'tax_total_minor' => '900',
                'grand_total_minor' => '5400',
            ],
            [
                'currency' => 'USD',
                'order_count' => 24,
                'subtotal_minor' => '18014398509536982',
                'item_discount_minor' => '0',
                'order_discount_minor' => '0',
                'net_total_minor' => '18014398509536982',
                'tax_total_minor' => '0',
                'grand_total_minor' => '18014398509536982',
            ],
        ];

        $pages = [
            1 => [$lastUsd->id, $sameTimeOrders->reverse()->firstOrFail()->id],
            12 => [$sameTimeOrders->firstOrFail()->id, $eur->id],
            13 => [$firstUsd->id],
            14 => [],
        ];
        foreach ($pages as $page => $ids) {
            $response = $this->report([...$query, 'page' => $page])->assertOk()
                ->assertJsonPath('data.totals.order_count', 25)
                ->assertJsonPath('data.totals.by_currency', $currencyTotals)
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('meta.last_page', 13)
                ->assertJsonPath('meta.total', 25);

            self::assertSame($ids, $response->json('data.orders.*.id'));
        }

        $this->report([...$query, 'product_id' => Product::factory()->createOne()->id])->assertOk()
            ->assertJsonPath('data.orders', [])
            ->assertJsonPath('data.totals', ['order_count' => 0, 'by_currency' => []])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_pages_sort_timestamp_ties_by_id_and_keep_exact_currency_totals(): void
    {
        $this->authenticateAs('admin');

        $orders = $this->completedOrders();
        $largeTotal = 9_007_199_254_740_991;
        $usd = $orders->state(['subtotal_minor' => $largeTotal, 'grand_total_minor' => $largeTotal]);
        $first = $usd->createOne();
        $second = $usd->createOne();
        $euros = $orders->createOne([
            'currency' => 'EUR',
            'subtotal_minor' => 1250,
            'discount_total_minor' => 250,
            'order_discount_minor' => 50,
            'order_tax_rate_basis_points' => 0,
            'grand_total_minor' => 1000,
        ]);
        OrderItem::factory()->create([
            'order_id' => $euros->id,
            'unit_price_minor' => 1250,
            'subtotal_minor' => 1250,
            'item_discount_minor' => 200,
            'allocated_order_discount_minor' => 50,
            'base_tax_rate_basis_points' => 2500,
            'line_total_minor' => 1000,
        ]);

        foreach ([[$euros->id, $second->id], [$first->id], []] as $index => $ids) {
            $page = $index + 1;
            $this->report([
                'date_from' => '2026-11-20',
                'date_to' => '2026-11-20',
                'per_page' => 2,
                'page' => $page,
            ])->assertOk()
                ->assertJsonPath('data.orders.*.id', $ids)
                ->assertJsonPath('data.totals.order_count', 3)
                ->assertJsonPath('data.totals.by_currency.*.currency', ['EUR', 'USD'])
                ->assertJsonPath('data.totals.by_currency.*.order_count', [1, 2])
                ->assertJsonPath('data.totals.by_currency.*.grand_total_minor', ['1000', '18014398509481982'])
                ->assertJsonPath('data.totals.by_currency.0.tax_total_minor', '0')
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('meta.last_page', 2)
                ->assertJsonPath('meta.total', 3);
        }
    }

    public function test_empty_report_uses_the_default_local_reporting_window(): void
    {
        Config::set('dashboard.timezone', 'America/New_York');
        $this->travelTo(CarbonImmutable::parse('2026-11-24T04:59:59Z'));
        $this->authenticateAs('manager');

        $this->report()->assertOk()
            ->assertJsonPath('data.orders', [])
            ->assertJsonPath('data.totals', ['order_count' => 0, 'by_currency' => []])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.date_from', '2026-10-25')
            ->assertJsonPath('meta.date_to', '2026-11-23')
            ->assertJsonPath('meta.timezone', 'America/New_York')
            ->assertJsonPath('meta.snapshot_at', static fn (mixed $value): bool => is_string($value)
                && preg_match('/\A2026-11-24T04:59:59(?:\.\d+)?Z\z/', $value) === 1);
    }

    public function test_default_report_range_preserves_its_skipped_first_local_date(): void
    {
        Config::set(['dashboard.timezone' => 'Pacific/Apia', 'reports.default_days' => 30]);
        $this->travelTo(CarbonImmutable::parse('2012-01-27T10:00:00Z'));
        $this->authenticateAs('manager');

        $orders = $this->completedOrders()->state(['order_date' => '2011-12-29']);
        $orders->createOne(['completed_at' => '2011-12-30 09:59:59']);
        $included = $orders->createOne(['completed_at' => '2011-12-30 10:00:00']);

        $this->report()
            ->assertOk()
            ->assertJsonPath('meta.date_from', '2011-12-30')
            ->assertJsonPath('meta.date_to', '2012-01-28')
            ->assertJsonPath('meta.timezone', 'Pacific/Apia')
            ->assertJsonPath('data.orders.*.id', [$included->id])
            ->assertJsonPath('data.totals.order_count', 1);
    }

    public function test_report_requires_an_active_actor_and_both_report_and_global_order_grants(): void
    {
        $this->report()->assertUnauthorized();

        $staff = User::factory()->create()->assignRole('staff')->givePermissionTo('reports.view');
        $this->withToken($staff->createToken('staff-sales-tax')->plainTextToken);
        Auth::forgetGuards();
        $this->report()->assertForbidden();

        $orderViewer = User::factory()->create()->givePermissionTo('orders.view.any');
        $this->withToken($orderViewer->createToken('order-viewer')->plainTextToken);
        Auth::forgetGuards();
        $this->report()->assertForbidden();

        $disabled = User::factory()->create(['is_active' => false])->assignRole('manager');
        $this->withToken($disabled->createToken('inactive-reporter')->plainTextToken);
        Auth::forgetGuards();
        $this->report()->assertUnauthorized();
    }

    public function test_revocation_blocks_the_next_report_on_the_same_token(): void
    {
        $this->authenticateAs('manager');
        $this->report()->assertOk();

        Role::findByName('manager', 'web')->revokePermissionTo('reports.view');
        Auth::forgetGuards();
        $this->report()->assertForbidden();
    }

    public function test_report_validates_filters_and_accepts_the_maximum_inclusive_date_range(): void
    {
        $this->authenticateAs('manager');

        foreach ([
            ['query' => ['date_from' => '2026-11-01'], 'field' => 'date_to'],
            ['query' => ['date_to' => '2026-11-01'], 'field' => 'date_from'],
            ['query' => ['date_from' => '2026-02-30', 'date_to' => '2026-03-01'], 'field' => 'date_from'],
            ['query' => ['date_from' => '1000-01-01', 'date_to' => '1000-01-02'], 'field' => 'date_from'],
            ['query' => ['date_from' => '9999-12-30', 'date_to' => '9999-12-31'], 'field' => 'date_to'],
            ['query' => ['date_from' => '2026-11-21', 'date_to' => '2026-11-20'], 'field' => 'date_to'],
            ['query' => ['date_from' => '2025-11-01', 'date_to' => '2026-11-02'], 'field' => 'date_to'],
            ['query' => ['customer_id' => 0], 'field' => 'customer_id'],
            ['query' => ['product_id' => 'not-an-id'], 'field' => 'product_id'],
            ['query' => ['page' => 0], 'field' => 'page'],
            ['query' => ['per_page' => 101], 'field' => 'per_page'],
        ] as $case) {
            $this->report($case['query'])->assertUnprocessable()
                ->assertJsonValidationErrors($case['field'], 'errors.fields');
        }

        $this->report(['date_from' => '2025-11-01', 'date_to' => '2026-11-01'])
            ->assertOk()
            ->assertJsonPath('meta.date_from', '2025-11-01')
            ->assertJsonPath('meta.date_to', '2026-11-01');
    }

    private function completedOrders(): OrderFactory
    {
        return Order::factory()->state([
            'status' => OrderStatus::Completed,
            'order_date' => '2026-11-10',
            'completed_at' => '2026-11-20 12:00:00',
            'subtotal_minor' => 2500,
            'grand_total_minor' => 2500,
        ]);
    }

    private function authenticateAs(string $role): void
    {
        $actor = User::factory()->create()->assignRole($role);
        $this->withToken($actor->createToken('sales-tax-report')->plainTextToken);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return TestResponse<Response>
     */
    private function report(array $query = []): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'?'.http_build_query($query));
    }
}
