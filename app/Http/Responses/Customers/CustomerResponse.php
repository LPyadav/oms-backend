<?php

declare(strict_types=1);

namespace App\Http\Responses\Customers;

use App\Http\Resources\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

final readonly class CustomerResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Customer>  $customers
     */
    public function list(LengthAwarePaginator $customers, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.customers.list_retrieved'),
            data: [
                'customers' => CustomerResource::collection($customers->getCollection())->resolve($request),
            ],
            meta: [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        );
    }

    public function show(Customer $customer, Request $request): JsonResponse
    {
        return $this->customer($customer, $request, Lang::string('api.customers.retrieved'));
    }

    public function create(Customer $customer, Request $request): JsonResponse
    {
        return $this->customer($customer, $request, Lang::string('api.customers.created'), Response::HTTP_CREATED);
    }

    public function update(Customer $customer, Request $request): JsonResponse
    {
        return $this->customer($customer, $request, Lang::string('api.customers.updated'));
    }

    public function deactivate(Customer $customer, Request $request): JsonResponse
    {
        return $this->customer($customer, $request, Lang::string('api.customers.deactivated'));
    }

    private function customer(
        Customer $customer,
        Request $request,
        string $message,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return $this->apiResponse->success(
            message: $message,
            data: [
                'customer' => (new CustomerResource($customer))->resolve($request),
            ],
            status: $status,
        );
    }
}
