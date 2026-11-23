<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class OrderFulfillmentReportTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/reports/orders';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_combined_filters_use_inclusive_business_order_dates_and_exclude_deleted_orders(): void
    {
        $this->authenticateAs('manager');

        $customer = Customer::factory()->inactive()->create();
        $assignee = User::factory()->create();
        $orders = Order::factory()->state([
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
            'order_date' => '2026-11-21',
            'created_at' => '2026-10-31 23:59:59',
            'completed_at' => '2026-12-01 00:00:00',
            'status' => OrderStatus::Completed,
            'grand_total_minor' => 1200,
        ]);

        $first = $orders->create(['order_date' => '2026-11-20']);
        $last = $orders->create(['order_date' => '2026-11-22']);
        $orders->create(['order_date' => '2026-11-19', 'completed_at' => '2026-11-20 10:00:00']);
        $orders->create(['order_date' => '2026-11-23', 'completed_at' => '2026-11-22 10:00:00']);
        $orders->create(['status' => OrderStatus::Pending, 'completed_at' => null]);
        $orders->create(['customer_id' => Customer::factory()->create()->id]);
        $orders->create(['assignee_id' => User::factory()->create()->id]);
        $orders->create(['assignee_id' => null]);
        $orders->create()->delete();

        $this->report([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-22',
            'status' => OrderStatus::Completed->value,
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
        ])->assertOk()
            ->assertJsonPath('data.orders.*.id', [$last->id, $first->id])
            ->assertJsonPath('data.totals', [
                'order_count' => 2,
                'by_currency' => [
                    ['currency' => 'USD', 'order_count' => 2, 'grand_total_minor' => '2400'],
                ],
            ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.date_from', '2026-11-20')
            ->assertJsonPath('meta.date_to', '2026-11-22');
    }

    public function test_filtered_totals_remain_the_same_across_pages_and_separate_stored_currencies(): void
    {
        $this->authenticateAs('admin');

        $customer = Customer::factory()->createOne();
        $assignee = User::factory()->createOne();
        $orders = Order::factory()->state([
            'order_date' => '2026-11-22',
            'status' => OrderStatus::Cancelled,
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
        ]);

        $first = $orders->create(['grand_total_minor' => 150]);
        $second = $orders->create(['grand_total_minor' => 225]);
        $third = $orders->create(['currency' => 'EUR', 'grand_total_minor' => 80]);
        $older = $orders->create(['order_date' => '2026-11-21', 'grand_total_minor' => 50]);
        $newerOrders = $orders->state([
            'created_by' => $first->created_by,
            'grand_total_minor' => 25,
        ])->createMany(22);
        $orders->create(['grand_total_minor' => 600])->delete();
        $orders->create(['status' => OrderStatus::Draft, 'grand_total_minor' => 700]);
        $orders->create(['customer_id' => Customer::factory()->createOne()->id]);
        $orders->create(['assignee_id' => null]);

        $query = [
            'date_from' => '2026-11-21',
            'date_to' => '2026-11-22',
            'status' => OrderStatus::Cancelled->value,
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
            'per_page' => 2,
        ];
        $expectedTotals = [
            'order_count' => 26,
            'by_currency' => [
                ['currency' => 'EUR', 'order_count' => 1, 'grand_total_minor' => '80'],
                ['currency' => 'USD', 'order_count' => 25, 'grand_total_minor' => '975'],
            ],
        ];

        $pages = [
            1 => $newerOrders->reverse()->take(2)->pluck('id')->all(),
            12 => [$third->id, $second->id],
            13 => [$first->id, $older->id],
            14 => [],
        ];
        foreach ($pages as $page => $ids) {
            $response = $this->report([...$query, 'page' => $page])->assertOk()
                ->assertJsonPath('data.totals', $expectedTotals)
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('meta.per_page', 2)
                ->assertJsonPath('meta.last_page', 13)
                ->assertJsonPath('meta.total', 26);

            self::assertSame($ids, $response->json('data.orders.*.id'));
        }
    }

    public function test_currency_totals_keep_precision_beyond_the_javascript_integer_limit(): void
    {
        $this->authenticateAs('manager');

        Order::factory()->count(2)->create([
            'order_date' => '2026-11-20',
            'grand_total_minor' => 9_007_199_254_740_991,
        ]);

        $this->report(['date_from' => '2026-11-20', 'date_to' => '2026-11-20'])->assertOk()
            ->assertJsonPath('data.orders.0.grand_total_minor', 9_007_199_254_740_991)
            ->assertJsonPath('data.totals.by_currency.0.grand_total_minor', '18014398509481982');
    }

    public function test_rows_supply_minimal_customer_and_assignee_labels_and_an_order_id_for_drilldown(): void
    {
        $actor = User::factory()->create()->givePermissionTo('reports.view', 'orders.view.any');
        $this->withToken($actor->createToken('fulfillment-report')->plainTextToken);

        $customer = Customer::factory()->create([
            'name' => 'Northwind customer',
            'email' => 'customer-private@example.test',
            'phone' => '5559870123',
            'address' => '45 Market Street',
        ]);
        $assignee = User::factory()->create([
            'name' => 'Assigned employee',
            'email' => 'employee-private@example.test',
        ]);
        $orders = Order::factory()->state([
            'customer_id' => $customer->id,
            'order_date' => '2026-11-20',
            'priority' => OrderPriority::High,
            'status' => OrderStatus::Confirmed,
            'grand_total_minor' => 1225,
        ]);

        $assigned = $orders->create(['assignee_id' => $assignee->id]);
        $unassigned = $orders->create();

        $this->report(['date_from' => '2026-11-20', 'date_to' => '2026-11-20'])->assertOk()
            ->assertJsonPath('data.orders.0.id', $unassigned->id)
            ->assertJsonPath('data.orders.0.assignee', null)
            ->assertJsonPath('data.orders.1', [
                'id' => $assigned->id,
                'order_number' => $assigned->order_number,
                'order_date' => '2026-11-20',
                'customer' => ['id' => $customer->id, 'name' => $customer->name],
                'priority' => 'high',
                'status' => 'confirmed',
                'assignee' => ['id' => $assignee->id, 'name' => $assignee->name],
                'currency' => 'USD',
                'grand_total_minor' => 1225,
            ]);

        $this->getJson('/api/orders/'.$assigned->id)->assertOk()
            ->assertJsonPath('data.order.id', $assigned->id);
    }

    public function test_empty_report_uses_zero_totals_and_the_default_local_date_range(): void
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
            ->assertJsonPath('meta.snapshot_at', static fn (mixed $value): bool => is_string($value)
                && preg_match('/\A2026-11-24T04:59:59(?:\.\d+)?Z\z/', $value) === 1);
    }

    public function test_default_report_range_includes_business_orders_dated_on_its_skipped_first_local_date(): void
    {
        Config::set(['dashboard.timezone' => 'Pacific/Apia', 'reports.default_days' => 30]);
        $this->travelTo(CarbonImmutable::parse('2012-01-27T10:00:00Z'));
        $this->authenticateAs('manager');

        Order::factory()->createOne(['order_date' => '2011-12-29']);
        $included = Order::factory()->createOne(['order_date' => '2011-12-30']);

        $this->report()
            ->assertOk()
            ->assertJsonPath('meta.date_from', '2011-12-30')
            ->assertJsonPath('meta.date_to', '2012-01-28')
            ->assertJsonPath('data.orders.*.id', [$included->id])
            ->assertJsonPath('data.totals.order_count', 1);
    }

    public function test_report_requires_an_active_actor_and_both_report_and_global_order_grants(): void
    {
        $this->report()->assertUnauthorized();

        $staff = User::factory()->create()->assignRole('staff');
        $staff->givePermissionTo('reports.view');
        $this->withToken($staff->createToken('staff-fulfillment-report')->plainTextToken);
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

    public function test_report_validates_queries_and_accepts_the_maximum_inclusive_date_range(): void
    {
        $this->authenticateAs('manager');

        foreach ([
            ['query' => ['date_from' => '2026-11-01'], 'field' => 'date_to'],
            ['query' => ['date_to' => '2026-11-01'], 'field' => 'date_from'],
            ['query' => ['date_from' => '2026-02-30', 'date_to' => '2026-03-01'], 'field' => 'date_from'],
            ['query' => ['date_from' => '2026-11-21', 'date_to' => '2026-11-20'], 'field' => 'date_to'],
            ['query' => ['date_from' => '2025-11-01', 'date_to' => '2026-11-02'], 'field' => 'date_to'],
            ['query' => ['status' => 'not-a-status'], 'field' => 'status'],
            ['query' => ['customer_id' => 0], 'field' => 'customer_id'],
            ['query' => ['assignee_id' => 'not-an-id'], 'field' => 'assignee_id'],
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

    private function authenticateAs(string $role): void
    {
        $actor = User::factory()->create()->assignRole($role);
        $this->withToken($actor->createToken('fulfillment-report')->plainTextToken);
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
