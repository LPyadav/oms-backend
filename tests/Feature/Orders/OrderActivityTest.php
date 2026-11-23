<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class OrderActivityTest extends TestCase
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

    public function test_reassignment_moves_staff_access_and_preserves_assignment_history(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $previous = User::factory()->create()->assignRole('staff');
        $next = User::factory()->create()->assignRole('staff');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $managerToken = $manager->createToken('order activity test')->plainTextToken;
        $previousToken = $previous->createToken('order activity test')->plainTextToken;
        $nextToken = $next->createToken('order activity test')->plainTextToken;

        $this->useBearer($managerToken);
        $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-22',
            'assignee_id' => $previous->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'order-activity-test'])->assertCreated()
            ->assertJsonCount(1, 'data.order.assignment_history')
            ->assertJsonPath('data.order.assignment_history.0.previous_assignee_id', null)
            ->assertJsonPath('data.order.assignment_history.0.new_assignee_id', $previous->id);

        $order = Order::query()->sole();
        $path = '/api/orders/'.$order->id;
        $this->useBearer($previousToken);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('data.order.assignee', ['id' => $previous->id, 'name' => $previous->name]);
        $this->useBearer($nextToken);
        $this->getJson($path)->assertNotFound();

        $this->useBearer($managerToken);
        $result = $this->postJson($path.'/assign', [
            'assignee_id' => $next->id,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.order.assignee_id', $next->id)
            ->assertJsonPath('data.order.assignee', [
                'id' => $next->id,
                'name' => $next->name,
                'email' => $next->email,
                'roles' => ['staff'],
            ])
            ->assertJsonPath('data.order.status', OrderStatus::Draft->value)
            ->assertJsonPath('data.order.version', 2)
            ->assertJsonCount(2, 'data.order.assignment_history')
            ->assertJsonPath('data.order.assignment_history.0.new_assignee_id', $previous->id)
            ->assertJsonPath('data.order.assignment_history.1.previous_assignee_id', $previous->id)
            ->assertJsonPath('data.order.assignment_history.1.new_assignee_id', $next->id)
            ->assertJsonPath('data.order.assignment_history.1.actor_id', $manager->id)
            ->assertJsonPath('data.order.assignment_history.1.actor_name', $manager->name)
            ->assertJsonPath('data.order.assignment_history.1.created_at', static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->assertJsonMissingPath('data.order.assignment_history.1.before_values')
            ->assertJsonMissingPath('data.order.assignment_history.1.after_values');

        $history = AuditLog::query()->where('action', 'order.assigned')->latest('id')->firstOrFail();
        self::assertSame($manager->id, $history->actor_id);
        self::assertSame('order', $history->target_type);
        self::assertSame($order->id, $history->target_id);
        self::assertSame($previous->id, data_get($history->before_values, 'assignee_id'));
        self::assertSame($next->id, $history->after_values['assignee_id']);
        self::assertSame(1, data_get($history->before_values, 'version'));
        self::assertSame(2, $history->after_values['version']);
        $result->assertJsonPath('data.order.assignment_history.1.id', $history->id);

        $this->getJson('/api/orders')->assertOk()
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.assignee', ['id' => $next->id, 'name' => $next->name])
            ->assertJsonMissingPath('data.orders.0.assignment_history');
        $this->useBearer($previousToken);
        $this->getJson($path)->assertNotFound();
        $this->postJson($path.'/notes', ['body' => 'Former assignment note.'])->assertNotFound();
        $this->useBearer($nextToken);
        $this->getJson($path)->assertOk()
            ->assertJsonCount(2, 'data.order.assignment_history');

        self::assertSame($next->id, $order->refresh()->assignee_id);
        $this->assertDatabaseCount('order_notes', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_order_assignee_metadata_is_limited_to_assignment_permission(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $reviewer = User::factory()->create()->givePermissionTo('orders.view.any', 'orders.status.update.any');
        $assignee = User::factory()->create(['is_active' => false])->assignRole('staff');
        $order = Order::factory()->create([
            'created_by' => $manager->id,
            'assignee_id' => $assignee->id,
            'status' => OrderStatus::Confirmed,
        ]);
        $path = '/api/orders/'.$order->id;
        $identity = ['id' => $assignee->id, 'name' => $assignee->name];

        $this->useBearer($manager->createToken('order activity test')->plainTextToken);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('data.order.assignee', [
                ...$identity,
                'email' => $assignee->email,
                'roles' => ['staff'],
            ]);

        $this->useBearer($reviewer->createToken('order activity test')->plainTextToken);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('data.order.assignee', $identity);
        $this->postJson($path.'/status', [
            'status' => OrderStatus::Processing->value,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.order.assignee', $identity);
    }

    public function test_assignment_checks_version_and_keeps_an_unchanged_assignee_as_a_no_op(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create([
            'created_by' => $admin->id,
            'status' => OrderStatus::Ready,
        ]);
        $path = '/api/orders/'.$order->id.'/assign';
        $this->useBearer($admin->createToken('order activity test')->plainTextToken);

        $this->postJson($path, ['assignee_id' => $employee->id, 'expected_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.order.version', 2);
        $this->postJson($path, ['assignee_id' => $employee->id, 'expected_version' => 1])
            ->assertConflict()
            ->assertJsonPath('errors.code', ApiErrorCode::ORDER_VERSION_CONFLICT)
            ->assertJsonPath('meta.version', 2);
        $this->postJson($path, ['assignee_id' => $employee->id, 'expected_version' => 2])
            ->assertOk()
            ->assertJsonPath('data.order.version', 2)
            ->assertJsonCount(1, 'data.order.assignment_history');
        $this->assertDatabaseCount('audit_logs', 1);

        $this->postJson($path, ['assignee_id' => null, 'expected_version' => 2])
            ->assertOk()
            ->assertJsonPath('data.order.assignee_id', null)
            ->assertJsonPath('data.order.assignee', null)
            ->assertJsonPath('data.order.version', 3)
            ->assertJsonPath('data.order.assignment_history.1.previous_assignee_id', $employee->id)
            ->assertJsonPath('data.order.assignment_history.1.new_assignee_id', null);

        self::assertNull($order->refresh()->assignee_id);
        self::assertSame(3, $order->version);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_assignment_rejects_inactive_employees_and_terminal_orders(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $inactive = User::factory()->create(['is_active' => false]);
        $active = User::factory()->create()->assignRole('staff');
        $open = Order::factory()->create(['created_by' => $manager->id]);
        $this->useBearer($manager->createToken('order activity test')->plainTextToken);

        $this->postJson('/api/orders/'.$open->id.'/assign', [
            'assignee_id' => $inactive->id,
            'expected_version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id', 'errors.fields');
        self::assertNull($open->refresh()->assignee_id);
        self::assertSame(1, $open->version);

        foreach ([OrderStatus::Completed, OrderStatus::Cancelled] as $status) {
            $closed = Order::factory()->create(['created_by' => $manager->id, 'status' => $status]);

            $this->postJson('/api/orders/'.$closed->id.'/assign', [
                'assignee_id' => $active->id,
                'expected_version' => 1,
            ])->assertUnprocessable();

            self::assertNull($closed->refresh()->assignee_id);
            self::assertSame(1, $closed->version);
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_notes_are_appended_to_a_visible_order_without_changing_its_version(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $staff = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create([
            'created_by' => $manager->id,
            'assignee_id' => $staff->id,
            'status' => OrderStatus::Cancelled,
            'version' => 4,
        ]);
        $path = '/api/orders/'.$order->id;

        $this->useBearer($staff->createToken('order activity test')->plainTextToken);
        $created = $this->postJson($path.'/notes', ['body' => '  Buyer notified.  '])
            ->assertCreated()
            ->assertJsonPath('data.note.body', 'Buyer notified.')
            ->assertJsonPath('data.note.author.id', $staff->id)
            ->assertJsonPath('data.note.author.name', $staff->name)
            ->assertJsonPath('data.note.created_at', static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->assertJsonMissingPath('data.note.author.email');

        $note = OrderNote::query()->sole();
        $created->assertJsonPath('data.note.id', $note->id);
        self::assertSame($order->id, $note->order_id);
        self::assertSame($staff->id, $note->author_id);

        $audit = AuditLog::query()->where('action', 'order.note_added')->sole();
        self::assertSame($staff->id, $audit->actor_id);
        self::assertSame('order', $audit->target_type);
        self::assertSame($order->id, $audit->target_id);
        self::assertNull($audit->before_values);
        self::assertSame(['note_id' => $note->id], $audit->after_values);

        $this->useBearer($manager->createToken('order activity test')->plainTextToken);
        $this->postJson($path.'/notes', ['body' => 'Record closed.'])->assertCreated();
        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.order.version', 4)
            ->assertJsonCount(2, 'data.order.notes')
            ->assertJsonPath('data.order.notes.0.id', $note->id)
            ->assertJsonPath('data.order.notes.0.body', 'Buyer notified.')
            ->assertJsonPath('data.order.notes.1.author.id', $manager->id);

        self::assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        self::assertSame(4, $order->version);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_assignment_and_note_commands_require_their_own_permissions(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $viewer = User::factory()->create()->givePermissionTo('orders.view.assigned');
        $order = Order::factory()->create(['assignee_id' => $staff->id]);
        $viewerOrder = Order::factory()->create(['assignee_id' => $viewer->id]);
        $assignment = ['assignee_id' => null, 'expected_version' => 1];
        $note = ['body' => 'Permission check.'];

        $this->postJson('/api/orders/'.$order->id.'/assign', $assignment)->assertUnauthorized();
        $this->postJson('/api/orders/'.$order->id.'/notes', $note)->assertUnauthorized();

        $this->useBearer($staff->createToken('order activity test')->plainTextToken);
        $this->postJson('/api/orders/'.$order->id.'/assign', $assignment)->assertForbidden();
        $this->postJson('/api/orders/'.$viewerOrder->id.'/notes', $note)->assertNotFound();

        $this->useBearer($viewer->createToken('order activity test')->plainTextToken);
        $this->getJson('/api/orders/'.$viewerOrder->id)->assertOk();
        $this->postJson('/api/orders/'.$viewerOrder->id.'/notes', $note)->assertForbidden();

        self::assertSame($staff->id, $order->refresh()->assignee_id);
        $this->assertDatabaseCount('order_notes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_assignment_and_note_commands_only_validate_allowed_json_body_fields(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = User::factory()->create();
        $order = Order::factory()->create(['created_by' => $manager->id]);
        $path = '/api/orders/'.$order->id;
        $this->useBearer($manager->createToken('order activity test')->plainTextToken);
        Config::set('orders.max_note_length', 8);

        $this->postJson($path.'/assign?expected_version=1', ['assignee_id' => $employee->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_version', 'errors.fields');
        $this->postJson($path.'/assign?assignee_id='.$employee->id, ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id', 'errors.fields');
        $this->postJson($path.'/assign', ['assignee_id' => (string) $employee->id, 'expected_version' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee_id', 'expected_version'], 'errors.fields');

        foreach ([
            [$path.'/notes?body=ok', []],
            [$path.'/notes', ['body' => 'Valid', 'author_id' => $employee->id]],
            [$path.'/notes', ['body' => '   ']],
            [$path.'/notes', ['body' => str_repeat('x', 9)]],
        ] as [$uri, $data]) {
            $this->postJson($uri, $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('body', 'errors.fields');
        }

        self::assertNull($order->refresh()->assignee_id);
        self::assertSame(1, $order->version);
        $this->assertDatabaseCount('order_notes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_both_assignment_and_note_writes(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = User::factory()->create()->assignRole('staff');
        $order = Order::factory()->create(['created_by' => $manager->id]);
        $path = '/api/orders/'.$order->id;
        $this->useBearer($manager->createToken('order activity test')->plainTextToken);
        AuditLog::saving(static fn (): bool => false);

        $this->postJson($path.'/assign', ['assignee_id' => $employee->id, 'expected_version' => 1])
            ->assertInternalServerError()
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);
        $this->postJson($path.'/notes', ['body' => 'Rollback note.'])
            ->assertInternalServerError()
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        self::assertNull($order->refresh()->assignee_id);
        self::assertSame(1, $order->version);
        $this->assertDatabaseCount('order_notes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function useBearer(string $token): void
    {
        Auth::forgetGuards();
        $this->withToken($token);
    }
}
