<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\Inventory\StockVersionConflictException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Services\Users\AdminUserWriteLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;

final readonly class InventoryService
{
    public function __construct(
        private ProductPolicy $productPolicy,
        private AdminUserWriteLock $writeLock,
        private CatalogWriteTransaction $writeTransaction,
    ) {}

    public function adjust(
        User $actor,
        int|string $productId,
        int $stockQuantity,
        int $expectedStockVersion,
        string $reason,
    ): Product {
        $this->assertValidMovement($stockQuantity, $reason);

        return $this->writeTransaction->run(function () use ($actor, $productId, $stockQuantity, $expectedStockVersion, $reason): Product {
            $lockedActor = $this->writeLock->acquireShared($actor);

            if (! $this->productPolicy->adjustStock($lockedActor)) {
                throw new AuthorizationException;
            }

            $product = Product::query()->lockForUpdate()->findOrFail($productId);

            if ($product->stock_version !== $expectedStockVersion) {
                throw new StockVersionConflictException($product->stock_version);
            }

            if ($product->stock_quantity === $stockQuantity) {
                return $product;
            }

            return $this->recordMovement(
                $product,
                $stockQuantity,
                $reason,
                StockMovement::TYPE_ADJUSTMENT,
                $lockedActor,
            );
        });
    }

    public function initialize(Product $product, int $stockQuantity, string $reason): Product
    {
        $this->assertValidMovement($stockQuantity, $reason);

        if (! $product->exists || $stockQuantity === 0) {
            throw new InvalidArgumentException('Opening stock requires a saved product and a positive quantity.');
        }

        return DB::transaction(function () use ($product, $stockQuantity, $reason): Product {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($lockedProduct->stock_quantity !== 0 || $lockedProduct->stock_version !== 0) {
                throw new LogicException('Opening stock can only be recorded for an uninitialized product.');
            }

            return $this->recordMovement(
                $lockedProduct,
                $stockQuantity,
                $reason,
                StockMovement::TYPE_OPENING_BALANCE,
                null,
            );
        });
    }

    private function recordMovement(
        Product $product,
        int $stockQuantity,
        string $reason,
        string $type,
        ?User $actor,
    ): Product {
        $previousQuantity = $product->stock_quantity;

        if ($product->stock_version === PHP_INT_MAX) {
            throw new OverflowException('The product stock version has reached its maximum value.');
        }

        $product->stock_quantity = $stockQuantity;
        $product->stock_version++;

        if (! $product->save()) {
            throw new RuntimeException('The product stock balance could not be saved.');
        }

        $movement = new StockMovement([
            'product_id' => $product->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'quantity_before' => $previousQuantity,
            'quantity_after' => $stockQuantity,
            'quantity_delta' => $stockQuantity - $previousQuantity,
            'stock_version' => $product->stock_version,
            'reason' => $reason,
        ]);

        if (! $movement->save()) {
            throw new RuntimeException('The stock movement could not be saved.');
        }

        return $product;
    }

    private function assertValidMovement(int $stockQuantity, string $reason): void
    {
        if ($stockQuantity < 0 || $stockQuantity > Product::MAX_STOCK_QUANTITY) {
            throw new InvalidArgumentException('The stock quantity is outside the supported range.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > StockMovement::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('A stock movement requires a reason within the supported length.');
        }
    }
}
