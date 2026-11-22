<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Enums\OrderPriority;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** @phpstan-import-type OrderInputItem from OrderService */
final class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/orders';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
            'orders.currency' => 'USD',
        ]);

    }

    public function test_manager_creates_an_assigned_draft_with_a_note_and_server_calculated_totals(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $staff = User::factory()->create()->assignRole('staff');
        $customer = Customer::factory()->create();
        $first = Product::factory()->create(['name' => 'Tool cabinet', 'price' => '100.00', 'tax_rate' => '10.00', 'stock_quantity' => 8]);
        $second = Product::factory()->create(['price' => '50.00', 'tax_rate' => '10.00', 'stock_quantity' => 5]);

        $this->useBearerFor($manager);
        $this->postJson(self::ENDPOINT, [
            ...self::payload($customer, [
                ['product_id' => $first->id, 'quantity' => 2],
                ['product_id' => $second->id, 'quantity' => 3],
            ]),
            'priority' => 'high',
            'assignee_id' => $staff->id,
            'note' => 'Confirm the collection time.',
            'order_discount_minor' => 2_000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.order.status', 'draft')
            ->assertJsonPath('data.order.priority', 'high')
            ->assertJsonPath('data.order.version', 1)
            ->assertJsonPath('data.order.currency', 'USD')
            ->assertJsonPath('data.order.customer.id', $customer->id)
            ->assertJsonPath('data.order.assignee.id', $staff->id)
            ->assertJsonPath('data.order.notes.0.body', 'Confirm the collection time.')
            ->assertJsonPath('data.order.notes.0.author.id', $manager->id)
            ->assertJsonPath('data.order.subtotal_minor', 35_000)
            ->assertJsonPath('data.order.discount_total_minor', 2_000)
            ->assertJsonPath('data.order.tax_total_minor', 3_300)
            ->assertJsonPath('data.order.grand_total_minor', 36_300)
            ->assertJsonPath('data.order.items.0.product_name', 'Tool cabinet')
            ->assertJsonPath('data.order.items.0.product_sku', $first->sku)
            ->assertJsonPath('data.order.items.0.unit_price_minor', 10_000)
            ->assertJsonPath('data.order.items.0.tax_rate_basis_points', 1_000)
            ->assertJsonPath('data.order.items.1.allocated_order_discount_minor', 857);

        $order = Order::query()->sole();
        $this->postJson(self::ENDPOINT.'/preview', [
            'order_id' => $order->id,
            'items' => [
                ['product_id' => $first->id, 'quantity' => 2],
                ['product_id' => $second->id, 'quantity' => 3],
            ],
        ])->assertOk()
            ->assertJsonPath('data.pricing.order_discount_minor', 2_000)
            ->assertJsonPath('data.pricing.grand_total_minor', 36_300);
        self::assertSame($manager->id, $order->created_by);
        $this->assertDatabaseHas('order_notes', [
            'order_id' => $order->id,
            'author_id' => $manager->id,
            'body' => 'Confirm the collection time.',
        ]);

        $created = AuditLog::query()->where('action', 'order.created')->sole();
        self::assertSame($manager->id, $created->actor_id);
        self::assertSame('order', $created->target_type);
        self::assertSame($order->id, $created->target_id);
        self::assertNull($created->before_values);
        self::assertSame(36_300, $created->after_values['grand_total_minor']);

        $assigned = AuditLog::query()->where('action', 'order.assigned')->sole();
        self::assertSame(['assignee_id' => null], $assigned->before_values);
        self::assertSame(['assignee_id' => $staff->id], $assigned->after_values);

        self::assertSame(8, $first->refresh()->stock_quantity);
        self::assertSame(5, $second->refresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_manager_cannot_delete_even_with_the_delete_permission(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $manager->givePermissionTo('orders.delete');
        $order = Order::factory()->create(['created_by' => $manager->id]);

        $this->useBearerFor($manager);
        $this->deleteJson(self::ENDPOINT.'/'.$order->id.'?expected_version=1')
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);

        self::assertFalse($order->refresh()->trashed());
        self::assertSame(1, $order->version);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_staff_list_and_show_return_only_assigned_orders(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $otherStaff = User::factory()->create()->assignRole('staff');
        $assigned = Order::factory()->create(['assignee_id' => $staff->id]);
        $other = Order::factory()->create(['assignee_id' => $otherStaff->id]);
        Order::factory()->create();

        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->useBearerFor($staff);

        $this->getJson(self::ENDPOINT.'?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $assigned->id);

        $this->getJson(self::ENDPOINT.'/'.$assigned->id)
            ->assertOk()
            ->assertJsonPath('data.order.id', $assigned->id);
        $this->getJson(self::ENDPOINT.'/'.$other->id)
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);
        $this->patchJson(self::ENDPOINT.'/'.$assigned->id, ['expected_version' => 1, 'priority' => 'high'])
            ->assertForbidden();
    }

    public function test_versioned_edits_keep_existing_snapshots_and_price_new_products(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $customer = Customer::factory()->create();
        $existing = Product::factory()->create(['name' => 'Original name', 'price' => '100.00', 'tax_rate' => '10.00']);
        $additional = Product::factory()->create(['price' => '60.00', 'tax_rate' => '5.00']);
        $this->useBearerFor($admin);
        $this->postJson(self::ENDPOINT, self::payload($customer, [
            ['product_id' => $existing->id, 'quantity' => 1],
        ]))->assertCreated();

        $order = Order::query()->sole();
        $path = self::ENDPOINT.'/'.$order->id;
        $existing->update(['name' => 'New catalog name', 'price' => '160.00', 'tax_rate' => '18.00']);
        $items = [
            ['product_id' => $existing->id, 'quantity' => 2],
            ['product_id' => $additional->id, 'quantity' => 1],
        ];

        $this->postJson(self::ENDPOINT.'/preview', ['order_id' => $order->id, 'items' => $items])
            ->assertOk()
            ->assertJsonPath('data.pricing.grand_total_minor', 28_300);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('audit_logs', 1);

        $this->patchJson($path, ['expected_version' => 1, 'items' => $items])
            ->assertOk()
            ->assertJsonPath('data.order.version', 2)
            ->assertJsonPath('data.order.grand_total_minor', 28_300)
            ->assertJsonPath('data.order.items.0.product_name', 'Original name')
            ->assertJsonPath('data.order.items.0.unit_price_minor', 10_000)
            ->assertJsonPath('data.order.items.0.tax_rate_basis_points', 1_000)
            ->assertJsonPath('data.order.items.1.product_id', $additional->id)
            ->assertJsonPath('data.order.items.1.unit_price_minor', 6_000)
            ->assertJsonPath('data.order.items.1.tax_rate_basis_points', 500);

        $this->patchJson($path, ['expected_version' => 1, 'priority' => 'high'])
            ->assertConflict()
            ->assertJsonPath('errors.code', ApiErrorCode::ORDER_VERSION_CONFLICT)
            ->assertJsonPath('meta.version', 2);

        $lineIds = $order->items()->pluck('id')->all();
        $this->patchJson($path, ['expected_version' => 2, 'items' => $items])
            ->assertOk()
            ->assertJsonPath('data.order.version', 2);
        self::assertSame($lineIds, $order->items()->pluck('id')->all());

        self::assertSame(OrderPriority::Normal, $order->refresh()->priority);
        self::assertSame(2, $order->version);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('stock_movements', 0);

        $audit = AuditLog::query()->where('action', 'order.updated')->sole();
        self::assertSame($admin->id, $audit->actor_id);
        self::assertSame(11_000, data_get($audit->before_values, 'grand_total_minor'));
        self::assertSame(28_300, $audit->after_values['grand_total_minor']);
    }

    public function test_admin_soft_delete_preserves_order_lines_and_audit_history(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $this->useBearerFor($admin);
        $this->postJson(self::ENDPOINT, self::payload($customer, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->assertCreated();

        $order = Order::query()->sole();
        $path = self::ENDPOINT.'/'.$order->id;

        $this->deleteJson($path, ['expected_version' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_version'], 'errors.fields');
        $this->deleteJson($path.'?expected_version=2')->assertConflict()
            ->assertJsonPath('meta.version', 1);
        $this->deleteJson($path.'?expected_version=1')->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->getJson($path)->assertNotFound();
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $product->id]);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('stock_movements', 0);

        $audit = AuditLog::query()->where('action', 'order.deleted')->sole();
        self::assertSame($admin->id, $audit->actor_id);
        self::assertSame($order->id, $audit->target_id);
        self::assertNull(data_get($audit->before_values, 'deleted_at'));
        self::assertNotNull($audit->after_values['deleted_at']);
        self::assertSame(2, $audit->after_values['version']);
    }

    public function test_create_rolls_back_header_lines_and_note_when_audit_save_fails(): void
    {
        $this->useBearerFor(User::factory()->create()->assignRole('manager'));
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        AuditLog::saving(static fn (): bool => false);

        $this->postJson(self::ENDPOINT, [
            ...self::payload($customer, [['product_id' => $product->id, 'quantity' => 1]]),
            'note' => 'This note must roll back with the order.',
        ])
            ->assertInternalServerError()
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_notes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_create_rejects_query_only_fields_invalid_items_and_forged_values(): void
    {
        $this->useBearerFor(User::factory()->create()->assignRole('manager'));
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $items = [['product_id' => $product->id, 'quantity' => 1]];
        $payload = self::payload($customer, $items);

        $this->postJson(self::ENDPOINT.'?customer_id='.$customer->id, [
            'order_date' => $payload['order_date'],
            'items' => $items,
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id'], 'errors.fields');

        $this->postJson(self::ENDPOINT, [...$payload, 'items' => (object) $items])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items'], 'errors.fields');

        $this->postJson(self::ENDPOINT, [...$payload, 'items' => [['product_id' => 999_999, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id'], 'errors.fields');

        $this->postJson(self::ENDPOINT, [...$payload, 'grand_total_minor' => 1, 'status' => 'confirmed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body'], 'errors.fields');

        $this->postJson(self::ENDPOINT, [
            ...$payload,
            'items' => [[...$items[0], 'unit_price_minor' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0'], 'errors.fields');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function useBearerFor(User $actor): void
    {
        Auth::forgetGuards();
        $this->withToken($actor->createToken('order-test')->plainTextToken);
    }

    /**
     * @param  list<OrderInputItem>  $items
     * @return array{customer_id: int, order_date: string, items: list<OrderInputItem>}
     */
    private static function payload(Customer $customer, array $items): array
    {
        return [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-22',
            'items' => $items,
        ];
    }
}
