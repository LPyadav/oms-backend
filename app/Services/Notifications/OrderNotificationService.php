<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\OrderNotification;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Users\AdminUserWriteLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class OrderNotificationService
{
    public function __construct(
        private OrderPolicy $orderPolicy,
        private AdminUserWriteLock $writeLock,
    ) {}

    /**
     * @param  'read'|'unread'|null  $status
     * @return LengthAwarePaginator<int, OrderNotification>
     */
    public function paginate(User $actor, ?string $status, int $page, int $perPage): LengthAwarePaginator
    {
        return DB::transaction(function () use ($actor, $status, $page, $perPage): LengthAwarePaginator {
            $query = $this->visibleTo($actor);

            if ($status === 'unread') {
                $query->whereNull('read_at');
            } elseif ($status === 'read') {
                $query->whereNotNull('read_at');
            }

            return $query->with('event.order:id,order_number')
                ->orderByDesc('id')
                ->paginate(perPage: $perPage, page: $page);
        });
    }

    public function unreadCount(User $actor): int
    {
        return DB::transaction(fn (): int => $this->visibleTo($actor)->whereNull('read_at')->count());
    }

    public function markRead(User $actor, string $notificationId): OrderNotification
    {
        return DB::transaction(function () use ($actor, $notificationId): OrderNotification {
            $lockedActor = $this->writeLock->acquireShared($actor);
            $notification = $this->visibleTo($lockedActor)
                ->with('event.order:id,order_number')
                ->lockForUpdate()
                ->findOrFail($notificationId);

            if ($notification->read_at === null) {
                $notification->read_at = now()->toImmutable();
                if (! $notification->save()) {
                    throw new RuntimeException('The notification read time could not be saved.');
                }
            }

            return $notification;
        });
    }

    public function markAllRead(User $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            $lockedActor = $this->writeLock->acquireShared($actor);

            return $this->visibleTo($lockedActor)->whereNull('read_at')->update(['read_at' => now()]);
        });
    }

    /**
     * @return Builder<OrderNotification>
     */
    private function visibleTo(User $actor): Builder
    {
        $query = OrderNotification::query()->where('recipient_id', $actor->id);
        if (! $this->orderPolicy->viewAny($actor->withoutRelations())) {
            return $query->whereKey([]);
        }

        return $query->whereHas('recipient', static function (Builder $recipients): void {
            $recipients->where('is_active', true);
        })->whereHas('event.order', static function (Builder $orders) use ($actor): void {
            $orders->where('assignee_id', $actor->id);
        });
    }
}
