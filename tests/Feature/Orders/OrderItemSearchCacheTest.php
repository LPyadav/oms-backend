<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class OrderItemSearchCacheTest extends TestCase
{
    use DatabaseMigrations;

    private const string ENDPOINT = '/api/orders';

    private const string SNAPSHOT = 'Snapshot %_! Valve';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
            'cache.stores.order_item_search' => ['driver' => 'array'],
            'orders.item_search_cache.enabled' => true,
            'orders.item_search_cache.ttl_seconds' => 30,
            'orders.item_search_cache.max_ids' => 2,
        ]);
    }

    public function test_a_warm_snapshot_search_uses_current_scope_and_filters(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $visible = Order::factory()->create(['assignee_id' => $staff->id]);
        $hidden = Order::factory()->create(['status' => OrderStatus::Pending]);
        $this->addSnapshot($visible);
        $this->addSnapshot($hidden);
        $this->addSnapshot(Order::factory()->create(), 'Snapshot AB! Valve');

        $this->authenticate(User::factory()->create()->assignRole('manager'));
        DB::enableQueryLog();
        $this->listing(self::SNAPSHOT)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        self::assertTrue($this->queriedItemSnapshots());

        $visible->forceFill(['status' => OrderStatus::Pending])->saveOrFail();
        Role::findByName('staff', 'web')->revokePermissionTo('customers.view.assigned-orders');
        $this->authenticate($staff);
        DB::flushQueryLog();

        $this->listing(self::SNAPSHOT, ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.orders.*.id', [$visible->id])
            ->assertJsonPath('data.orders.0.customer.email', null)
            ->assertJsonPath('meta.total', 1);
        self::assertFalse($this->queriedItemSnapshots());
    }

    public function test_empty_snapshot_matches_expire_and_transactional_reads_use_sql(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('manager'));
        DB::enableQueryLog();

        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 0);
        self::assertTrue($this->queriedItemSnapshots());

        DB::flushQueryLog();
        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 0);
        self::assertFalse($this->queriedItemSnapshots());

        DB::transaction(function (): void {
            DB::flushQueryLog();
            $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 0);
            self::assertTrue($this->queriedItemSnapshots());
        });

        $this->travel(31)->seconds();
        DB::flushQueryLog();
        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 0);
        self::assertTrue($this->queriedItemSnapshots());
    }

    public function test_an_oversized_match_uses_sql_without_truncating_results(): void
    {
        Config::set('orders.item_search_cache.max_ids', 1);
        $this->authenticate(User::factory()->create()->assignRole('manager'));
        $this->addSnapshot(Order::factory()->create());
        $this->addSnapshot(Order::factory()->create());

        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 2);

        DB::enableQueryLog();
        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 2);
        self::assertTrue($this->queriedItemSnapshots());
    }

    public function test_search_falls_back_to_sql_when_the_cache_store_is_unavailable(): void
    {
        Config::set('cache.stores.order_item_search', ['driver' => 'unavailable-test-cache']);
        $this->authenticate(User::factory()->create()->assignRole('manager'));
        $order = Order::factory()->create();
        $this->addSnapshot($order);

        $this->listing(self::SNAPSHOT)
            ->assertOk()
            ->assertJsonPath('data.orders.*.id', [$order->id])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_committed_creates_and_line_replacements_invalidate_snapshot_matches(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('manager'));
        $customer = Customer::factory()->create();
        $original = Product::factory()->create(['name' => self::SNAPSHOT]);
        $replacement = Product::factory()->create(['name' => 'Replacement coupling']);

        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 0);

        $this->postJson(self::ENDPOINT, [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-22',
            'items' => [['product_id' => $original->id, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'cache-create'])->assertCreated();

        $order = Order::query()->sole();
        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('data.orders.*.id', [$order->id]);
        $this->listing($replacement->name)->assertOk()->assertJsonPath('meta.total', 0);

        $this->patchJson(self::ENDPOINT.'/'.$order->id, [
            'expected_version' => $order->version,
            'items' => [['product_id' => $replacement->id, 'quantity' => 1]],
        ])->assertOk();

        $this->listing(self::SNAPSHOT)->assertOk()->assertJsonPath('meta.total', 0);
        $this->listing($replacement->name)->assertOk()->assertJsonPath('data.orders.*.id', [$order->id]);
    }

    private function authenticate(User $user): void
    {
        Auth::forgetGuards();
        $this->withToken($user->createToken('snapshot-search-cache-test')->plainTextToken);
    }

    private function addSnapshot(Order $order, string $name = self::SNAPSHOT): void
    {
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => $name]);
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return TestResponse<Response>
     */
    private function listing(string $search, array $filters = []): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'search' => $search,
            ...$filters,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    private function queriedItemSnapshots(): bool
    {
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'order_items') && str_contains($query['query'], 'product_name')) {
                return true;
            }
        }

        return false;
    }
}
