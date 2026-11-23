<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderNotificationType;
use App\Enums\OrderStatus;
use App\Exceptions\Orders\OrderActivityContentionException;
use App\Exceptions\Orders\OrderVersionConflictException;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Audit\AuditLogWriter;
use App\Services\Notifications\OrderNotificationOutbox;
use App\Services\Users\AdminUserWriteLock;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;
use RuntimeException;

final readonly class OrderActivityService
{
    public function __construct(
        private OrderPolicy $policy,
        private AdminUserWriteLock $writeLock,
        private AuditLogWriter $auditLogWriter,
        private OrderService $orders,
        private ConcurrencyErrorDetector $concurrencyErrors,
        private OrderNotificationOutbox $notificationOutbox,
    ) {}

    public function assign(
        User $actor,
        string $orderId,
        ?int $assigneeId,
        int $expectedVersion,
    ): Order {
        if (! $this->policy->assign($actor)) {
            throw new AuthorizationException;
        }

        return $this->write(function () use ($actor, $orderId, $assigneeId, $expectedVersion): Order {
            $lockedActor = $this->writeLock->acquireShared($actor);
            if (! $this->policy->assign($lockedActor)) {
                throw new AuthorizationException;
            }

            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->version !== $expectedVersion) {
                throw new OrderVersionConflictException($order->version);
            }

            if (in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'assignee_id' => Lang::string('api.orders.assignment_not_allowed'),
                ]);
            }

            if ($order->assignee_id === $assigneeId) {
                return $this->orders->find($lockedActor, (string) $order->id);
            }

            if ($order->version === PHP_INT_MAX) {
                throw ValidationException::withMessages([
                    'expected_version' => Lang::string('api.orders.version_limit'),
                ]);
            }

            $assignee = $assigneeId === null ? null : $this->activeAssignee($assigneeId);
            $beforeValues = [
                'assignee_id' => $order->assignee_id,
                'version' => $order->version,
            ];

            $order->assignee()->associate($assignee);
            $order->version++;
            if (! $order->save()) {
                throw new RuntimeException('The order assignment could not be saved.');
            }

            $this->auditLogWriter->record(
                $lockedActor,
                $order,
                'order',
                'order.assigned',
                $beforeValues,
                [
                    'assignee_id' => $order->assignee_id,
                    'version' => $order->version,
                ],
            );

            $this->notificationOutbox->record($order, OrderNotificationType::Assigned);

            return $this->orders->find($lockedActor, (string) $order->id);
        });
    }

    public function addNote(User $actor, string $orderId, string $body): OrderNote
    {
        if (! $this->policy->addNote($actor)) {
            throw new AuthorizationException;
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > Config::integer('orders.max_note_length')) {
            throw ValidationException::withMessages([
                'body' => Lang::string('api.orders.note_invalid'),
            ]);
        }

        return $this->write(function () use ($actor, $orderId, $body): OrderNote {
            $lockedActor = $this->writeLock->acquireShared($actor);
            if (! $this->policy->addNote($lockedActor)) {
                throw new AuthorizationException;
            }

            $query = Order::query();
            if (! $this->policy->addAnyNote($lockedActor)) {
                $query->where('assignee_id', $lockedActor->id);
            }

            $order = $query->lockForUpdate()->findOrFail($orderId);
            $note = new OrderNote([
                'author_id' => $lockedActor->id,
                'body' => $body,
            ]);
            if ($order->notes()->save($note) === false) {
                throw new RuntimeException('The order note could not be saved.');
            }

            $this->auditLogWriter->record(
                $lockedActor,
                $order,
                'order',
                'order.note_added',
                null,
                ['note_id' => $note->id],
            );

            return $note->load('author:id,name');
        });
    }

    private function activeAssignee(int $assigneeId): User
    {
        $assignee = User::query()->where('is_active', true)->sharedLock()->find($assigneeId);
        if ($assignee === null) {
            throw ValidationException::withMessages([
                'assignee_id' => Lang::string('api.orders.assignee_unavailable'),
            ]);
        }

        return $assignee;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function write(Closure $operation): mixed
    {
        $attempts = Config::integer('orders.write_attempts');
        if ($attempts < 1) {
            throw new LogicException('Order activity writes require at least one transaction attempt.');
        }

        try {
            return DB::transaction($operation, attempts: $attempts);
        } catch (PDOException $exception) {
            if (! $this->concurrencyErrors->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            $requestId = Str::uuid()->toString();
            Log::warning('Order activity could not acquire its database locks.', ['request_id' => $requestId]);

            throw new OrderActivityContentionException($requestId);
        }
    }
}
