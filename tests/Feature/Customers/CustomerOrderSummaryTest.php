<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderPricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class CustomerOrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 20,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_inactive_customer_summary_groups_all_visible_values_and_bounds_recent_orders(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);

        $customer = Customer::factory()->inactive()->create();
        $orders = Order::factory()->for($customer)->for($manager, 'creator');
        $orders->create([
            'order_date' => '2026-11-18',
            'status' => OrderStatus::Completed,
            'grand_total_minor' => 9_007_199_254_740_991,
        ]);
        $pending = $orders->create([
            'order_date' => '2026-11-20',
            'status' => OrderStatus::Pending,
            'grand_total_minor' => 2_500,
        ]);
        $completed = $orders->create([
            'order_date' => '2026-11-22',
            'status' => OrderStatus::Completed,
            'currency' => 'EUR',
            'grand_total_minor' => 4_200,
        ]);
        $cancelled = $orders->create([
            'order_date' => '2026-11-22',
            'status' => OrderStatus::Cancelled,
            'grand_total_minor' => 700,
        ]);
        $confirmed = $orders->create([
            'order_date' => '2026-11-21',
            'status' => OrderStatus::Confirmed,
            'currency' => 'EUR',
            'grand_total_minor' => 1_200,
        ]);
        $draft = $orders->create([
            'order_date' => '2026-11-23',
            'grand_total_minor' => 100,
        ]);
        $orders->create(['order_date' => '2026-11-24', 'status' => OrderStatus::Pending])->delete();
        Order::factory()->for($manager, 'creator')->create([
            'order_date' => '2026-11-24',
            'status' => OrderStatus::Pending,
            'grand_total_minor' => 50_000,
        ]);

        $this->getJson($this->summaryPath($customer))
            ->assertOk()
            ->assertJsonPath('data.order_summary.total_orders', 6)
            ->assertJsonPath('data.order_summary.pending_orders', 1)
            ->assertJsonPath('data.order_summary.completed_orders', 2)
            ->assertJsonPath('data.order_summary.cancelled_orders', 1)
            ->assertJsonPath('data.order_summary.total_value_by_currency', [
                ['currency' => 'EUR', 'total_minor' => '5400'],
                ['currency' => 'USD', 'total_minor' => '9007199254744291'],
            ])
            ->assertJsonPath('data.order_summary.recent_orders.*.id', [
                $draft->id,
                $cancelled->id,
                $completed->id,
                $confirmed->id,
                $pending->id,
            ])
            ->assertJsonPath('data.order_summary.recent_orders.1', [
                'id' => $cancelled->id,
                'order_number' => $cancelled->order_number,
                'order_date' => '2026-11-22',
                'status' => 'cancelled',
                'currency' => 'USD',
                'grand_total_minor' => 700,
            ]);
    }

    public function test_customer_lifetime_value_can_exceed_signed_64_bit_without_losing_precision(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);

        $customer = Customer::factory()->create();
        $orderCount = 1_025;
        Order::factory()
            ->for($customer)
            ->for($manager, 'creator')
            ->count($orderCount)
            ->create([
                'status' => OrderStatus::Pending,
                'subtotal_minor' => OrderPricingService::MAX_MINOR_UNITS,
                'grand_total_minor' => OrderPricingService::MAX_MINOR_UNITS,
            ]);

        $this->getJson($this->summaryPath($customer))
            ->assertOk()
            ->assertJsonPath('data.order_summary.total_orders', $orderCount)
            ->assertJsonPath('data.order_summary.pending_orders', $orderCount)
            ->assertJsonPath('data.order_summary.total_value_by_currency', [
                ['currency' => 'USD', 'total_minor' => '9232379236109515775'],
            ]);
    }

    public function test_assigned_only_order_reader_with_catalog_access_sees_only_own_orders(): void
    {
        $staff = User::factory()->create()->assignRole('staff')->givePermissionTo('customers.view');
        $this->authenticate($staff);

        $customer = Customer::factory()->create();
        $other = User::factory()->create();
        $orders = Order::factory()->for($customer)->for($other, 'creator');
        $visible = $orders->for($staff, 'assignee')->create([
            'status' => OrderStatus::Pending,
            'grand_total_minor' => 4_500,
        ]);
        $orders->for($other, 'assignee')->create(['status' => OrderStatus::Completed]);
        $orders->create(['status' => OrderStatus::Cancelled]);

        $this->getJson($this->summaryPath($customer))
            ->assertOk()
            ->assertJsonPath('data.order_summary.total_orders', 1)
            ->assertJsonPath('data.order_summary.pending_orders', 1)
            ->assertJsonPath('data.order_summary.completed_orders', 0)
            ->assertJsonPath('data.order_summary.cancelled_orders', 0)
            ->assertJsonPath('data.order_summary.total_value_by_currency', [
                ['currency' => 'USD', 'total_minor' => '4500'],
            ])
            ->assertJsonPath('data.order_summary.recent_orders.*.id', [$visible->id]);
    }

    public function test_a_customer_with_no_visible_orders_has_zeroes_and_empty_lists(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $customer = Customer::factory()->create();

        $this->getJson($this->summaryPath($customer))
            ->assertOk()
            ->assertJsonPath('data.order_summary', [
                'total_orders' => 0,
                'pending_orders' => 0,
                'completed_orders' => 0,
                'cancelled_orders' => 0,
                'total_value_by_currency' => [],
                'recent_orders' => [],
            ]);

        $this->getJson('/api/customers/999999/order-summary')->assertNotFound();
    }

    public function test_catalog_access_without_order_view_never_exposes_summary(): void
    {
        $this->authenticate(User::factory()->create()->givePermissionTo('customers.view'));
        $customer = Customer::factory()->create();

        $this->getJson('/api/customers/'.$customer->id)->assertOk();
        $this->getJson($this->summaryPath($customer))->assertForbidden();
        $this->getJson('/api/customers/999999/order-summary')->assertForbidden();
    }

    public function test_catalog_summary_requires_authentication_and_catalog_permission(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $customer = Customer::factory()->create();
        Order::factory()->for($customer)->for($staff, 'assignee')->for($staff, 'creator')->create();

        $this->getJson($this->summaryPath($customer))->assertUnauthorized();

        $this->authenticate($staff);

        $this->getJson($this->summaryPath($customer))->assertForbidden();
        $this->getJson('/api/customers/999999/order-summary')->assertForbidden();
    }

    private function authenticate(User $actor): void
    {
        $this->withToken($actor->createToken('Customer order summary test')->plainTextToken);
    }

    private function summaryPath(Customer $customer): string
    {
        return '/api/customers/'.$customer->id.'/order-summary';
    }
}
