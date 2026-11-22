<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\DeactivateCustomerRequest;
use App\Http\Requests\Customers\ListCustomersRequest;
use App\Http\Requests\Customers\ShowCustomerRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Responses\Customers\CustomerResponse;
use App\Models\User;
use App\Services\Customers\CustomerService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly CustomerResponse $customerResponse,
    ) {}

    public function index(ListCustomersRequest $request): JsonResponse
    {
        $customers = $this->customerService->paginate(
            $this->actor($request),
            search: $request->search(),
            isActive: $request->isActive(),
            perPage: $request->perPage(),
            page: $request->page(),
        );

        return $this->customerResponse->list($customers, $request);
    }

    public function show(ShowCustomerRequest $request, string $customer): JsonResponse
    {
        $record = $this->customerService->find($this->actor($request), $customer);

        return $this->customerResponse->show($record, $request);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $created = $this->customerService->create($this->actor($request), $request->customerData());

        return $this->customerResponse->create($created, $request);
    }

    public function update(UpdateCustomerRequest $request, string $customer): JsonResponse
    {
        $updated = $this->customerService->update($this->actor($request), $customer, $request->customerData());

        return $this->customerResponse->update($updated, $request);
    }

    public function deactivate(DeactivateCustomerRequest $request, string $customer): JsonResponse
    {
        $deactivated = $this->customerService->deactivate($this->actor($request), $customer);

        return $this->customerResponse->deactivate($deactivated, $request);
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
