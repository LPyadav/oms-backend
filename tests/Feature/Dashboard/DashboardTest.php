<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const string DASHBOARD = '/api/dashboard';

    private const string REVENUE_TREND = '/api/dashboard/revenue-trend';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'orders.currency' => 'USD',
            'dashboard.timezone' => 'UTC',
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_overview_reconciles_status_counts_catalog_counts_and_completed_totals(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);

        $customer = Customer::factory()->create();
        Customer::factory()->inactive()->create();
        Product::factory()->create();
        Product::factory()->inactive()->create();

        $orders = Order::factory()->state([
            'customer_id' => $customer->id,
            'created_by' => $manager->id,
            'order_date' => '2026-10-25',
        ]);

        foreach (OrderStatus::cases() as $status) {
            if ($status !== OrderStatus::Completed) {
                $orders->create(['status' => $status, 'grand_total_minor' => 5000]);
            }
        }

        $completed = $orders->state([
            'status' => OrderStatus::Completed,
            'completed_at' => '2026-11-02 14:00:00',
        ]);
        $completed->create(['subtotal_minor' => 80, 'tax_total_minor' => 20, 'grand_total_minor' => 100]);
        $completed->create(['subtotal_minor' => 81, 'tax_total_minor' => 20, 'grand_total_minor' => 101]);
        $completed->create(['currency' => 'EUR', 'subtotal_minor' => 80, 'grand_total_minor' => 80]);
        $orders->create(['status' => OrderStatus::Completed, 'grand_total_minor' => 5000]);

        $deleted = $completed->create(['order_date' => '2026-11-24', 'grand_total_minor' => 5000]);
        $deleted->delete();
        $recent = $orders->create(['order_date' => '2026-11-23']);

        $response = $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.total_orders', 11)
            ->assertJsonPath('data.orders_by_status', [
                'draft' => 2,
                'pending' => 1,
                'confirmed' => 1,
                'processing' => 1,
                'ready' => 1,
                'completed' => 4,
                'cancelled' => 1,
            ])
            ->assertJsonPath('data.total_customers', 2)
            ->assertJsonPath('data.total_products', 2)
            ->assertJsonPathCanonicalizing('data.financial_metrics', [
                [
                    'currency' => 'USD',
                    'completed_orders' => 2,
                    'total_revenue_minor' => '201',
                    'average_order_value_minor' => 101,
                ],
                [
                    'currency' => 'EUR',
                    'completed_orders' => 1,
                    'total_revenue_minor' => '80',
                    'average_order_value_minor' => 80,
                ],
            ]);
        self::assertSame([
            'id' => $recent->id,
            'order_number' => $recent->order_number,
            'order_date' => '2026-11-23',
            'status' => 'draft',
            'priority' => 'normal',
            'currency' => 'USD',
            'grand_total_minor' => 0,
        ], $response->json('data.recent_orders.0'));
    }

    public function test_empty_overview_returns_zero_for_each_metric_and_the_configured_currency(): void
    {
        Config::set('orders.currency', 'EUR');
        $this->authenticate(User::factory()->create()->assignRole('admin'));

        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.total_orders', 0)
            ->assertJsonPath('data.orders_by_status', array_fill_keys(array_column(OrderStatus::cases(), 'value'), 0))
            ->assertJsonPath('data.total_customers', 0)
            ->assertJsonPath('data.total_products', 0)
            ->assertJsonPath('data.financial_metrics', [[
                'currency' => 'EUR',
                'completed_orders' => 0,
                'total_revenue_minor' => '0',
                'average_order_value_minor' => 0,
            ]])
            ->assertJsonPath('data.recent_orders', []);
    }

    public function test_dashboard_and_trend_require_authentication_and_both_dashboard_and_global_order_access(): void
    {
        $this->getJson(self::DASHBOARD)->assertUnauthorized();
        $this->getJson(self::REVENUE_TREND)->assertUnauthorized();

        $staff = User::factory()->create()->assignRole('staff');
        $this->authenticate($staff);
        $this->getJson(self::DASHBOARD)->assertForbidden();
        $this->getJson(self::REVENUE_TREND)->assertForbidden();

        $staff->givePermissionTo('dashboard.view');
        Auth::forgetGuards();
        $this->getJson(self::DASHBOARD)->assertForbidden();
        $this->getJson(self::REVENUE_TREND)->assertForbidden();

        $globalReader = User::factory()->create()->givePermissionTo('orders.view.any');
        $this->authenticate($globalReader);
        $this->getJson(self::DASHBOARD)->assertForbidden();
        $this->getJson(self::REVENUE_TREND)->assertForbidden();
    }

    public function test_catalog_counts_and_dashboard_revocation_follow_the_current_grants_on_the_same_token(): void
    {
        Customer::factory()->create();
        Product::factory()->create();

        $actor = User::factory()->create()->givePermissionTo('dashboard.view', 'orders.view.any', 'customers.view');
        $this->authenticate($actor);
        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.total_customers', 1)
            ->assertJsonPath('data.total_products', null);

        $actor->revokePermissionTo('customers.view');
        $actor->givePermissionTo('products.view');
        Auth::forgetGuards();
        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.total_customers', null)
            ->assertJsonPath('data.total_products', 1);

        $actor->revokePermissionTo('dashboard.view');
        Auth::forgetGuards();
        $this->getJson(self::DASHBOARD)->assertForbidden();
        $this->getJson(self::REVENUE_TREND)->assertForbidden();
    }

    public function test_inactive_user_cannot_access_dashboard_or_trend_with_an_existing_token(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);
        $this->getJson(self::DASHBOARD)->assertOk();

        $manager->forceFill(['is_active' => false])->saveOrFail();
        Auth::forgetGuards();
        $this->getJson(self::DASHBOARD)->assertUnauthorized();
        $this->getJson(self::REVENUE_TREND)->assertUnauthorized();
    }

    /** @param  list<array{string, string, string, int}>  $expected */
    #[DataProvider('revenueBuckets')]
    public function test_revenue_buckets_use_local_completion_dates_across_dst_and_include_empty_periods(
        string $groupBy,
        array $expected,
    ): void {
        Config::set('dashboard.timezone', 'America/New_York');
        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);
        $customer = Customer::factory()->create();

        $orders = Order::factory()->state([
            'customer_id' => $customer->id,
            'created_by' => $manager->id,
            'order_date' => '2026-10-01',
            'status' => OrderStatus::Completed,
        ]);
        foreach ([
            '2026-10-31 03:59:59' => 3000,
            '2026-10-31 04:00:00' => 100,
            '2026-11-01 05:30:00' => 200,
            '2026-11-01 06:30:00' => 300,
            '2026-11-02 05:00:00' => 400,
            '2026-11-04 05:00:00' => 4000,
        ] as $completedAt => $totalMinor) {
            $orders->create(['completed_at' => $completedAt, 'grand_total_minor' => $totalMinor]);
        }
        $orders->create([
            'status' => OrderStatus::Cancelled,
            'completed_at' => '2026-11-01 06:31:00',
            'grand_total_minor' => 5000,
        ]);
        $deleted = $orders->create(['completed_at' => '2026-11-01 06:32:00', 'grand_total_minor' => 6000]);
        $deleted->delete();

        $points = array_map(
            static fn (array $point): array => [
                'period_start' => $point[0],
                'period_end' => $point[1],
                'revenue_minor' => $point[2],
                'completed_orders' => $point[3],
            ],
            $expected,
        );
        $this->trend(['group_by' => $groupBy, 'date_from' => '2026-10-31', 'date_to' => '2026-11-03'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.group_by', $groupBy)
            ->assertJsonPath('data.timezone', 'America/New_York')
            ->assertJsonPath('data.date_from', '2026-10-31')
            ->assertJsonPath('data.date_to', '2026-11-03')
            ->assertJsonPath('data.series', [['currency' => 'USD', 'points' => $points]]);
    }

    /** @return array<string, array{string, list<array{string, string, string, int}>}> */
    public static function revenueBuckets(): array
    {
        return [
            'daily' => ['day', [
                ['2026-10-31', '2026-10-31', '100', 1],
                ['2026-11-01', '2026-11-01', '500', 2],
                ['2026-11-02', '2026-11-02', '400', 1],
                ['2026-11-03', '2026-11-03', '0', 0],
            ]],
            'Monday weeks' => ['week', [
                ['2026-10-31', '2026-11-01', '600', 3],
                ['2026-11-02', '2026-11-03', '400', 1],
            ]],
            'calendar months' => ['month', [
                ['2026-10-31', '2026-10-31', '100', 1],
                ['2026-11-01', '2026-11-03', '900', 3],
            ]],
        ];
    }

    public function test_daily_revenue_assigns_completions_to_their_local_dates_across_a_midnight_rollback(): void
    {
        Config::set('dashboard.timezone', 'America/St_Johns');
        $manager = User::factory()->createOne()->assignRole('manager');
        $this->authenticate($manager);

        $orders = Order::factory()->state([
            'created_by' => $manager->id,
            'customer_id' => Customer::factory()->createOne()->id,
            'order_date' => '1988-10-29',
            'status' => OrderStatus::Completed,
        ]);

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
            $orders->createOne([
                'completed_at' => $completedAt,
                'subtotal_minor' => $totalMinor,
                'grand_total_minor' => $totalMinor,
            ]);
        }

        $this->trend(['group_by' => 'day', 'date_from' => '1988-10-29', 'date_to' => '1988-10-30'])
            ->assertOk()
            ->assertJsonPath('data.series', [['currency' => 'USD', 'points' => [
                [
                    'period_start' => '1988-10-29',
                    'period_end' => '1988-10-29',
                    'revenue_minor' => '800',
                    'completed_orders' => 3,
                ],
                [
                    'period_start' => '1988-10-30',
                    'period_end' => '1988-10-30',
                    'revenue_minor' => '1300',
                    'completed_orders' => 3,
                ],
            ]]]);
    }

    public function test_revenue_trend_preserves_currency_boundaries_when_historical_orders_use_different_currencies(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('manager'));

        $orders = Order::factory()->state([
            'status' => OrderStatus::Completed,
            'completed_at' => '2026-11-12 12:00:00',
        ]);
        $orders->create(['currency' => 'USD', 'grand_total_minor' => 600]);
        $orders->create(['currency' => 'EUR', 'grand_total_minor' => 400]);

        $this->trend(['date_from' => '2026-11-12', 'date_to' => '2026-11-12'])
            ->assertOk()
            ->assertJsonPathCanonicalizing('data.series', [
                ['currency' => 'USD', 'points' => [[
                    'period_start' => '2026-11-12',
                    'period_end' => '2026-11-12',
                    'revenue_minor' => '600',
                    'completed_orders' => 1,
                ]]],
                ['currency' => 'EUR', 'points' => [[
                    'period_start' => '2026-11-12',
                    'period_end' => '2026-11-12',
                    'revenue_minor' => '400',
                    'completed_orders' => 1,
                ]]],
            ]);
    }

    public function test_historical_revenue_uses_only_saved_currency_when_there_is_qualifying_data(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('manager'));
        Order::factory()->create([
            'currency' => 'EUR',
            'status' => OrderStatus::Completed,
            'completed_at' => '2026-11-12 12:00:00',
            'grand_total_minor' => 400,
        ]);

        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.financial_metrics', [[
                'currency' => 'EUR',
                'completed_orders' => 1,
                'total_revenue_minor' => '400',
                'average_order_value_minor' => 400,
            ]]);
        $this->trend(['date_from' => '2026-11-12', 'date_to' => '2026-11-12'])
            ->assertOk()
            ->assertJsonPath('data.series', [['currency' => 'EUR', 'points' => [[
                'period_start' => '2026-11-12',
                'period_end' => '2026-11-12',
                'revenue_minor' => '400',
                'completed_orders' => 1,
            ]]]]);
    }

    public function test_daily_trend_accepts_and_zero_fills_the_full_inclusive_range(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('manager'));

        $response = $this->trend(['group_by' => 'day', 'date_from' => '2025-11-01', 'date_to' => '2026-11-01'])
            ->assertOk()
            ->assertJsonPath('data.series.0.currency', 'USD')
            ->assertJsonCount(366, 'data.series.0.points')
            ->assertJsonPath('data.series.0.points.0.period_start', '2025-11-01')
            ->assertJsonPath('data.series.0.points.365.period_end', '2026-11-01');

        self::assertSame(array_fill(0, 366, '0'), $response->json('data.series.0.points.*.revenue_minor'));
        self::assertSame(array_fill(0, 366, 0), $response->json('data.series.0.points.*.completed_orders'));
    }

    public function test_revenue_trend_defaults_to_thirty_local_calendar_days_and_zero_fills_when_empty(): void
    {
        Config::set(['dashboard.timezone' => 'America/New_York', 'orders.currency' => 'EUR']);
        $this->travelTo(CarbonImmutable::parse('2026-11-24 04:59:59', 'UTC'));
        $this->authenticate(User::factory()->create()->assignRole('manager'));

        $this->trend()
            ->assertOk()
            ->assertJsonPath('data.group_by', 'day')
            ->assertJsonPath('data.date_from', '2026-10-25')
            ->assertJsonPath('data.date_to', '2026-11-23')
            ->assertJsonPath('data.series.0.currency', 'EUR')
            ->assertJsonCount(30, 'data.series.0.points')
            ->assertJsonPath('data.series.0.points.29', [
                'period_start' => '2026-11-23',
                'period_end' => '2026-11-23',
                'revenue_minor' => '0',
                'completed_orders' => 0,
            ]);
    }

    public function test_revenue_trend_rejects_incomplete_invalid_and_excessive_calendar_ranges(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('manager'));

        $this->trend(['group_by' => 'year'])->assertUnprocessable()->assertJsonValidationErrors('group_by', 'errors.fields');
        $this->trend(['date_from' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('date_to', 'errors.fields');
        $this->trend(['date_to' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('date_from', 'errors.fields');
        $this->trend(['date_from' => '2026-02-30', 'date_to' => '2026-03-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from', 'errors.fields');
        $this->trend(['date_from' => '2026-11-02', 'date_to' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to', 'errors.fields');
        $this->trend(['date_from' => '2025-11-01', 'date_to' => '2026-11-02'])->assertUnprocessable();
    }

    public function test_overview_and_trend_preserve_exact_currency_totals_beyond_integer_limits(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);
        $customer = Customer::factory()->create();

        $maximum = OrderPricingService::MAX_MINOR_UNITS;
        $order = [
            'customer_id' => $customer->id,
            'created_by' => $manager->id,
            'order_date' => '2026-11-12',
            'completed_at' => '2026-11-12 12:00:00',
            'status' => OrderStatus::Completed->value,
            'currency' => 'USD',
            'subtotal_minor' => $maximum,
            'order_discount_minor' => 0,
            'discount_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => $maximum,
        ];

        foreach (array_chunk(range(1, 1025), 50) as $batch) {
            DB::table('orders')->insert(array_map(
                static fn (int $number): array => [...$order, 'order_number' => 'PRECISION-USD-'.$number],
                $batch,
            ));
        }

        DB::table('orders')->insert([
            [...$order, 'order_number' => 'PRECISION-EUR-1', 'currency' => 'EUR'],
            [
                ...$order,
                'order_number' => 'PRECISION-EUR-2',
                'currency' => 'EUR',
                'subtotal_minor' => 2,
                'grand_total_minor' => 2,
            ],
        ]);

        $this->getJson(self::DASHBOARD)
            ->assertOk()
            ->assertJsonPath('data.financial_metrics', [
                [
                    'currency' => 'EUR',
                    'completed_orders' => 2,
                    'total_revenue_minor' => '9007199254740993',
                    'average_order_value_minor' => 4_503_599_627_370_497,
                ],
                [
                    'currency' => 'USD',
                    'completed_orders' => 1025,
                    'total_revenue_minor' => '9232379236109515775',
                    'average_order_value_minor' => $maximum,
                ],
            ]);

        $response = $this->trend(['date_from' => '2026-11-12', 'date_to' => '2026-11-12'])
            ->assertOk()
            ->assertJsonCount(2, 'data.series');

        $response
            ->assertJsonPath('data.series.0.currency', 'EUR')
            ->assertJsonPath('data.series.0.points.0.revenue_minor', '9007199254740993')
            ->assertJsonPath('data.series.0.points.0.completed_orders', 2)
            ->assertJsonPath('data.series.1.currency', 'USD')
            ->assertJsonPath('data.series.1.points.0.revenue_minor', '9232379236109515775')
            ->assertJsonPath('data.series.1.points.0.completed_orders', 1025);
    }

    public function test_revenue_trend_preserves_the_label_and_empty_bucket_for_a_skipped_local_date(): void
    {
        Config::set('dashboard.timezone', 'Pacific/Apia');
        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);

        $orders = Order::factory()->state([
            'created_by' => $manager->id,
            'status' => OrderStatus::Completed,
        ]);
        $orders->create(['completed_at' => '2011-12-30 09:59:59', 'grand_total_minor' => 20]);
        $orders->create(['completed_at' => '2011-12-30 10:00:00', 'grand_total_minor' => 30]);

        $skipped = [
            'period_start' => '2011-12-30',
            'period_end' => '2011-12-30',
            'revenue_minor' => '0',
            'completed_orders' => 0,
        ];

        $this->trend(['date_from' => '2011-12-30', 'date_to' => '2011-12-30'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Pacific/Apia')
            ->assertJsonPath('data.date_from', '2011-12-30')
            ->assertJsonPath('data.date_to', '2011-12-30')
            ->assertJsonPath('data.series', [['currency' => 'USD', 'points' => [$skipped]]]);

        $this->trend(['date_from' => '2011-12-29', 'date_to' => '2011-12-31'])
            ->assertOk()
            ->assertJsonPath('data.series', [['currency' => 'USD', 'points' => [
                [
                    'period_start' => '2011-12-29',
                    'period_end' => '2011-12-29',
                    'revenue_minor' => '20',
                    'completed_orders' => 1,
                ],
                $skipped,
                [
                    'period_start' => '2011-12-31',
                    'period_end' => '2011-12-31',
                    'revenue_minor' => '30',
                    'completed_orders' => 1,
                ],
            ]]]);
    }

    private function authenticate(User $actor): void
    {
        Auth::forgetGuards();
        $this->withToken($actor->createToken('dashboard-api')->plainTextToken);
    }

    /**
     * @param  array<string, string>  $query
     * @return TestResponse<Response>
     */
    private function trend(array $query = []): TestResponse
    {
        return $this->getJson(self::REVENUE_TREND.($query === [] ? '' : '?'.http_build_query($query)));
    }
}
