<?php

declare(strict_types=1);

namespace App\Http\Responses\Orders;

use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Orders\OrderPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

/** @phpstan-import-type PricingPreview from OrderPricingService */
final readonly class OrderResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Order>  $orders
     */
    public function list(LengthAwarePaginator $orders, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.orders.list_retrieved'),
            data: [
                'orders' => OrderResource::collection($orders->getCollection())->resolve($request),
            ],
            meta: [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        );
    }

    public function show(Order $order, Request $request): JsonResponse
    {
        return $this->order($order, $request, Lang::string('api.orders.retrieved'));
    }

    /**
     * @param  PricingPreview  $pricing
     */
    public function preview(array $pricing): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.orders.pricing_previewed'),
            data: ['pricing' => $pricing],
        );
    }

    public function create(Order $order, Request $request): JsonResponse
    {
        return $this->order(
            $order,
            $request,
            Lang::string('api.orders.created'),
            Response::HTTP_CREATED,
            ['Location' => route('orders.show', ['order' => $order->id], absolute: false)],
        );
    }

    public function update(Order $order, Request $request): JsonResponse
    {
        return $this->order($order, $request, Lang::string('api.orders.updated'));
    }

    public function statusUpdated(Order $order, Request $request): JsonResponse
    {
        return $this->order($order, $request, Lang::string('api.orders.status_updated'));
    }

    public function delete(): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.orders.deleted'),
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function order(
        Order $order,
        Request $request,
        string $message,
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        return $this->apiResponse->success(
            message: $message,
            data: ['order' => (new OrderResource($order))->resolve($request)],
            status: $status,
            headers: $headers,
        );
    }
}
