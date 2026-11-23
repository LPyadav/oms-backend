<?php

declare(strict_types=1);

namespace App\Http\Responses\Notifications;

use App\Http\Resources\OrderNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\OrderNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;

final readonly class OrderNotificationResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, OrderNotification>  $notifications
     */
    public function list(LengthAwarePaginator $notifications, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.notifications.list_retrieved'),
            data: [
                'notifications' => OrderNotificationResource::collection($notifications->getCollection())->resolve($request),
            ],
            meta: [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        );
    }

    public function unreadCount(int $count): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.notifications.unread_count_retrieved'),
            data: ['unread_count' => $count],
        );
    }

    public function markedRead(OrderNotification $notification, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.notifications.marked_read'),
            data: ['notification' => (new OrderNotificationResource($notification))->resolve($request)],
        );
    }

    public function markedAllRead(int $markedCount): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.notifications.marked_all_read'),
            data: ['marked_count' => $markedCount],
        );
    }
}
