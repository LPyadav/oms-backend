<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\Inventory\StockVersionConflictException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Services\Users\AdminUserWriteLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
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

    /**
     * The caller must hold the order row lock inside the lifecycle transaction.
     */
    public function deductForOrder(Order $order, User $actor): void
    {
        $this->requireOrderTransaction($order, $actor);

        $lines = $order->items()->reorder()->orderBy('product_id')->lockForUpdate()->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => Lang::string('api.orders.items_required'),
            ]);
        }

        $products = $this->lockProducts($lines->map(static fn (OrderItem $line): int => $line->product_id)->all());

        foreach ($lines as $line) {
            $product = $products->get($line->product_id);
            if ($product === null || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => Lang::string('api.orders.confirmation_product_unavailable', [
                        'sku' => $line->product_sku,
                    ]),
                ]);
            }

            if ($product->stock_quantity < $line->quantity) {
                throw ValidationException::withMessages([
                    'items' => Lang::string('api.orders.insufficient_stock', ['sku' => $line->product_sku]),
                ]);
            }

            $this->recordMovement(
                $product,
                $product->stock_quantity - $line->quantity,
                'Order confirmed.',
                StockMovement::TYPE_ORDER_DEDUCTION,
                $actor,
                $order,
            );
        }
    }

    /**
     * Restore the quantities recorded at confirmation under the same order lock.
     */
    public function restoreForOrder(Order $order, User $actor): void
    {
        $this->requireOrderTransaction($order, $actor);

        $deductions = StockMovement::query()
            ->where('order_id', $order->id)
            ->where('type', StockMovement::TYPE_ORDER_DEDUCTION)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get();

        if ($deductions->isEmpty()) {
            return;
        }

        $products = $this->lockProducts($deductions->map(static fn (StockMovement $movement): int => $movement->product_id)->all());

        foreach ($deductions as $deduction) {
            $product = $products->get($deduction->product_id);
            $delta = $deduction->quantity_delta;

            if ($product === null || $delta >= 0 || $delta < -Product::MAX_STOCK_QUANTITY) {
                throw new LogicException('An order deduction contains invalid stock movement data.');
            }

            $restoredStock = $product->stock_quantity - $delta;
            if ($restoredStock > Product::MAX_STOCK_QUANTITY) {
                throw ValidationException::withMessages([
                    'status' => Lang::string('api.orders.restoration_stock_limit', ['sku' => $product->sku]),
                ]);
            }

            $this->recordMovement(
                $product,
                $restoredStock,
                'Order cancelled.',
                StockMovement::TYPE_ORDER_RESTORATION,
                $actor,
                $order,
            );
        }
    }

    private function requireOrderTransaction(Order $order, User $actor): void
    {
        $connection = $order->getConnection();

        if (
            ! $order->exists
            || ! $actor->exists
            || $connection !== $actor->getConnection()
            || $connection !== DB::connection()
            || $connection->transactionLevel() === 0
        ) {
            throw new LogicException('Order inventory movements require persisted models in the active lifecycle transaction.');
        }
    }

    /**
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product>
     */
    private function lockProducts(array $productIds): Collection
    {
        return Product::query()
            ->whereKey($productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (Product $product): int => $product->id);
    }

    private function recordMovement(
        Product $product,
        int $stockQuantity,
        string $reason,
        string $type,
        ?User $actor,
        ?Order $order = null,
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
            'order_id' => $order?->id,
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
