<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const int QUANTITY = 5;

    private const int UNIT_PRICE_MINOR = 2500;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_manager_completes_the_order_through_each_transition_and_records_its_history(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $product = $this->product(100);
        $order = $this->orderWithItems($manager, [$product]);
        $this->useBearerFor($manager);

        $this->transition($order, OrderStatus::Confirmed, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status', 'errors.fields');

        $previous = OrderStatus::Draft;
        $targets = [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Ready, OrderStatus::Completed];
        foreach ($targets as $index => $target) {
            $remarks = $target === OrderStatus::Completed ? 'Delivered to the buyer.' : null;
            $this->transition($order, $target, $index + 1, $remarks)
                ->assertOk()
                ->assertJsonPath('data.order.status', $target->value)
                ->assertJsonPath('data.order.version', $index + 2)
                ->assertJsonCount($index + 1, 'data.order.status_history')
                ->assertJsonPath("data.order.status_history.{$index}.previous_status", $previous->value)
                ->assertJsonPath("data.order.status_history.{$index}.new_status", $target->value)
                ->assertJsonPath("data.order.status_history.{$index}.actor.id", $manager->id)
                ->assertJsonPath("data.order.status_history.{$index}.actor.name", $manager->name)
                ->assertJsonPath("data.order.status_history.{$index}.remarks", $remarks)
                ->assertJsonPath("data.order.status_history.{$index}.created_at", static fn (mixed $time): bool => is_string($time) && $time !== '');
            $previous = $target;

            if ($target === OrderStatus::Pending) {
                self::assertSame(100, $product->refresh()->stock_quantity);
                $this->transition($order, OrderStatus::Pending, 2)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('status', 'errors.fields');
            }
        }

        $this->transition($order, OrderStatus::Draft, 6)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status', 'errors.fields');
        $this->transition($order, OrderStatus::Cancelled, 6)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status', 'errors.fields');

        self::assertSame(OrderStatus::Completed, $order->refresh()->status);
        $completedAt = $order->completed_at;
        self::assertNotNull($completedAt);
        $this->getJson('/api/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.order.completed_at', $completedAt->toISOString())
            ->assertJsonCount(5, 'data.order.status_history');
        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonMissingPath('data.orders.0.status_history');

        self::assertSame(95, $product->refresh()->stock_quantity);
        self::assertSame(1, $product->stock_version);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'actor_id' => $manager->id,
            'type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity_before' => 100,
            'quantity_after' => 95,
            'quantity_delta' => -5,
            'stock_version' => 1,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('order_status_histories', 5);
        $this->assertDatabaseCount('audit_logs', 5);

        $audit = AuditLog::query()->latest('id')->firstOrFail();
        self::assertSame($manager->id, $audit->actor_id);
        self::assertSame('order', $audit->target_type);
        self::assertSame($order->id, $audit->target_id);
        self::assertSame('order.status_changed', $audit->action);
        self::assertSame('ready', data_get($audit->before_values, 'status'));
        self::assertSame('completed', $audit->after_values['status']);
        self::assertSame(6, $audit->after_values['version']);
        self::assertSame($completedAt->toIso8601String(), $audit->after_values['completed_at']);
    }

    public function test_staff_can_only_advance_their_assigned_orders_to_processing_and_ready(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $staff = User::factory()->create()->assignRole('staff');
        $otherStaff = User::factory()->create()->assignRole('staff');
        $product = $this->product(100);
        $assigned = $this->orderWithItems($manager, [$product], OrderStatus::Pending, $staff);
        $other = $this->orderWithItems($manager, [$product], OrderStatus::Pending, $otherStaff);

        $this->useBearerFor($manager);
        $this->transition($assigned, OrderStatus::Confirmed, 1)->assertOk();
        $this->transition($other, OrderStatus::Confirmed, 1)->assertOk();

        $this->useBearerFor($staff);
        $this->transition($other, OrderStatus::Processing, 2)->assertNotFound();
        $this->transition($assigned, OrderStatus::Cancelled, 2)->assertForbidden();
        $this->transition($assigned, OrderStatus::Processing, 2)
            ->assertOk()
            ->assertJsonPath('data.order.status_history.1.actor.id', $staff->id);
        $this->transition($assigned, OrderStatus::Ready, 3)->assertOk();
        $this->transition($assigned, OrderStatus::Completed, 4)->assertForbidden();

        self::assertSame(OrderStatus::Ready, $assigned->refresh()->status);
        self::assertSame(4, $assigned->version);
        self::assertSame(OrderStatus::Confirmed, $other->refresh()->status);
        self::assertSame(2, $other->version);
        $this->assertDatabaseCount('order_status_histories', 4);
        $this->assertDatabaseCount('audit_logs', 4);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_cancellation_restores_the_deduction_once_after_a_manual_stock_adjustment(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $product = $this->product(100);
        $order = $this->orderWithItems($admin, [$product], OrderStatus::Pending);
        $this->useBearerFor($admin);

        $this->transition($order, OrderStatus::Confirmed, 1)->assertOk();
        $this->patchJson('/api/products/'.$product->id.'/stock', [
            'stock_quantity' => 80,
            'expected_stock_version' => 1,
            'reason' => 'Cycle count identified damaged units.',
        ])->assertOk();

        $this->transition($order, OrderStatus::Cancelled, 2)
            ->assertOk()
            ->assertJsonPath('data.order.status', 'cancelled')
            ->assertJsonPath('data.order.version', 3);
        $this->transition($order, OrderStatus::Cancelled, 2)
            ->assertConflict()
            ->assertJsonPath('errors.code', ApiErrorCode::ORDER_VERSION_CONFLICT)
            ->assertJsonPath('meta.version', 3);
        $this->transition($order, OrderStatus::Cancelled, 3)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status', 'errors.fields');

        self::assertSame(85, $product->refresh()->stock_quantity);
        self::assertSame(3, $product->stock_version);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'actor_id' => $admin->id,
            'type' => StockMovement::TYPE_ORDER_RESTORATION,
            'quantity_before' => 80,
            'quantity_after' => 85,
            'quantity_delta' => 5,
            'stock_version' => 3,
        ]);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('order_status_histories', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_cancelling_a_draft_or_pending_order_leaves_stock_unchanged(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $product = $this->product(100);
        $this->useBearerFor($manager);

        foreach ([OrderStatus::Draft, OrderStatus::Pending] as $initialStatus) {
            $order = $this->orderWithItems($manager, [$product], $initialStatus);

            $this->transition($order, OrderStatus::Cancelled, 1)
                ->assertOk()
                ->assertJsonPath('data.order.status', 'cancelled')
                ->assertJsonPath('data.order.version', 2);
        }

        self::assertSame(100, $product->refresh()->stock_quantity);
        self::assertSame(0, $product->stock_version);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('order_status_histories', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    /** @return array<string, array{bool, int}> */
    public static function unavailableProducts(): array
    {
        return [
            'inactive' => [false, 100],
            'insufficient stock' => [true, 4],
        ];
    }

    #[DataProvider('unavailableProducts')]
    public function test_confirmation_rolls_back_every_stock_change_when_a_product_is_unavailable(bool $active, int $stock): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $first = $this->product(100);
        $unavailable = $this->product($stock, $active);
        $order = $this->orderWithItems($manager, [$first, $unavailable], OrderStatus::Pending);
        $this->useBearerFor($manager);

        $this->transition($order, OrderStatus::Confirmed, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items', 'errors.fields');

        self::assertSame(OrderStatus::Pending, $order->refresh()->status);
        self::assertSame(1, $order->version);
        self::assertSame(100, $first->refresh()->stock_quantity);
        self::assertSame(0, $first->stock_version);
        self::assertSame($stock, $unavailable->refresh()->stock_quantity);
        self::assertSame(0, $unavailable->stock_version);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_confirmation_rolls_back_its_status_history_and_inventory_when_audit_save_fails(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $product = $this->product(100);
        $order = $this->orderWithItems($manager, [$product], OrderStatus::Pending);
        $this->useBearerFor($manager);
        AuditLog::saving(static fn (): bool => false);

        $this->transition($order, OrderStatus::Confirmed, 1)
            ->assertInternalServerError()
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        self::assertSame(OrderStatus::Pending, $order->refresh()->status);
        self::assertSame(1, $order->version);
        self::assertSame(100, $product->refresh()->stock_quantity);
        self::assertSame(0, $product->stock_version);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_status_command_validates_only_the_json_body(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $order = $this->orderWithItems($manager, [$this->product(100)]);
        $this->useBearerFor($manager);
        Config::set('orders.max_note_length', 3);
        $path = '/api/orders/'.$order->id.'/status';

        $this->postJson($path.'?status=pending&expected_version=1', ['remarks' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'expected_version'], 'errors.fields');
        $this->postJson($path, ['status' => 'unknown', 'expected_version' => '1', 'remarks' => 'Long'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'expected_version', 'remarks'], 'errors.fields');
        $this->postJson($path, ['status' => 'pending', 'expected_version' => 1, 'actor_id' => $manager->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body', 'errors.fields');

        self::assertSame(OrderStatus::Draft, $order->refresh()->status);
        self::assertSame(1, $order->version);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function product(int $stock, bool $active = true): Product
    {
        return Product::factory()->create([
            'price' => '25.00',
            'tax_rate' => '0.00',
            'stock_quantity' => $stock,
            'is_active' => $active,
        ]);
    }

    /** @param list<Product> $products */
    private function orderWithItems(
        User $creator,
        array $products,
        OrderStatus $status = OrderStatus::Draft,
        ?User $assignee = null,
    ): Order {
        $lineSubtotal = self::UNIT_PRICE_MINOR * self::QUANTITY;
        $subtotal = count($products) * $lineSubtotal;
        $order = Order::factory()->create([
            'created_by' => $creator->id,
            'assignee_id' => $assignee?->id,
            'status' => $status,
            'subtotal_minor' => $subtotal,
            'grand_total_minor' => $subtotal,
        ]);

        foreach ($products as $position => $product) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'position' => $position + 1,
                'quantity' => self::QUANTITY,
                'unit_price_minor' => self::UNIT_PRICE_MINOR,
                'subtotal_minor' => $lineSubtotal,
                'line_total_minor' => $lineSubtotal,
            ]);
        }

        return $order;
    }

    /** @return TestResponse<Response> */
    private function transition(Order $order, OrderStatus $status, int $version, ?string $remarks = null): TestResponse
    {
        return $this->postJson('/api/orders/'.$order->id.'/status', [
            'status' => $status->value,
            'expected_version' => $version,
            'remarks' => $remarks,
        ]);
    }

    private function useBearerFor(User $actor): void
    {
        Auth::forgetGuards();
        $this->withToken($actor->createToken('order-lifecycle-test')->plainTextToken);
    }
}
