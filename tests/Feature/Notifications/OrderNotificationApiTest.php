<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Constants\ApiErrorCode;
use App\Enums\OrderNotificationType;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\OrderNotificationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OrderNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/notifications';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_list_and_badge_only_expose_visible_notifications_for_the_recipient(): void
    {
        $this->freezeSecond();
        $manager = User::factory()->create()->assignRole('manager');
        $staff = User::factory()->create()->assignRole('staff');
        $visibleOrder = Order::factory()->create(['assignee_id' => $manager->id]);
        $transferredOrder = Order::factory()->create(['assignee_id' => $manager->id]);
        $deletedOrder = Order::factory()->create(['assignee_id' => $manager->id]);
        $visible = $this->notification($manager, $visibleOrder);
        $this->notification($staff);
        $this->notification($manager, $transferredOrder);
        $this->notification($manager, $deletedOrder);
        $transferredOrder->forceFill(['assignee_id' => $staff->id])->saveOrFail();
        $deletedOrder->delete();

        $this->authenticate($manager);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.notifications', [[
                'id' => $visible->id,
                'event_type' => OrderNotificationType::Assigned->value,
                'order_id' => $visibleOrder->id,
                'order_number' => $visibleOrder->order_number,
                'read_at' => null,
                'created_at' => now()->toISOString(),
            ]]);
        $this->getJson(self::ENDPOINT.'/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_read_filters_and_pagination_return_stable_newest_first_results(): void
    {
        $this->freezeSecond();
        $staff = User::factory()->create()->assignRole('staff');
        $oldestUnread = $this->notification($staff);
        $read = $this->notification($staff, readAt: now()->toImmutable());
        $newestUnread = $this->notification($staff);
        $this->authenticate($staff);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.notifications.*.id', [$newestUnread->id, $read->id, $oldestUnread->id]);
        $this->getJson(self::ENDPOINT.'?status=read')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.notifications.*.id', [$read->id]);
        $this->getJson(self::ENDPOINT.'?status=unread&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta', [
                'current_page' => 2,
                'per_page' => 1,
                'total' => 2,
                'last_page' => 2,
            ])
            ->assertJsonPath('data.notifications.*.id', [$oldestUnread->id]);
        $this->getJson(self::ENDPOINT.'/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_marking_one_notification_is_idempotent_and_rejects_another_recipients_id(): void
    {
        $this->freezeSecond();
        $staff = User::factory()->create()->assignRole('staff');
        $other = User::factory()->create()->assignRole('staff');
        $notification = $this->notification($staff);
        $otherNotification = $this->notification($other);
        $this->authenticate($staff);

        $this->patchJson($this->markReadPath($otherNotification))
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);
        self::assertNull($otherNotification->refresh()->read_at);

        $firstReadAt = now()->toISOString();
        $this->patchJson($this->markReadPath($notification))
            ->assertOk()
            ->assertJsonPath('data.notification.id', $notification->id)
            ->assertJsonPath('data.notification.read_at', $firstReadAt);

        $this->travel(1)->minute();
        $this->patchJson($this->markReadPath($notification))
            ->assertOk()
            ->assertJsonPath('data.notification.read_at', $firstReadAt);
        self::assertSame($firstReadAt, $notification->refresh()->read_at?->toISOString());
        $this->getJson(self::ENDPOINT.'/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_marking_all_updates_only_visible_unread_rows_and_preserves_existing_read_times(): void
    {
        $this->freezeSecond();
        $staff = User::factory()->create()->assignRole('staff');
        $other = User::factory()->create()->assignRole('staff');
        $transferredOrder = Order::factory()->create(['assignee_id' => $staff->id]);
        $visible = [$this->notification($staff), $this->notification($staff)];
        $previousReadAt = now()->toImmutable()->subHour();
        $read = $this->notification($staff, readAt: $previousReadAt);
        $transferred = $this->notification($staff, $transferredOrder);
        $otherNotification = $this->notification($other);
        $transferredOrder->forceFill(['assignee_id' => $other->id])->saveOrFail();
        $this->authenticate($staff);

        $this->patchJson(self::ENDPOINT.'/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_count', 2);

        foreach ($visible as $notification) {
            self::assertSame(now()->toISOString(), $notification->refresh()->read_at?->toISOString());
        }
        self::assertSame($previousReadAt->toISOString(), $read->refresh()->read_at?->toISOString());
        self::assertNull($transferred->refresh()->read_at);
        self::assertNull($otherNotification->refresh()->read_at);

        $this->patchJson(self::ENDPOINT.'/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_count', 0);
        $this->getJson(self::ENDPOINT.'/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_revoking_the_order_view_grant_hides_already_delivered_notifications(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $notification = $this->notification($staff);
        $this->authenticate($staff);

        $this->getJson(self::ENDPOINT.'/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
        Role::findByName('staff', 'web')->revokePermissionTo('orders.view.assigned');
        Auth::forgetGuards();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.notifications', [])
            ->assertJsonPath('meta.total', 0);
        $this->getJson(self::ENDPOINT.'/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
        $this->patchJson($this->markReadPath($notification))
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);
        $this->patchJson(self::ENDPOINT.'/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_count', 0);
        self::assertNull($notification->refresh()->read_at);
    }

    public function test_guests_and_disabled_bearer_tokens_cannot_read_or_mark_notifications(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $notification = $this->notification($staff);

        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
        $this->getJson(self::ENDPOINT.'/unread-count')->assertUnauthorized();
        $this->patchJson($this->markReadPath($notification))->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/read-all')->assertUnauthorized();

        $this->authenticate($staff);
        $staff->forceFill(['is_active' => false])->saveOrFail();

        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson(self::ENDPOINT.'/unread-count')->assertUnauthorized();
        $this->patchJson($this->markReadPath($notification))->assertUnauthorized();
        $this->patchJson(self::ENDPOINT.'/read-all')->assertUnauthorized();
        self::assertNull($notification->refresh()->read_at);
    }

    public function test_invalid_inbox_filters_and_pagination_return_query_field_errors(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('staff'));

        $this->getJson(self::ENDPOINT.'?status=pending&page=0&per_page=1001')
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(['status', 'page', 'per_page'], 'errors.fields');
    }

    private function authenticate(User $user): void
    {
        Auth::forgetGuards();
        $this->withToken($user->createToken('order-notifications-test')->plainTextToken);
    }

    private function markReadPath(OrderNotification $notification): string
    {
        return self::ENDPOINT.'/'.$notification->id.'/read';
    }

    private function notification(
        User $recipient,
        ?Order $order = null,
        ?CarbonImmutable $readAt = null,
    ): OrderNotification {
        $order ??= Order::factory()->create(['assignee_id' => $recipient->id]);
        $event = OrderNotificationEvent::query()->create([
            'order_id' => $order->id,
            'recipient_id' => $recipient->id,
            'order_version' => $order->version,
            'event_type' => OrderNotificationType::Assigned,
            'available_at' => now(),
        ]);
        $event->forceFill(['processed_at' => now()])->saveOrFail();

        $notification = new OrderNotification(['event_id' => $event->id, 'recipient_id' => $recipient->id]);
        $notification->read_at = $readAt;
        $notification->saveOrFail();

        return $notification;
    }
}
