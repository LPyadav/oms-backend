<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListOrderNotificationsRequest;
use App\Http\Responses\Notifications\OrderNotificationResponse;
use App\Models\User;
use App\Services\Notifications\OrderNotificationService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderNotificationController extends Controller
{
    public function __construct(
        private readonly OrderNotificationService $notifications,
        private readonly OrderNotificationResponse $response,
    ) {}

    public function index(ListOrderNotificationsRequest $request): JsonResponse
    {
        $notifications = $this->notifications->paginate(
            $this->actor($request),
            $request->status(),
            $request->page(),
            $request->perPage(),
        );

        return $this->response->list($notifications, $request);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->response->unreadCount($this->notifications->unreadCount($this->actor($request)));
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $this->notifications->markRead($this->actor($request), $notification);

        return $this->response->markedRead($record, $request);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return $this->response->markedAllRead($this->notifications->markAllRead($this->actor($request)));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }
}
