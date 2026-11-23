<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\OrderNotificationType;
use App\Enums\OrderStatus;
use App\Jobs\OrderNotificationJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNotification;
use App\Models\OrderNotificationEvent;
use App\Models\Product;
use App\Models\User;
use App\Services\Notifications\OrderNotificationDeliveryService;
use App\Services\Notifications\OrderNotificationOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class OrderNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_creation_and_reassignment_record_only_genuine_new_assignments(): void
    {
        Bus::fake();
        $this->authenticateAsManager();
        $initial = User::factory()->create()->assignRole('staff');
        $next = User::factory()->create()->assignRole('staff');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $payload = [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-23',
            'assignee_id' => $initial->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $headers = ['Idempotency-Key' => 'notification-create-assignment'];

        $this->postJson('/api/orders', $payload, $headers)->assertCreated();
        $order = Order::query()->sole();
        $this->postJson('/api/orders', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.order.id', $order->id);

        $assignment = '/api/orders/'.$order->id.'/assign';
        $this->postJson($assignment, ['assignee_id' => $initial->id, 'expected_version' => 1])->assertOk();
        $this->postJson($assignment, ['assignee_id' => $next->id, 'expected_version' => 1])->assertOk();
        $this->postJson($assignment, ['assignee_id' => null, 'expected_version' => 2])->assertOk();

        $this->assertDatabaseCount('order_notification_events', 2);
        foreach ([1 => $initial->id, 2 => $next->id] as $version => $recipientId) {
            $this->assertDatabaseHas('order_notification_events', [
                'order_id' => $order->id,
                'order_version' => $version,
                'event_type' => OrderNotificationType::Assigned->value,
                'recipient_id' => $recipientId,
            ]);
        }
        Bus::assertDispatchedTimes(OrderNotificationJob::class, 2);
    }

    public function test_confirmation_and_cancellation_deliver_once_to_the_captured_assignee(): void
    {
        Bus::fake();
        $this->authenticateAsManager();
        [$order, $product] = $this->pendingOrder();
        $path = '/api/orders/'.$order->id.'/status';
        $this->postJson($path, ['status' => 'confirmed', 'expected_version' => 1])->assertOk();
        self::assertSame(4, $product->refresh()->stock_quantity);
        $this->postJson($path, ['status' => 'cancelled', 'expected_version' => 2])->assertOk();
        self::assertSame(5, $product->refresh()->stock_quantity);

        $events = OrderNotificationEvent::query()->orderBy('order_version')->get();
        self::assertSame(
            [OrderNotificationType::Confirmed, OrderNotificationType::Cancelled],
            $events->pluck('event_type')->all(),
        );
        self::assertSame([2, 3], $events->pluck('order_version')->all());
        Bus::assertDispatchedTimes(OrderNotificationJob::class, 2);

        foreach ($events as $event) {
            $delivery = app(OrderNotificationDeliveryService::class);
            (new OrderNotificationJob($event->id))->handle($delivery);
            (new OrderNotificationJob($event->id))->handle($delivery);

            self::assertSame($order->assignee_id, $event->recipient_id);
            self::assertNotNull($event->refresh()->processed_at);
            self::assertNull($event->skip_reason);
            $this->assertDatabaseHas('order_notifications', [
                'event_id' => $event->id,
                'recipient_id' => $event->recipient_id,
                'read_at' => null,
            ]);
        }

        $this->assertDatabaseCount('order_notifications', 2);
    }

    public function test_failed_outbox_write_rolls_back_the_order_inventory_and_history(): void
    {
        Bus::fake();
        $this->authenticateAsManager();
        [$order, $product] = $this->pendingOrder();
        OrderNotificationEvent::saving(static fn (): bool => false);

        $this->postJson('/api/orders/'.$order->id.'/status', ['status' => 'confirmed', 'expected_version' => 1])
            ->assertInternalServerError();

        self::assertSame(OrderStatus::Pending, $order->refresh()->status);
        self::assertSame(1, $order->version);
        self::assertSame(5, $product->refresh()->stock_quantity);
        $this->assertDatabaseEmpty('stock_movements');
        $this->assertDatabaseEmpty('order_status_histories');
        $this->assertDatabaseEmpty('audit_logs');
        $this->assertDatabaseEmpty('order_notification_events');
        $this->assertDatabaseEmpty('order_notifications');
        Bus::assertNothingDispatched();
    }

    public function test_rolled_back_order_transaction_does_not_dispatch_a_job(): void
    {
        Bus::fake();
        $recipient = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create(['assignee_id' => $recipient->id]);

        DB::beginTransaction();
        try {
            app(OrderNotificationOutbox::class)->record($order, OrderNotificationType::Assigned);
            $this->assertDatabaseCount('order_notification_events', 1);
            Bus::assertNothingDispatched();
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseEmpty('order_notification_events');
        Bus::assertNothingDispatched();
    }

    public function test_failed_post_commit_dispatch_is_recovered_without_queueing_the_event_twice(): void
    {
        Config::set('order_notifications.recovery_delay_seconds', 5);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $this->authenticateAsManager();
        $recipient = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create();

        $this->postJson('/api/orders/'.$order->id.'/assign', [
            'assignee_id' => $recipient->id,
            'expected_version' => 1,
        ])->assertOk();

        $event = OrderNotificationEvent::query()->sole();
        self::assertSame($recipient->id, $order->refresh()->assignee_id);
        self::assertNull($event->processed_at);
        $this->assertDatabaseEmpty('order_notifications');

        Bus::fake();
        $this->recoverPendingNotifications();
        Bus::assertNothingDispatched();

        $this->travel(6)->seconds();
        $this->recoverPendingNotifications();
        $this->recoverPendingNotifications();
        Bus::assertDispatchedTimes(OrderNotificationJob::class, 1);
        Bus::assertDispatched(OrderNotificationJob::class, static fn (OrderNotificationJob $job): bool => $job->eventId === $event->id);

        (new OrderNotificationJob($event->id))->handle(app(OrderNotificationDeliveryService::class));
        self::assertNotNull($event->refresh()->processed_at);
        $this->assertDatabaseCount('order_notifications', 1);
    }

    /** @param 'reassigned'|'disabled'|'revoked'|'deleted' $change */
    #[DataProvider('recipientChanges')]
    public function test_delayed_delivery_skips_an_ineligible_recipient(string $change, string $reason): void
    {
        $recipient = User::factory()->create()->givePermissionTo('orders.view.assigned');
        $order = Order::factory()->create(['assignee_id' => $recipient->id]);
        $event = $this->event($order, $recipient);

        match ($change) {
            'reassigned' => $order->forceFill(['assignee_id' => User::factory()->create()->id])->saveOrFail(),
            'disabled' => $recipient->forceFill(['is_active' => false])->saveOrFail(),
            'revoked' => $recipient->revokePermissionTo('orders.view.assigned'),
            'deleted' => $order->delete(),
        };

        app(OrderNotificationDeliveryService::class)->deliver($event->id);

        self::assertNotNull($event->refresh()->processed_at);
        self::assertSame($reason, $event->skip_reason);
        $this->assertDatabaseEmpty('order_notifications');
    }

    /** @return array<string, array{'reassigned'|'disabled'|'revoked'|'deleted', string}> */
    public static function recipientChanges(): array
    {
        return [
            'reassigned' => ['reassigned', 'recipient_ineligible'],
            'disabled' => ['disabled', 'recipient_ineligible'],
            'revoked' => ['revoked', 'recipient_ineligible'],
            'deleted' => ['deleted', 'order_unavailable'],
        ];
    }

    public function test_failed_worker_rolls_back_delivery_and_recovery_can_retry(): void
    {
        $recipient = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create(['assignee_id' => $recipient->id]);
        $event = $this->event($order, $recipient);
        OrderNotificationEvent::saving(static fn (OrderNotificationEvent $event): bool => ! $event->isDirty('processed_at'));

        $job = new OrderNotificationJob($event->id);
        $delivery = app(OrderNotificationDeliveryService::class);

        $this->assertThrows(
            static fn () => $job->handle($delivery),
            RuntimeException::class,
            'The order notification event could not be marked processed.',
        );

        self::assertNull($event->refresh()->processed_at);
        $this->assertDatabaseEmpty('order_notifications');

        OrderNotificationEvent::flushEventListeners();
        Bus::fake();
        $this->recoverPendingNotifications();
        Bus::assertDispatchedTimes(OrderNotificationJob::class, 1);
        Bus::assertDispatched(OrderNotificationJob::class, static fn (OrderNotificationJob $job): bool => $job->eventId === $event->id);

        $job->handle($delivery);
        self::assertNotNull($event->refresh()->processed_at);
        $this->assertDatabaseCount('order_notifications', 1);
    }

    public function test_database_enforces_event_identity_and_the_intended_recipient(): void
    {
        $recipient = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['assignee_id' => $recipient->id]);
        $event = $this->event($order, $recipient);

        $this->assertThrows(fn () => $this->event($order, $other), UniqueConstraintViolationException::class);
        $this->event($order, $recipient, version: 2);
        $this->assertDatabaseCount('order_notification_events', 2);

        $notification = ['event_id' => $event->id, 'recipient_id' => $recipient->id];
        OrderNotification::query()->create($notification);
        $this->assertThrows(static fn () => OrderNotification::query()->create($notification), UniqueConstraintViolationException::class);
        $this->assertThrows(static fn () => OrderNotification::query()->create([
            'event_id' => $event->id,
            'recipient_id' => $other->id,
        ]), QueryException::class);
        $this->assertDatabaseCount('order_notifications', 1);
    }

    private function authenticateAsManager(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->withToken($manager->createToken('order notification test')->plainTextToken);
    }

    /** @return array{Order, Product} */
    private function pendingOrder(): array
    {
        $recipient = User::factory()->create()->assignRole('staff');
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'assignee_id' => $recipient->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);

        return [$order, $product];
    }

    private function event(Order $order, User $recipient, int $version = 1): OrderNotificationEvent
    {
        return OrderNotificationEvent::query()->create([
            'order_id' => $order->id,
            'order_version' => $version,
            'event_type' => OrderNotificationType::Assigned,
            'recipient_id' => $recipient->id,
            'available_at' => now(),
        ]);
    }

    private function recoverPendingNotifications(): void
    {
        $command = $this->artisan('oms:notifications:recover');
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
    }
}
