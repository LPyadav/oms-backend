<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class OrderSystemActivityTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/orders';

    private const string ACTIVITY = 'data.order.system_activity';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
            'orders.currency' => 'USD',
        ]);
    }

    public function test_order_creation_edits_and_idempotent_replay_return_the_current_system_activity(): void
    {
        $this->freezeSecond();
        $manager = User::factory()->create()->assignRole('manager');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $payload = [
            'customer_id' => $customer->id,
            'order_date' => '2026-11-22',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $headers = ['Idempotency-Key' => 'order-system-activity-test'];

        $this->useBearerFor($manager);
        $createdResponse = $this->postJson(self::ENDPOINT, $payload, $headers)->assertCreated();
        $order = Order::query()->sole();
        $path = self::ENDPOINT.'/'.$order->id;
        $created = AuditLog::query()->where('action', 'order.created')->sole();

        $createdResponse->assertJsonCount(1, self::ACTIVITY)
            ->assertJsonCount(5, self::ACTIVITY.'.0')
            ->assertJsonPath(self::ACTIVITY.'.0.id', $created->id)
            ->assertJsonPath(self::ACTIVITY.'.0.action', 'order.created')
            ->assertJsonPath(self::ACTIVITY.'.0.actor_id', $manager->id)
            ->assertJsonPath(self::ACTIVITY.'.0.actor_name', $manager->name)
            ->assertJsonPath(self::ACTIVITY.'.0.created_at', $created->created_at->toISOString());

        $editedResponse = $this->patchJson($path, ['expected_version' => 1, 'priority' => 'high'])
            ->assertOk()
            ->assertJsonPath('data.order.version', 2);
        $edited = AuditLog::query()->where('action', 'order.updated')->sole();
        $activityIds = [$edited->id, $created->id];

        $editedResponse->assertJsonPath(self::ACTIVITY.'.*.id', $activityIds)
            ->assertJsonPath(self::ACTIVITY.'.0.action', 'order.updated');

        $this->patchJson($path, ['expected_version' => 2, 'priority' => 'high'])
            ->assertOk()
            ->assertJsonPath('data.order.version', 2)
            ->assertJsonPath(self::ACTIVITY.'.*.id', $activityIds);

        $this->postJson(self::ENDPOINT, $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath(self::ACTIVITY.'.*.id', $activityIds);

        $this->getJson($path)->assertOk()
            ->assertJsonPath(self::ACTIVITY.'.*.id', $activityIds);
        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonMissingPath('data.orders.0.system_activity');

        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_system_activity_filters_audits_and_caps_newest_safe_events(): void
    {
        Config::set('orders.system_activity_limit', 3);
        $manager = User::factory()->create()->assignRole('manager');
        $order = Order::factory()->create(['created_by' => $manager->id]);
        $otherOrder = Order::factory()->create(['created_by' => $manager->id]);
        $missingActorId = $manager->id + 100_000;
        $created = $this->recordAudit('order.created', $order->id, $manager->id, '2026-11-22 10:01:00');
        $firstEdit = $this->recordAudit('order.updated', $order->id, $manager->id, '2026-11-22 10:02:00');
        $secondEdit = $this->recordAudit('order.updated', $order->id, $missingActorId, '2026-11-22 10:02:00');
        $this->recordAudit('order.updated', $order->id, $manager->id, '2026-11-22 10:00:00');
        $this->recordAudit('order.updated', $otherOrder->id, $manager->id, '2026-11-22 10:03:00');
        $this->recordAudit('order.updated', $order->id, $manager->id, '2026-11-22 10:03:00', 'product');

        foreach (['order.assigned', 'order.status_changed', 'order.note_added', 'order.deleted', 'order.internal'] as $action) {
            $this->recordAudit($action, $order->id, $manager->id, '2026-11-22 10:03:00');
        }

        $this->useBearerFor($manager);
        $this->getJson(self::ENDPOINT.'/'.$order->id)
            ->assertOk()
            ->assertJsonPath(self::ACTIVITY.'.*.id', [$secondEdit->id, $firstEdit->id, $created->id])
            ->assertJsonCount(5, self::ACTIVITY.'.0')
            ->assertJsonPath(self::ACTIVITY.'.0.action', 'order.updated')
            ->assertJsonPath(self::ACTIVITY.'.0.actor_id', $missingActorId)
            ->assertJsonPath(self::ACTIVITY.'.0.actor_name', null)
            ->assertJsonPath(self::ACTIVITY.'.0.created_at', $secondEdit->created_at->toISOString())
            ->assertJsonMissingPath(self::ACTIVITY.'.0.before_values')
            ->assertJsonMissingPath(self::ACTIVITY.'.0.after_values')
            ->assertJsonMissingPath(self::ACTIVITY.'.0.target_type')
            ->assertJsonMissingPath(self::ACTIVITY.'.0.target_id');
    }

    public function test_staff_receive_system_activity_only_for_their_assigned_orders(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $otherStaff = User::factory()->create()->assignRole('staff');
        $assigned = Order::factory()->create(['assignee_id' => $staff->id]);
        $other = Order::factory()->create(['assignee_id' => $otherStaff->id]);
        $withoutAudits = Order::factory()->create(['assignee_id' => $staff->id]);

        $created = $this->recordAudit('order.created', $assigned->id, $assigned->created_by, '2026-11-22 10:00:00');
        $this->recordAudit('order.created', $other->id, $other->created_by, '2026-11-22 10:00:00');

        $this->getJson(self::ENDPOINT.'/'.$assigned->id)->assertUnauthorized();

        $this->useBearerFor($staff);
        $this->getJson(self::ENDPOINT.'/'.$assigned->id)
            ->assertOk()
            ->assertJsonPath(self::ACTIVITY.'.*.id', [$created->id]);
        $this->getJson(self::ENDPOINT.'/'.$other->id)
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND)
            ->assertJsonMissingPath(self::ACTIVITY);
        $this->getJson(self::ENDPOINT.'/'.$withoutAudits->id)
            ->assertOk()
            ->assertJsonPath(self::ACTIVITY, []);
    }

    private function useBearerFor(User $actor): void
    {
        Auth::forgetGuards();
        $this->withToken($actor->createToken('order-system-activity-test')->plainTextToken);
    }

    private function recordAudit(
        string $action,
        int $targetId,
        int $actorId,
        string $createdAt,
        string $targetType = 'order',
    ): AuditLog {
        $audit = new AuditLog([
            'actor_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'before_values' => ['snapshot' => 'private before data'],
            'after_values' => ['snapshot' => 'private after data'],
        ]);
        $audit->forceFill(['created_at' => $createdAt])->saveOrFail();

        return $audit;
    }
}
