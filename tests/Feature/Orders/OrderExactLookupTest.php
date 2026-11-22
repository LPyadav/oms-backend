<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class OrderExactLookupTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/orders';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_exact_order_number_and_legacy_substring_search_keep_their_own_results(): void
    {
        $this->authenticateAs('manager');

        $term = 'ORD-EXACT%_!42';
        $date = ['order_date' => '2026-11-22'];
        $exact = Order::factory()->create([...$date, 'order_number' => $term]);
        $numberSubstring = Order::factory()->create([...$date, 'order_number' => $term.'-SUFFIX']);
        $customer = Customer::factory()->create(['email' => 'purchasing-'.strtolower($term).'@example.test']);
        $customerMatch = Order::factory()->create([...$date, 'customer_id' => $customer->id]);
        $itemMatch = Order::factory()->create($date);
        OrderItem::factory()->create(['order_id' => $itemMatch->id, 'product_name' => 'Archive '.$term]);
        $assignee = User::factory()->create(['name' => 'Assignee '.$term]);
        $assigneeMatch = Order::factory()->create([...$date, 'assignee_id' => $assignee->id]);
        Order::factory()->create([...$date, 'order_number' => 'ORD-EXACTXY!42']);

        foreach ([[$exact->id, $numberSubstring->id], [$customerMatch->id, $itemMatch->id], [$assigneeMatch->id]] as $index => $ids) {
            $page = $index + 1;
            $this->listing([
                'search' => $term,
                'direction' => 'asc',
                'per_page' => 2,
                'page' => $page,
            ])->assertOk()
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('meta.total', 5)
                ->assertJsonPath('data.orders.*.id', $ids);
        }

        $this->listing(['order_number' => '  '.$term.'  ', 'search' => $term])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.orders.*.id', [$exact->id]);
    }

    public function test_exact_sku_uses_historical_snapshots_and_preserves_pagination_and_combined_filters(): void
    {
        $this->authenticateAs('manager');

        $sku = 'HIST-SKU%_!42';
        $date = ['order_date' => '2026-11-22'];
        $customer = Customer::factory()->create(['name' => 'Dispatch Beacon']);
        $first = Order::factory()->create($date);
        $second = Order::factory()->create([...$date, 'customer_id' => $customer->id, 'status' => OrderStatus::Pending]);
        $partial = Order::factory()->create([...$date, 'customer_id' => $customer->id, 'status' => OrderStatus::Pending]);
        $this->addSnapshot($first, $sku);
        $this->addSnapshot($first, $sku, 2);
        $this->addSnapshot($second, $sku);
        $this->addSnapshot($partial, $sku.'-SUFFIX');
        $this->addSnapshot(Order::factory()->create($date), 'HIST-SKUXY!42');
        $catalog = Product::factory()->create(['sku' => $sku]);
        OrderItem::factory()->create([
            'product_id' => $catalog->id,
            'product_name' => 'Historical model',
            'product_sku' => 'OTHER-HISTORICAL-SKU',
        ]);

        foreach ([$first->id, $second->id] as $index => $id) {
            $page = $index + 1;
            $this->listing(['sku' => '  '.$sku.'  ', 'direction' => 'asc', 'per_page' => 1, 'page' => $page])
                ->assertOk()
                ->assertJsonPath('meta.total', 2)
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('data.orders.*.id', [$id]);
        }

        $this->listing(['search' => $sku, 'direction' => 'asc'])
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.orders.*.id', [$first->id, $second->id, $partial->id]);

        $this->listing(['sku' => $sku, 'search' => 'Dispatch Beacon', 'status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.orders.*.id', [$second->id]);

        $this->listing(['sku' => $sku, 'order_number' => $first->order_number])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.orders.*.id', [$first->id]);

        $this->listing(['sku' => $sku, 'order_number' => $partial->order_number])
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data.orders', []);
    }

    public function test_exact_filters_respect_staff_scope_and_soft_deletes(): void
    {
        $staff = $this->authenticateAs('staff');
        $otherStaff = User::factory()->create()->assignRole('staff');
        $own = Order::factory()->create(['assignee_id' => $staff->id]);
        $other = Order::factory()->create(['assignee_id' => $otherStaff->id]);
        $deleted = Order::factory()->create(['assignee_id' => $staff->id]);
        $sku = 'HIST-SCOPED-SKU';

        foreach ([$own, $other, $deleted] as $order) {
            $this->addSnapshot($order, $sku);
        }

        $deleted->delete();

        foreach ([['order_number' => $own->order_number], ['sku' => $sku]] as $query) {
            $this->listing($query)
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.orders.*.id', [$own->id]);
            $this->listing([...$query, 'assignee_id' => $otherStaff->id])
                ->assertOk()
                ->assertJsonPath('meta.total', 0);
        }

        foreach ([$other, $deleted] as $hidden) {
            $this->listing(['order_number' => $hidden->order_number])
                ->assertOk()
                ->assertJsonPath('meta.total', 0);
        }
    }

    public function test_exact_lookup_validates_only_query_values(): void
    {
        $this->authenticateAs('manager');
        $match = Order::factory()->create();
        $other = Order::factory()->create();
        $sku = 'HIST-GOOD-SKU';
        $this->addSnapshot($match, $sku);

        foreach (['order_number' => 40, 'sku' => 64] as $field => $limit) {
            foreach (['', '   ', ['identifier'], str_repeat('X', $limit + 1)] as $invalid) {
                $this->listing([$field => $invalid])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors($field, 'errors.fields');
            }
        }

        $query = http_build_query([
            'order_number' => $match->order_number,
            'sku' => $sku,
        ], '', '&', PHP_QUERY_RFC3986);
        $this->json('GET', self::ENDPOINT.'?'.$query, [
            'order_number' => $other->order_number,
            'sku' => 'BODY-SKU',
        ])->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.orders.*.id', [$match->id]);
    }

    private function addSnapshot(Order $order, string $sku, int $position = 1): void
    {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_sku' => $sku,
            'position' => $position,
        ]);
    }

    private function authenticateAs(string $role): User
    {
        $actor = User::factory()->create()->assignRole($role);
        $this->withToken($actor->createToken('order-exact-lookup')->plainTextToken);

        return $actor;
    }

    /**
     * @param  array<string, int|string|list<string>>  $query
     * @return TestResponse<Response>
     */
    private function listing(array $query): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
