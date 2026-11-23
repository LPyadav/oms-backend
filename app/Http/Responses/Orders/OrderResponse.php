<?php

declare(strict_types=1);

namespace App\Http\Responses\Orders;

use App\Http\Resources\OrderAssigneeResource;
use App\Http\Resources\OrderNoteResource;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\OrderCustomerContactScope;
use App\Services\Orders\OrderPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

/** @phpstan-import-type PricingPreview from OrderPricingService */
final readonly class OrderResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
        private CustomerPolicy $customerPolicy,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Order>  $orders
     */
    public function list(LengthAwarePaginator $orders, Request $request): JsonResponse
    {
        $contactScope = $this->customerContactScope($request);
        $data = $orders->getCollection()->map(
            static fn (Order $order): array => (new OrderResource($order, $contactScope))->resolve($request),
        )->all();

        return $this->apiResponse->success(
            message: Lang::string('api.orders.list_retrieved'),
            data: ['orders' => $data],
            meta: $this->paginationMeta($orders),
        );
    }

    /**
     * @param  LengthAwarePaginator<int, User>  $assignees
     */
    public function assignees(LengthAwarePaginator $assignees, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.orders.assignees_retrieved'),
            data: [
                'assignees' => OrderAssigneeResource::collection($assignees->getCollection())->resolve($request),
            ],
            meta: $this->paginationMeta($assignees),
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
            data: ['pricing' => [
                ...$pricing,
                'items' => array_map(
                    static fn (array $line): array => Arr::except($line, 'base_tax_rate_basis_points'),
                    $pricing['items'],
                ),
            ]],
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

    public function assigned(Order $order, Request $request): JsonResponse
    {
        return $this->order($order, $request, Lang::string('api.orders.assigned'));
    }

    public function noteAdded(OrderNote $note, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.orders.note_added'),
            data: ['note' => (new OrderNoteResource($note))->resolve($request)],
            status: Response::HTTP_CREATED,
        );
    }

    public function delete(): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.orders.deleted'),
        );
    }

    private function customerContactScope(Request $request): OrderCustomerContactScope
    {
        $actor = $request->user();

        return $actor instanceof User
            ? $this->customerPolicy->orderContactScope($actor)
            : new OrderCustomerContactScope;
    }

    /**
     * @template TRecord of Order|User
     *
     * @param  LengthAwarePaginator<int, TRecord>  $records
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function paginationMeta(LengthAwarePaginator $records): array
    {
        return [
            'current_page' => $records->currentPage(),
            'per_page' => $records->perPage(),
            'total' => $records->total(),
            'last_page' => $records->lastPage(),
        ];
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
        $data = (new OrderResource($order, $this->customerContactScope($request)))->resolve($request);
        $actor = $request->user();

        if ($order->assignee_id !== null && $actor instanceof User && $actor->can('assign', Order::class)) {
            $order->load(['assignee:id,name,email', 'assignee.roles:id,name,guard_name']);

            if ($order->assignee instanceof User) {
                $data['assignee'] = (new OrderAssigneeResource($order->assignee))->resolve($request);
            }
        }

        return $this->apiResponse->success(
            message: $message,
            data: ['order' => $data],
            status: $status,
            headers: $headers,
        );
    }
}
