<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\Audit\AuditLogWriter;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Services\Users\AdminUserWriteLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProductService
{
    private const array METADATA_FIELDS = ['sku', 'name', 'price', 'tax_rate'];

    public function __construct(
        private ProductPolicy $productPolicy,
        private AdminUserWriteLock $writeLock,
        private AuditLogWriter $auditLogWriter,
        private CatalogWriteTransaction $writeTransaction,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(
        User $actor,
        ?string $search,
        ?bool $isActive,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        if (! $this->productPolicy->viewAny($actor)) {
            throw new AuthorizationException;
        }

        $query = Product::query();
        if ($search !== null) {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(static function (Builder $query) use ($pattern): void {
                $query->whereRaw("name like ? escape '!'", [$pattern])
                    ->orWhereRaw("sku like ? escape '!'", [$pattern]);
            });
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->orderBy('id')->paginate(perPage: $perPage, page: $page);
    }

    public function find(User $actor, int|string $productId): Product
    {
        if (! $this->productPolicy->view($actor)) {
            throw new AuthorizationException;
        }

        return Product::query()->findOrFail($productId);
    }

    /**
     * @param  array{sku: string, name: string, price: string, tax_rate?: string}  $attributes
     */
    public function create(User $actor, array $attributes): Product
    {
        return $this->writeTransaction->run(function () use ($actor, $attributes): Product {
            $lockedActor = $this->writeLock->acquireShared($actor);
            if (! $this->productPolicy->create($lockedActor)) {
                throw new AuthorizationException;
            }

            $product = new Product($this->metadata($attributes));
            $this->save($product);
            $product->refresh();

            $this->auditLogWriter->record(
                $lockedActor,
                $product,
                'product',
                'product.created',
                null,
                $product->only([...self::METADATA_FIELDS, 'stock_quantity', 'stock_version', 'is_active']),
            );

            return $product;
        });
    }

    /**
     * @param  array{sku?: string, name?: string, price?: string, tax_rate?: string}  $attributes
     */
    public function update(User $actor, int|string $productId, array $attributes): Product
    {
        return $this->writeTransaction->run(function () use ($actor, $productId, $attributes): Product {
            $lockedActor = $this->writeLock->acquireShared($actor);
            if (! $this->productPolicy->update($lockedActor)) {
                throw new AuthorizationException;
            }

            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $product->fill($this->metadata($attributes));
            $changedFields = array_keys($product->getDirty());

            if ($changedFields === []) {
                return $product;
            }

            $before = Arr::only($product->getOriginal(), $changedFields);
            $this->save($product);

            $this->auditLogWriter->record(
                $lockedActor,
                $product,
                'product',
                'product.updated',
                $before,
                $product->only($changedFields),
            );

            return $product;
        });
    }

    public function deactivate(User $actor, int|string $productId): Product
    {
        return $this->writeTransaction->run(function () use ($actor, $productId): Product {
            $lockedActor = $this->writeLock->acquireShared($actor);
            if (! $this->productPolicy->deactivate($lockedActor)) {
                throw new AuthorizationException;
            }

            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            if (! $product->is_active) {
                return $product;
            }

            $product->is_active = false;
            $this->save($product);

            $this->auditLogWriter->record(
                $lockedActor,
                $product,
                'product',
                'product.deactivated',
                ['is_active' => true],
                ['is_active' => false],
            );

            return $product;
        });
    }

    /**
     * @param  array{sku?: string, name?: string, price?: string, tax_rate?: string}  $attributes
     * @return array<string, string>
     */
    private function metadata(array $attributes): array
    {
        $metadata = Arr::only($attributes, self::METADATA_FIELDS);
        if (isset($metadata['sku'])) {
            $metadata['sku'] = strtoupper(trim($metadata['sku']));
        }

        return $metadata;
    }

    private function save(Product $product): void
    {
        try {
            if (! $product->save()) {
                throw new RuntimeException('The product could not be saved.');
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'sku' => Lang::string('validation.unique', ['attribute' => 'sku']),
            ])->status(Response::HTTP_CONFLICT);
        }
    }
}
