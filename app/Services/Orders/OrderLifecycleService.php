<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderNotificationType;
use App\Enums\OrderStatus;
use App\Enums\OrderWriteOperation;
use App\Exceptions\Orders\OrderVersionConflictException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Audit\AuditLogWriter;
use App\Services\Inventory\InventoryService;
use App\Services\Notifications\OrderNotificationOutbox;
use App\Services\Users\AdminUserWriteLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class OrderLifecycleService
{
    public function __construct(
        private OrderPolicy $policy,
        private AdminUserWriteLock $writeLock,
        private InventoryService $inventory,
        private AuditLogWriter $auditLogWriter,
        private OrderReader $orders,
        private OrderWriteTransaction $writeTransaction,
        private OrderNotificationOutbox $notificationOutbox,
    ) {}

    public function transition(
        User $actor,
        string $orderId,
        OrderStatus $status,
        int $expectedVersion,
        ?string $remarks,
    ): Order {
        $this->authorize($actor);

        return $this->writeTransaction->run(function () use ($actor, $orderId, $status, $expectedVersion, $remarks): Order {
            $lockedActor = $this->writeLock->acquireShared($actor);
            $this->authorize($lockedActor);
            $mayChangeAnyStatus = $this->policy->changeAnyStatus($lockedActor);

            $query = Order::query();
            if (! $mayChangeAnyStatus) {
                $query->where('assignee_id', $lockedActor->id);
            }

            $order = $query->lockForUpdate()->findOrFail($orderId);
            if ($order->version !== $expectedVersion) {
                throw new OrderVersionConflictException($order->version);
            }

            $previousStatus = $order->status;
            $this->validateTransition($previousStatus, $status, $mayChangeAnyStatus);

            if ($order->version === PHP_INT_MAX) {
                throw ValidationException::withMessages([
                    'expected_version' => Lang::string('api.orders.version_limit'),
                ]);
            }

            $before = $this->auditState($order);
            $this->applyInventoryChange($order, $lockedActor, $status);

            $order->status = $status;
            $order->version++;
            if ($status === OrderStatus::Completed) {
                $order->completed_at = now()->toImmutable();
            }

            if (! $order->save()) {
                throw new RuntimeException('The order status could not be saved.');
            }

            $history = new OrderStatusHistory([
                'actor_id' => $lockedActor->id,
                'previous_status' => $previousStatus,
                'new_status' => $status,
                'remarks' => $remarks,
            ]);
            if ($order->statusHistory()->save($history) === false) {
                throw new RuntimeException('The order status history could not be saved.');
            }

            $this->auditLogWriter->record(
                $lockedActor,
                $order,
                'order',
                'order.status_changed',
                $before,
                $this->auditState($order),
            );

            match ($status) {
                OrderStatus::Confirmed => $this->notificationOutbox->record($order, OrderNotificationType::Confirmed),
                OrderStatus::Cancelled => $this->notificationOutbox->record($order, OrderNotificationType::Cancelled),
                default => null,
            };

            return $this->orders->find($lockedActor, (string) $order->id);
        }, OrderWriteOperation::Lifecycle);
    }

    private function authorize(User $actor): void
    {
        if (! $this->policy->changeStatus($actor)) {
            throw new AuthorizationException;
        }
    }

    private function validateTransition(OrderStatus $from, OrderStatus $to, bool $mayChangeAnyStatus): void
    {
        $next = match ($from) {
            OrderStatus::Draft => OrderStatus::Pending,
            OrderStatus::Pending => OrderStatus::Confirmed,
            OrderStatus::Confirmed => OrderStatus::Processing,
            OrderStatus::Processing => OrderStatus::Ready,
            OrderStatus::Ready => OrderStatus::Completed,
            OrderStatus::Completed, OrderStatus::Cancelled => null,
        };

        if ($next === null || ($to !== $next && $to !== OrderStatus::Cancelled)) {
            throw ValidationException::withMessages([
                'status' => Lang::string('api.orders.invalid_transition', [
                    'from' => $from->value,
                    'to' => $to->value,
                ]),
            ]);
        }

        if (! $mayChangeAnyStatus && ($to !== $next || ! in_array($from, [OrderStatus::Confirmed, OrderStatus::Processing], true))) {
            throw new AuthorizationException;
        }
    }

    private function applyInventoryChange(Order $order, User $actor, OrderStatus $status): void
    {
        match ($status) {
            OrderStatus::Confirmed => $this->inventory->deductForOrder($order, $actor),
            OrderStatus::Cancelled => $this->inventory->restoreForOrder($order, $actor),
            default => null,
        };
    }

    /**
     * @return array{status: string, version: int, completed_at: ?string}
     */
    private function auditState(Order $order): array
    {
        return [
            'status' => $order->status->value,
            'version' => $order->version,
            'completed_at' => $order->completed_at?->toIso8601String(),
        ];
    }
}
