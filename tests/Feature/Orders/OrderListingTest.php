<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderPriority;
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

final class OrderListingTest extends TestCase
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

    public function test_search_matches_each_assessment_field_and_returns_each_order_once(): void
    {
        $this->authenticateAsManager();

        $customer = Customer::factory()->inactive()->create([
            'name' => 'Northwind Wholesale',
            'email' => 'purchasing-dispatch@example.test',
        ]);
        $assignee = User::factory()->create(['name' => 'Jordan Howard']);
        $order = Order::factory()->create([
            'order_number' => 'ORD-SOURCE907',
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
        ]);
        $product = Product::factory()->create(['name' => 'Live replacement model']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Archived Pump Seal',
            'product_sku' => 'HIST-441',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'position' => 2,
            'product_name' => 'Archived Pump Cartridge',
            'product_sku' => 'HIST-442',
        ]);
        Order::factory()->create();

        foreach (['SOURCE907', 'Northwind', 'purchasing-dispatch', 'Archived Pump', 'HIST-441', 'Howard'] as $term) {
            $response = $this->listing(['search' => $term])
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonMissingPath('data.orders.0.items');
            self::assertSame([$order->id], $response->json('data.orders.*.id'), $term);
        }

        $this->listing(['search' => 'Live replacement model'])
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_search_treats_wildcard_characters_as_literal_text(): void
    {
        $this->authenticateAsManager();

        $match = Order::factory()->create(['order_number' => 'ORD-RATE%_!END']);
        Order::factory()->create(['order_number' => 'ORD-RATEXY!END']);

        $response = $this->listing(['search' => 'RATE%_!'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        self::assertSame([$match->id], $response->json('data.orders.*.id'));
    }

    public function test_all_filters_apply_together_with_inclusive_date_boundaries(): void
    {
        $this->authenticateAsManager();

        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $assignee = User::factory()->create();
        $otherAssignee = User::factory()->create();
        $matching = [
            'status' => OrderStatus::Pending,
            'priority' => OrderPriority::High,
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
        ];
        $first = Order::factory()->create([...$matching, 'order_date' => '2026-11-20']);
        $last = Order::factory()->create([...$matching, 'order_date' => '2026-11-22']);
        Order::factory()->create([...$matching, 'order_date' => '2026-11-19']);
        Order::factory()->create([...$matching, 'order_date' => '2026-11-23']);

        foreach ([
            'status' => OrderStatus::Draft,
            'priority' => OrderPriority::Normal,
            'customer_id' => $otherCustomer->id,
            'assignee_id' => $otherAssignee->id,
        ] as $field => $otherValue) {
            Order::factory()->create([...$matching, 'order_date' => '2026-11-21', $field => $otherValue]);
        }

        $response = $this->listing([
            'status' => 'pending',
            'priority' => 'high',
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-22',
        ])->assertOk()->assertJsonPath('meta.total', 2);

        self::assertSame([$last->id, $first->id], $response->json('data.orders.*.id'));
    }

    public function test_every_sort_supports_both_directions(): void
    {
        $this->authenticateAsManager();

        $first = Order::factory()->create([
            'order_date' => '2026-11-20',
            'grand_total_minor' => 100,
            'status' => OrderStatus::Cancelled,
            'priority' => OrderPriority::Low,
            'customer_id' => Customer::factory()->create(['name' => 'Beta Trading'])->id,
        ]);
        $second = Order::factory()->create([
            'order_date' => '2026-11-22',
            'grand_total_minor' => 2,
            'status' => OrderStatus::Pending,
            'priority' => OrderPriority::Urgent,
            'customer_id' => Customer::factory()->create(['name' => 'Alpha Supplies'])->id,
        ]);
        $third = Order::factory()->create([
            'order_date' => '2026-11-21',
            'grand_total_minor' => 10,
            'status' => OrderStatus::Completed,
            'priority' => OrderPriority::High,
            'customer_id' => Customer::factory()->create(['name' => 'Zebra Retail'])->id,
        ]);

        $ascending = [
            'order_date' => [$first->id, $third->id, $second->id],
            'total' => [$second->id, $third->id, $first->id],
            'status' => [$second->id, $third->id, $first->id],
            'priority' => [$first->id, $third->id, $second->id],
            'customer' => [$second->id, $first->id, $third->id],
        ];

        foreach ($ascending as $field => $ids) {
            $asc = $this->listing(['sort' => $field, 'direction' => 'asc'])->assertOk();
            $desc = $this->listing(['sort' => $field, 'direction' => 'desc'])->assertOk();

            self::assertSame($ids, $asc->json('data.orders.*.id'), $field);
            self::assertSame(array_reverse($ids), $desc->json('data.orders.*.id'), $field);
        }
    }

    public function test_customer_sort_breaks_name_ties_by_order_id_across_pages_and_uses_live_names(): void
    {
        $this->authenticateAsManager();

        $firstTieCustomer = Customer::factory()->create(['name' => 'Delta Trading']);
        $secondTieCustomer = Customer::factory()->inactive()->create(['name' => 'Delta Trading']);
        $alpha = Customer::factory()->create(['name' => 'Alpha Supplies']);
        $zulu = Customer::factory()->create(['name' => 'Zulu Retail']);

        $firstTie = Order::factory()->create(['customer_id' => $secondTieCustomer->id]);
        $alphaOrder = Order::factory()->create(['customer_id' => $alpha->id]);
        $secondTie = Order::factory()->create(['customer_id' => $firstTieCustomer->id]);
        $zuluOrder = Order::factory()->create(['customer_id' => $zulu->id]);
        $thirdTie = Order::factory()->create(['customer_id' => $secondTieCustomer->id]);

        $ascending = [$alphaOrder->id, $firstTie->id, $secondTie->id, $thirdTie->id, $zuluOrder->id];

        foreach (['asc' => $ascending, 'desc' => array_reverse($ascending)] as $direction => $ids) {
            foreach (array_chunk($ids, 2) as $index => $pageIds) {
                $this->listing([
                    'sort' => 'customer',
                    'direction' => $direction,
                    'page' => $index + 1,
                    'per_page' => 2,
                ])->assertOk()
                    ->assertJsonPath('data.orders.*.id', $pageIds)
                    ->assertJsonPath('meta.current_page', $index + 1)
                    ->assertJsonPath('meta.last_page', 3)
                    ->assertJsonPath('meta.total', 5);
            }
        }

        $secondTieCustomer->update(['name' => 'Aaron Supplies']);

        $this->listing(['sort' => 'customer', 'direction' => 'asc'])
            ->assertOk()
            ->assertJsonPath('data.orders.*.id', [
                $firstTie->id, $thirdTie->id, $alphaOrder->id, $secondTie->id, $zuluOrder->id,
            ])
            ->assertJsonPath('data.orders.0.customer.id', $secondTieCustomer->id)
            ->assertJsonPath('data.orders.0.customer.name', 'Aaron Supplies')
            ->assertJsonPath('meta.total', 5);
    }

    public function test_customer_sort_preserves_staff_scope_filters_and_totals(): void
    {
        $staff = User::factory()->create(['name' => 'Order Handler'])->assignRole('staff');
        $otherStaff = User::factory()->create(['name' => 'Order Handler'])->assignRole('staff');
        $this->withToken($staff->createToken('order-listing')->plainTextToken);

        $alpha = Customer::factory()->inactive()->create(['name' => 'Alpha Dispatch']);
        $delta = Customer::factory()->create(['name' => 'Delta Dispatch']);
        $outsideSearch = Customer::factory()->create(['name' => 'Zulu Retail', 'email' => 'unrelated@example.test']);
        $matching = [
            'assignee_id' => $staff->id,
            'status' => OrderStatus::Pending,
            'order_date' => '2026-11-22',
            'customer_id' => $delta->id,
        ];
        $deltaOrder = Order::factory()->create($matching);
        $alphaOrder = Order::factory()->create([...$matching, 'customer_id' => $alpha->id]);
        $deleted = Order::factory()->create($matching);

        foreach ([
            $deltaOrder,
            $alphaOrder,
            $deleted,
            Order::factory()->create([...$matching, 'status' => OrderStatus::Draft]),
            Order::factory()->create([...$matching, 'order_date' => '2026-11-21']),
            Order::factory()->create([...$matching, 'customer_id' => $outsideSearch->id]),
            Order::factory()->create([...$matching, 'assignee_id' => $otherStaff->id]),
            Order::factory()->create([...$matching, 'assignee_id' => null]),
        ] as $order) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_name' => 'Archived Valve',
                'product_sku' => 'CUSTOMER-SORT-42',
            ]);
        }

        Order::factory()->create($matching);
        $deleted->delete();

        $filters = [
            'sort' => 'customer',
            'direction' => 'asc',
            'search' => 'Dispatch',
            'sku' => 'CUSTOMER-SORT-42',
            'status' => 'pending',
            'date_from' => '2026-11-22',
            'date_to' => '2026-11-22',
        ];
        $this->listing($filters)
            ->assertOk()
            ->assertJsonPath('data.orders.*.id', [$alphaOrder->id, $deltaOrder->id])
            ->assertJsonPath('meta.total', 2);

        $this->listing([...$filters, 'customer_id' => $delta->id])
            ->assertOk()
            ->assertJsonPath('data.orders.*.id', [$deltaOrder->id])
            ->assertJsonPath('meta.total', 1);

        $this->listing([...$filters, 'assignee_id' => $otherStaff->id])
            ->assertOk()
            ->assertJsonPath('data.orders', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_pagination_breaks_sort_ties_by_id_in_the_same_direction(): void
    {
        $this->authenticateAsManager();

        $earlier = Order::factory()->create(['order_date' => '2026-11-22']);
        $later = Order::factory()->create(['order_date' => '2026-11-22']);
        Order::factory()->create(['order_date' => '2026-11-22'])->delete();

        foreach ([
            ['query' => [], 'ids' => [$later->id, $earlier->id]],
            ['query' => ['sort' => 'order_date', 'direction' => 'asc'], 'ids' => [$earlier->id, $later->id]],
        ] as $case) {
            foreach ($case['ids'] as $index => $id) {
                $this->listing([...$case['query'], 'per_page' => 1, 'page' => $index + 1])
                    ->assertOk()
                    ->assertJsonCount(1, 'data.orders')
                    ->assertJsonPath('data.orders.0.id', $id)
                    ->assertJsonPath('meta.current_page', $index + 1)
                    ->assertJsonPath('meta.total', 2);
            }
        }
    }

    public function test_search_and_assignee_filter_preserve_staff_scope(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $otherStaff = User::factory()->create()->assignRole('staff');
        $customer = Customer::factory()->create(['name' => 'Scope Beacon']);
        $this->withToken($staff->createToken('order-listing')->plainTextToken);
        $visible = Order::factory()->create(['assignee_id' => $staff->id, 'customer_id' => $customer->id]);
        Order::factory()->create(['assignee_id' => $otherStaff->id, 'customer_id' => $customer->id]);
        Order::factory()->create(['customer_id' => $customer->id]);
        Order::factory()->create(['assignee_id' => $staff->id]);

        $this->listing(['search' => 'Scope Beacon', 'per_page' => 1])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.orders.0.id', $visible->id);
        $this->listing(['search' => 'Scope Beacon', 'assignee_id' => $otherStaff->id])
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_query_parameters_return_field_errors(): void
    {
        $this->authenticateAsManager();

        $this->listing([
            'status' => 'unknown',
            'priority' => 'unknown',
            'customer_id' => 0,
            'assignee_id' => 'employee',
            'date_from' => '2026-02-30',
            'sort' => 'created_at',
            'direction' => 'sideways',
            'page' => 0,
            'per_page' => Config::integer('pagination.orders.max_per_page') + 1,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'status', 'priority', 'customer_id', 'assignee_id', 'date_from',
            'sort', 'direction', 'page', 'per_page',
        ], 'errors.fields');

        $this->listing(['date_from' => '2026-11-22', 'date_to' => '2026-11-21'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to'], 'errors.fields');
    }

    public function test_listing_uses_search_criteria_from_the_query_string(): void
    {
        $this->authenticateAsManager();

        $match = Order::factory()->create(['order_number' => 'ORD-QUERY-TARGET']);
        Order::factory()->create(['order_number' => 'ORD-BODY-TARGET']);

        $this->json('GET', self::ENDPOINT.'?search=QUERY-TARGET', ['search' => 'BODY-TARGET'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.orders.0.id', $match->id);
    }

    private function authenticateAsManager(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->withToken($manager->createToken('order-listing')->plainTextToken);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return TestResponse<Response>
     */
    private function listing(array $query): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
