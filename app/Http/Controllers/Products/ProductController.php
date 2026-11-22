<?php

declare(strict_types=1);

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\AdjustProductStockRequest;
use App\Http\Requests\Products\DeactivateProductRequest;
use App\Http\Requests\Products\ListProductsRequest;
use App\Http\Requests\Products\ShowProductRequest;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Responses\Products\ProductResponse;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Products\ProductService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly InventoryService $inventoryService,
        private readonly ProductResponse $productResponse,
    ) {}

    public function index(ListProductsRequest $request): JsonResponse
    {
        $products = $this->productService->paginate(
            $this->actor($request),
            search: $request->search(),
            isActive: $request->isActive(),
            perPage: $request->perPage(),
            page: $request->page(),
        );

        return $this->productResponse->list($products, $request);
    }

    public function show(ShowProductRequest $request, string $product): JsonResponse
    {
        $record = $this->productService->find($this->actor($request), $product);

        return $this->productResponse->show($record, $request);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $created = $this->productService->create($this->actor($request), $request->productData());

        return $this->productResponse->create($created, $request);
    }

    public function update(UpdateProductRequest $request, string $product): JsonResponse
    {
        $updated = $this->productService->update($this->actor($request), $product, $request->productData());

        return $this->productResponse->update($updated, $request);
    }

    public function deactivate(DeactivateProductRequest $request, string $product): JsonResponse
    {
        $deactivated = $this->productService->deactivate($this->actor($request), $product);

        return $this->productResponse->deactivate($deactivated, $request);
    }

    public function adjustStock(AdjustProductStockRequest $request, string $product): JsonResponse
    {
        $adjusted = $this->inventoryService->adjust(
            $this->actor($request),
            $product,
            $request->stockQuantity(),
            $request->expectedStockVersion(),
            $request->reason(),
        );

        return $this->productResponse->adjustStock($adjusted, $request);
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
