<?php

declare(strict_types=1);

namespace App\Http\Responses\Products;

use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProductResponse
{
    public function __construct(
        private ApiResponse $apiResponse,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Product>  $products
     */
    public function list(LengthAwarePaginator $products, Request $request): JsonResponse
    {
        return $this->apiResponse->success(
            message: Lang::string('api.products.list_retrieved'),
            data: [
                'products' => ProductResource::collection($products->getCollection())->resolve($request),
            ],
            meta: [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        );
    }

    public function show(Product $product, Request $request): JsonResponse
    {
        return $this->product($product, $request, Lang::string('api.products.retrieved'));
    }

    public function create(Product $product, Request $request): JsonResponse
    {
        return $this->product($product, $request, Lang::string('api.products.created'), Response::HTTP_CREATED);
    }

    public function update(Product $product, Request $request): JsonResponse
    {
        return $this->product($product, $request, Lang::string('api.products.updated'));
    }

    public function deactivate(Product $product, Request $request): JsonResponse
    {
        return $this->product($product, $request, Lang::string('api.products.deactivated'));
    }

    public function adjustStock(Product $product, Request $request): JsonResponse
    {
        return $this->product($product, $request, Lang::string('api.products.stock_adjusted'));
    }

    private function product(
        Product $product,
        Request $request,
        string $message,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return $this->apiResponse->success(
            message: $message,
            data: [
                'product' => (new ProductResource($product))->resolve($request),
            ],
            status: $status,
        );
    }
}
