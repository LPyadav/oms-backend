<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\AssignOrderRequest;
use App\Http\Requests\Orders\ChangeOrderStatusRequest;
use App\Http\Requests\Orders\DeleteOrderRequest;
use App\Http\Requests\Orders\ListOrdersRequest;
use App\Http\Requests\Orders\PreviewOrderRequest;
use App\Http\Requests\Orders\ShowOrderRequest;
use App\Http\Requests\Orders\StoreOrderNoteRequest;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Http\Responses\Orders\OrderResponse;
use App\Models\User;
use App\Services\Orders\OrderActivityService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderLifecycleService $orderLifecycleService,
        private readonly OrderActivityService $orderActivityService,
        private readonly OrderResponse $orderResponse,
    ) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $orders = $this->orderService->paginate($this->actor($request), $request->criteria());

        return $this->orderResponse->list($orders, $request);
    }

    public function show(ShowOrderRequest $request, string $order): JsonResponse
    {
        $record = $this->orderService->find($this->actor($request), $order);

        return $this->orderResponse->show($record, $request);
    }

    public function preview(PreviewOrderRequest $request): JsonResponse
    {
        $pricing = $this->orderService->preview(
            $this->actor($request),
            $request->items(),
            $request->orderDiscountMinor(),
            $request->orderId(),
        );

        return $this->orderResponse->preview($pricing);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create(
            $this->actor($request),
            $request->orderData(),
            $request->idempotencyKey(),
        );

        return $this->orderResponse->create($order, $request);
    }

    public function update(UpdateOrderRequest $request, string $order): JsonResponse
    {
        $updated = $this->orderService->update($this->actor($request), $order, $request->orderData());

        return $this->orderResponse->update($updated, $request);
    }

    public function changeStatus(ChangeOrderStatusRequest $request, string $order): JsonResponse
    {
        $updated = $this->orderLifecycleService->transition(
            $this->actor($request),
            $order,
            $request->status(),
            $request->expectedVersion(),
            $request->remarks(),
        );

        return $this->orderResponse->statusUpdated($updated, $request);
    }

    public function assign(AssignOrderRequest $request, string $order): JsonResponse
    {
        $updated = $this->orderActivityService->assign(
            $this->actor($request),
            $order,
            $request->assigneeId(),
            $request->expectedVersion(),
        );

        return $this->orderResponse->assigned($updated, $request);
    }

    public function addNote(StoreOrderNoteRequest $request, string $order): JsonResponse
    {
        $note = $this->orderActivityService->addNote($this->actor($request), $order, $request->body());

        return $this->orderResponse->noteAdded($note, $request);
    }

    public function destroy(DeleteOrderRequest $request, string $order): JsonResponse
    {
        $this->orderService->delete($this->actor($request), $order, $request->expectedVersion());

        return $this->orderResponse->delete();
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
