<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** @phpstan-import-type CreateOrderData from OrderService */
final class OrderCreationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/orders';

    private const string KEY = 'order-test.1908_~';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
            'orders.currency' => 'USD',
        ]);
    }

    public function test_same_actor_replays_an_equivalent_payload_using_a_new_bearer_token(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = User::factory()->create()->assignRole('staff');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['price' => '12.00', 'tax_rate' => '0.00']);
        $data = [
            ...self::payload($customer, $product),
            'assignee_id' => $employee->id,
            'note' => 'Confirm the collection time.',
        ];

        $this->useBearer($manager->createToken('order creation')->plainTextToken);
        $this->postJson(self::ENDPOINT, $data, ['Idempotency-Key' => self::KEY])->assertCreated();

        $order = Order::query()->sole();
        $location = self::ENDPOINT.'/'.$order->id;
        $product->update(['is_active' => false, 'price' => '20.00']);
        $this->patchJson($location, ['expected_version' => 1, 'priority' => 'high'])->assertOk();

        $this->useBearer($manager->createToken('order creation retry')->plainTextToken);
        $this->postJson(self::ENDPOINT, [
            'items' => [['item_discount_minor' => 0, 'quantity' => 2, 'product_id' => $product->id]],
            'order_discount_minor' => 0,
            'priority' => 'normal',
            'note' => $data['note'],
            'assignee_id' => $employee->id,
            'order_date' => $data['order_date'],
            'customer_id' => $customer->id,
        ], ['Idempotency-Key' => self::KEY])
            ->assertCreated()
            ->assertHeader('Location', $location)
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath('data.order.version', 2)
            ->assertJsonPath('data.order.priority', 'high')
            ->assertJsonPath('data.order.assignee.id', $employee->id)
            ->assertJsonPath('data.order.items.0.unit_price_minor', 1_200)
            ->assertJsonPath('data.order.notes.0.body', $data['note'])
            ->assertJsonCount(1, 'data.order.assignment_history');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_notes', 1);
        $this->assertDatabaseCount('order_creation_keys', 1);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('order_creation_keys', ['actor_id' => $manager->id, 'order_id' => $order->id]);
    }

    public function test_idempotent_tax_override_requires_current_order_update_permission(): void
    {
        $actor = User::factory()->create()->givePermissionTo('orders.view.any', 'orders.create', 'orders.update');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['price' => '20.00', 'tax_rate' => '18.00']);
        $data = self::payload($customer, $product);
        $data['items'][0]['tax_rate_basis_points'] = 500;
        $headers = ['Idempotency-Key' => self::KEY];
        $token = $actor->createToken('order creation')->plainTextToken;
        $this->useBearer($token);

        $this->postJson(self::ENDPOINT.'/preview', ['items' => $data['items']])->assertOk()
            ->assertJsonPath('data.pricing.grand_total_minor', 4_200);
        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated()
            ->assertJsonPath('data.order.items.0.tax_rate_basis_points', 500)
            ->assertJsonPath('data.order.grand_total_minor', 4_200);
        $order = Order::query()->sole();
        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated()
            ->assertJsonPath('data.order.id', $order->id);
        $changed = $data;
        $changed['items'][0]['tax_rate_basis_points'] = 0;
        $this->postJson(self::ENDPOINT, $changed, $headers)->assertConflict()
            ->assertJsonPath('errors.code', ApiErrorCode::ORDER_IDEMPOTENCY_CONFLICT);

        $actor->revokePermissionTo('orders.update');
        $this->useBearer($token);
        $this->postJson(self::ENDPOINT, $data, $headers)->assertForbidden();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_creation_keys', 1);
    }

    public function test_changed_payload_conflicts_and_the_same_key_for_another_actor_creates_a_separate_order(): void
    {
        $first = User::factory()->create()->assignRole('manager');
        $second = User::factory()->create()->assignRole('manager');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $data = self::payload($customer, $product);
        $headers = ['Idempotency-Key' => self::KEY];

        $this->useBearer($first->createToken('order creation')->plainTextToken);
        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated();
        $this->postJson(self::ENDPOINT, [
            ...$data,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ], $headers)
            ->assertConflict()
            ->assertJsonPath('errors.code', ApiErrorCode::ORDER_IDEMPOTENCY_CONFLICT);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 2]);

        $this->useBearer($second->createToken('order creation')->plainTextToken);
        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated();

        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('order_creation_keys', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('orders', ['created_by' => $first->id]);
        $this->assertDatabaseHas('orders', ['created_by' => $second->id]);
    }

    public function test_replay_requires_current_creation_and_assignment_permissions(): void
    {
        $actor = User::factory()->create()->givePermissionTo('orders.view.any', 'orders.create', 'orders.assign');
        $employee = User::factory()->create()->assignRole('staff');
        $data = [
            ...self::payload(Customer::factory()->create(), Product::factory()->create()),
            'assignee_id' => $employee->id,
        ];
        $token = $actor->createToken('order creation')->plainTextToken;
        $headers = ['Idempotency-Key' => self::KEY];

        $this->useBearer($token);
        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated();
        $order = Order::query()->sole();

        $actor->revokePermissionTo('orders.assign');
        $this->useBearer($token);
        $this->getJson(self::ENDPOINT.'/'.$order->id)->assertOk();
        $this->postJson(self::ENDPOINT, $data, $headers)
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);

        $actor->givePermissionTo('orders.assign')->revokePermissionTo('orders.create');
        $this->useBearer($token);
        $this->postJson(self::ENDPOINT, $data, $headers)
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_creation_keys', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_replay_denies_access_after_an_assignment_role_grant_is_revoked(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = User::factory()->create()->assignRole('staff');
        $data = [
            ...self::payload(Customer::factory()->create(), Product::factory()->create()),
            'assignee_id' => $employee->id,
        ];
        $token = $manager->createToken('order creation')->plainTextToken;
        $headers = ['Idempotency-Key' => self::KEY];

        $this->useBearer($token);
        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated();

        Role::findByName('manager', Guard::getDefaultName($manager))->revokePermissionTo('orders.assign');

        $this->useBearer($token);
        $this->postJson(self::ENDPOINT, $data, $headers)
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_creation_keys', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_missing_malformed_and_repeated_headers_cannot_create_an_order(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $data = self::payload(Customer::factory()->create(), Product::factory()->create());
        $this->useBearer($manager->createToken('order creation')->plainTextToken);

        foreach ([
            [self::ENDPOINT, $data, []],
            [self::ENDPOINT.'?idempotency_key='.self::KEY, $data, []],
            [self::ENDPOINT, [...$data, 'idempotency_key' => self::KEY], []],
            [self::ENDPOINT, $data, ['Idempotency-Key' => '']],
            [self::ENDPOINT, $data, ['Idempotency-Key' => 'with space']],
            [self::ENDPOINT, $data, ['Idempotency-Key' => str_repeat('a', 129)]],
            [self::ENDPOINT, $data, ['Idempotency-Key' => [self::KEY, 'another-key']]],
            [self::ENDPOINT, $data, ['Idempotency-Key' => self::KEY.', another-key']],
        ] as [$uri, $payload, $headers]) {
            $this->postJson($uri, $payload, $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('idempotency_key', 'errors.fields');
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_creation_keys', 0);
    }

    public function test_failed_audit_releases_the_key_for_a_retry(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $data = [
            ...self::payload(Customer::factory()->create(), Product::factory()->create()),
            'note' => 'This note is part of the transaction.',
        ];
        $headers = ['Idempotency-Key' => self::KEY];
        $this->useBearer($manager->createToken('order creation')->plainTextToken);
        AuditLog::saving(static fn (): bool => false);

        $this->postJson(self::ENDPOINT, $data, $headers)
            ->assertInternalServerError()
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        foreach (['orders', 'order_items', 'order_notes', 'order_creation_keys', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        AuditLog::flushEventListeners();
        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated();

        foreach (['orders', 'order_items', 'order_notes', 'order_creation_keys', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
    }

    public function test_soft_deleted_order_retains_its_key_and_cannot_be_recreated(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $data = self::payload(Customer::factory()->create(), Product::factory()->create());
        $headers = ['Idempotency-Key' => self::KEY];
        $this->useBearer($admin->createToken('order creation')->plainTextToken);

        $this->postJson(self::ENDPOINT, $data, $headers)->assertCreated();
        $order = Order::query()->sole();
        $this->deleteJson(self::ENDPOINT.'/'.$order->id.'?expected_version=1')->assertOk();

        $this->postJson(self::ENDPOINT, $data, $headers)
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_creation_keys', 1);
        $this->assertDatabaseHas('order_creation_keys', ['actor_id' => $admin->id, 'order_id' => $order->id]);
    }

    private function useBearer(string $token): void
    {
        Auth::forgetGuards();
        $this->withToken($token);
    }

    /** @return CreateOrderData */
    private static function payload(Customer $customer, Product $product): array
    {
        return [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-22',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];
    }
}
