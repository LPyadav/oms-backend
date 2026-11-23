<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderNotificationType;
use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Exceptions\Orders\OrderIdempotencyConflictException;
use App\Exceptions\Orders\OrderVersionConflictException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCreationKey;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Audit\AuditLogWriter;
use App\Services\Notifications\OrderNotificationOutbox;
use App\Services\Users\AdminUserWriteLock;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * @phpstan-import-type OrderListCriteria from OrderListQuery
 * @phpstan-import-type LineInput from OrderPricingService
 * @phpstan-import-type PricedLine from OrderPricingService
 * @phpstan-import-type PricedOrder from OrderPricingService
 * @phpstan-import-type PricingPreview from OrderPricingService
 *
 * @phpstan-type OrderInputItem array{product_id: int, quantity: int, item_discount_minor?: int, tax_rate_basis_points?: int}
 * @phpstan-type CreateOrderData array{customer_id: int, order_date: string, items: list<OrderInputItem>, priority?: string, order_discount_minor?: int, assignee_id?: int|null, note?: string|null, order_tax_rate_basis_points?: int|null}
 * @phpstan-type UpdateOrderData array{expected_version: int, customer_id?: int, order_date?: string, priority?: string, items?: list<OrderInputItem>, order_discount_minor?: int, order_tax_rate_basis_points?: int|null}
 */
final readonly class OrderService
{
    private const array AUDIT_FIELDS = [
        'order_number',
        'customer_id',
        'assignee_id',
        'currency',
        'subtotal_minor',
        'order_discount_minor',
        'discount_total_minor',
        'order_tax_rate_basis_points',
        'tax_total_minor',
        'grand_total_minor',
        'version',
    ];

    private const array AUDIT_ITEM_FIELDS = [
        'position',
        'product_id',
        'product_name',
        'product_sku',
        'quantity',
        'unit_price_minor',
        'subtotal_minor',
        'item_discount_minor',
        'allocated_order_discount_minor',
        'base_tax_rate_basis_points',
        'tax_rate_basis_points',
        'tax_minor',
        'line_total_minor',
    ];

    public function __construct(
        private OrderPolicy $policy,
        private OrderReader $reader,
        private OrderPricingService $pricing,
        private AdminUserWriteLock $writeLock,
        private AuditLogWriter $auditLogWriter,
        private OrderWriteTransaction $writeTransaction,
        private OrderNotificationOutbox $notificationOutbox,
        private OrderItemSearchCache $itemSearchCache,
    ) {}

    /**
     * @param  OrderListCriteria  $criteria
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(User $actor, array $criteria): LengthAwarePaginator
    {
        return $this->reader->paginate($actor, $criteria);
    }

    public function find(User $actor, string $orderId): Order
    {
        return $this->reader->find($actor, $orderId);
    }

    /**
     * @param  list<OrderInputItem>  $items
     * @param  array{order_tax_rate_basis_points?: int|null}  $taxOverride
     * @return PricingPreview
     */
    public function preview(
        User $actor,
        array $items,
        ?int $orderDiscountMinor,
        ?int $orderId = null,
        array $taxOverride = [],
    ): array {
        $requestedRate = $taxOverride['order_tax_rate_basis_points'] ?? null;
        $taxRateProvided = array_key_exists('order_tax_rate_basis_points', $taxOverride);
        $authorized = $orderId === null
            ? $this->policy->create($actor, withTaxOverride: $requestedRate !== null || $this->hasItemTaxOverride($items))
            : $this->policy->update($actor);

        if (! $authorized) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $items, $orderDiscountMinor, $orderId, $requestedRate, $taxRateProvided): array {
            $order = $orderId === null ? null : $this->visibleOrders($actor)->findOrFail($orderId);
            if ($order !== null) {
                $this->ensureEditable($order);
            }

            $orderTaxRate = $taxRateProvided ? $requestedRate : $order?->order_tax_rate_basis_points;
            $this->ensureCompatibleTaxRates($items, $taxRateProvided || $orderTaxRate !== null);

            $currency = $order->currency ?? Config::string('orders.currency');
            $snapshots = $this->resolveLines($items, $order?->items()->get() ?? new Collection, $currency);

            $discount = $orderDiscountMinor ?? $order->order_discount_minor ?? 0;

            return ['currency' => $currency, ...$this->pricing->calculate($snapshots, $discount, $orderTaxRate)];
        });
    }

    /**
     * @param  CreateOrderData  $data
     */
    public function create(User $actor, array $data, string $idempotencyKey): Order
    {
        $this->authorizeCreation($actor, $data);
        $this->ensureCompatibleTaxRates($data['items'], array_key_exists('order_tax_rate_basis_points', $data));

        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = $this->creationRequestHash($data);

        return $this->writeTransaction->run(function () use ($actor, $data, $keyHash, $requestHash): Order {
            $lockedActor = $this->writeLock->acquireShared($actor);
            $creationKey = $this->lockCreationKey($lockedActor, $keyHash, $requestHash);
            $this->authorizeCreation($lockedActor, $data);

            if ($creationKey->request_hash !== $requestHash) {
                throw new OrderIdempotencyConflictException;
            }

            if ($creationKey->order_id !== null) {
                return Order::query()->with(OrderReader::DETAIL_RELATIONS)->findOrFail($creationKey->order_id);
            }

            $assigneeId = $data['assignee_id'] ?? null;
            $note = $data['note'] ?? null;

            if ($assigneeId !== null) {
                $this->ensureActiveAssignee($assigneeId);
            }

            $customer = $this->activeCustomer($data['customer_id']);
            $currency = Config::string('orders.currency');
            $lines = $this->resolveLines($data['items'], new Collection, $currency, lockProducts: true);
            $pricedOrder = $this->pricing->calculate(
                $lines,
                $data['order_discount_minor'] ?? 0,
                $data['order_tax_rate_basis_points'] ?? null,
            );

            $order = new Order([
                'customer_id' => $customer->id,
                'order_date' => $data['order_date'],
                'priority' => $data['priority'] ?? OrderPriority::Normal->value,
            ]);
            $order->forceFill([
                'order_number' => 'ORD-'.Str::ulid(),
                'created_by' => $lockedActor->id,
                'assignee_id' => $assigneeId,
                'status' => OrderStatus::Draft,
                'currency' => $currency,
                'version' => 1,
                ...$this->totals($pricedOrder),
            ]);

            $this->save($order);
            $this->insertItems($order, $pricedOrder['items']);

            if ($note !== null) {
                $this->appendInitialNote($order, $lockedActor, $note);
            }

            $order->load('items');
            $this->auditLogWriter->record($lockedActor, $order, 'order', 'order.created', null, $this->snapshot($order));
            if ($assigneeId !== null) {
                $this->auditLogWriter->record(
                    $lockedActor,
                    $order,
                    'order',
                    'order.assigned',
                    ['assignee_id' => null],
                    ['assignee_id' => $assigneeId],
                );
                $this->notificationOutbox->record($order, OrderNotificationType::Assigned);
            }

            $creationKey->order_id = $order->id;
            if (! $creationKey->save()) {
                throw new RuntimeException('The order creation result could not be recorded.');
            }

            return $order->loadMissing(OrderReader::DETAIL_RELATIONS);
        });
    }

    /**
     * @param  UpdateOrderData  $data
     */
    public function update(User $actor, string $orderId, array $data): Order
    {
        if (! $this->policy->update($actor)) {
            throw new AuthorizationException;
        }

        return $this->writeTransaction->run(function () use ($actor, $orderId, $data): Order {
            $lockedActor = $this->writeLock->acquireShared($actor);
            if (! $this->policy->update($lockedActor)) {
                throw new AuthorizationException;
            }

            $order = $this->visibleOrders($lockedActor)->lockForUpdate()->findOrFail($orderId);
            $this->ensureVersion($order, $data['expected_version']);
            $this->ensureEditable($order);
            $order->setRelation('items', $order->items()->lockForUpdate()->get());
            $before = $this->snapshot($order);
            $this->applyEditableFields($order, $data);
            $updatedItems = $this->reprice($order, $data);

            if (! $order->isDirty() && $updatedItems === null) {
                return $order->loadMissing(OrderReader::DETAIL_RELATIONS);
            }

            $this->advanceVersion($order);
            $this->save($order);

            // Replace only a changed line set. The order row stays locked throughout.
            if ($updatedItems !== null) {
                OrderItem::query()->where('order_id', $order->id)->delete();
                $this->insertItems($order, $updatedItems);
                $order->load('items');
            }

            $this->auditLogWriter->record(
                $lockedActor,
                $order,
                'order',
                'order.updated',
                $before,
                $this->snapshot($order),
            );

            return $order->loadMissing(OrderReader::DETAIL_RELATIONS);
        });
    }

    public function delete(User $actor, string $orderId, int $expectedVersion): void
    {
        if (! $this->policy->delete($actor)) {
            throw new AuthorizationException;
        }

        $this->writeTransaction->run(function () use ($actor, $orderId, $expectedVersion): void {
            $lockedActor = $this->writeLock->acquireShared($actor);
            if (! $this->policy->delete($lockedActor)) {
                throw new AuthorizationException;
            }

            $order = $this->visibleOrders($lockedActor)->lockForUpdate()->findOrFail($orderId);
            $this->ensureVersion($order, $expectedVersion);
            if (! in_array($order->status, [OrderStatus::Draft, OrderStatus::Pending, OrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => Lang::string('api.orders.not_deletable')]);
            }

            $order->setRelation('items', $order->items()->lockForUpdate()->get());
            $before = $this->snapshot($order);
            $this->advanceVersion($order);
            $this->save($order);
            if ($order->delete() !== true) {
                throw new RuntimeException('The order could not be soft deleted.');
            }

            $this->auditLogWriter->record(
                $lockedActor,
                $order,
                'order',
                'order.deleted',
                $before,
                $this->snapshot($order),
            );
        });
    }

    /**
     * @return Builder<Order>
     */
    public function visibleOrders(User $actor): Builder
    {
        return $this->reader->visibleOrders($actor);
    }

    /**
     * @param  CreateOrderData  $data
     */
    private function authorizeCreation(User $actor, array $data): void
    {
        if (! $this->policy->create(
            $actor,
            withAssignment: isset($data['assignee_id']),
            withNote: isset($data['note']),
            withTaxOverride: isset($data['order_tax_rate_basis_points']) || $this->hasItemTaxOverride($data['items']),
        )) {
            throw new AuthorizationException;
        }
    }

    /** @param list<OrderInputItem> $items */
    private function hasItemTaxOverride(array $items): bool
    {
        foreach ($items as $item) {
            if (isset($item['tax_rate_basis_points'])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<OrderInputItem> $items */
    private function ensureCompatibleTaxRates(array $items, bool $usesOrderTaxSetting): void
    {
        if ($usesOrderTaxSetting && $this->hasItemTaxOverride($items)) {
            throw ValidationException::withMessages([
                'order_tax_rate_basis_points' => Lang::string('api.orders.order_tax_conflicts_with_item_rates'),
            ]);
        }
    }

    private function lockCreationKey(User $actor, string $keyHash, string $requestHash): OrderCreationKey
    {
        // Serialize same-key requests before any consistent read establishes the MySQL snapshot.
        OrderCreationKey::query()->upsert([
            'actor_id' => $actor->id,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'created_at' => now(),
        ], ['actor_id', 'key_hash'], ['key_hash']);

        return OrderCreationKey::query()
            ->where('actor_id', $actor->id)
            ->where('key_hash', $keyHash)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param CreateOrderData $data */
    private function creationRequestHash(array $data): string
    {
        // Preserve line order and keep hashes stable for requests with no tax override.
        return hash('sha256', json_encode([
            'customer_id' => $data['customer_id'],
            'order_date' => $data['order_date'],
            'priority' => $data['priority'] ?? OrderPriority::Normal->value,
            'assignee_id' => $data['assignee_id'] ?? null,
            'note' => $data['note'] ?? null,
            'order_discount_minor' => $data['order_discount_minor'] ?? 0,
            'items' => array_map(static fn (array $item): array => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'item_discount_minor' => $item['item_discount_minor'] ?? 0,
                ...(isset($item['tax_rate_basis_points']) ? ['tax_rate_basis_points' => $item['tax_rate_basis_points']] : []),
            ], $data['items']),
            ...(isset($data['order_tax_rate_basis_points']) ? ['order_tax_rate_basis_points' => $data['order_tax_rate_basis_points']] : []),
        ], JSON_THROW_ON_ERROR));
    }

    private function activeCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->where('is_active', true)->sharedLock()->find($customerId);
        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => Lang::string('api.orders.customer_unavailable')]);
        }

        return $customer;
    }

    private function ensureActiveAssignee(int $assigneeId): void
    {
        $assignee = User::query()->where('is_active', true)->sharedLock()->find($assigneeId);
        if ($assignee === null) {
            throw ValidationException::withMessages(['assignee_id' => Lang::string('api.orders.assignee_unavailable')]);
        }
    }

    private function ensureVersion(Order $order, int $expectedVersion): void
    {
        if ($order->version !== $expectedVersion) {
            throw new OrderVersionConflictException($order->version);
        }
    }

    private function ensureEditable(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Draft, OrderStatus::Pending], true)) {
            throw ValidationException::withMessages(['status' => Lang::string('api.orders.not_editable')]);
        }
    }

    private function advanceVersion(Order $order): void
    {
        if ($order->version === PHP_INT_MAX) {
            throw ValidationException::withMessages(['expected_version' => Lang::string('api.orders.version_limit')]);
        }

        $order->version++;
    }

    /**
     * @param  UpdateOrderData  $data
     */
    private function applyEditableFields(Order $order, array $data): void
    {
        if (isset($data['customer_id']) && $data['customer_id'] !== $order->customer_id) {
            $order->customer()->associate($this->activeCustomer($data['customer_id']));
        }

        if (isset($data['order_date'])) {
            $order->order_date = $data['order_date'];
        }

        if (isset($data['priority'])) {
            $order->priority = OrderPriority::from($data['priority']);
        }
    }

    /**
     * @param  UpdateOrderData  $data
     * @return list<PricedLine>|null
     */
    private function reprice(Order $order, array $data): ?array
    {
        $taxRateProvided = array_key_exists('order_tax_rate_basis_points', $data);
        if (! isset($data['items']) && ! isset($data['order_discount_minor']) && ! $taxRateProvided) {
            return null;
        }

        $items = $data['items'] ?? array_values($order->items->map(static fn (OrderItem $line): array => [
            'product_id' => $line->product_id,
            'quantity' => $line->quantity,
            'item_discount_minor' => $line->item_discount_minor,
        ])->all());

        $orderTaxRate = $taxRateProvided ? $data['order_tax_rate_basis_points'] : $order->order_tax_rate_basis_points;
        $this->ensureCompatibleTaxRates($items, $taxRateProvided || $orderTaxRate !== null);

        $lines = $this->resolveLines($items, $order->items, $order->currency, lockProducts: true);
        $pricedOrder = $this->pricing->calculate(
            $lines,
            $data['order_discount_minor'] ?? $order->order_discount_minor,
            $orderTaxRate,
        );
        $order->forceFill($this->totals($pricedOrder));

        if ($order->items->count() !== count($pricedOrder['items'])) {
            return $pricedOrder['items'];
        }

        foreach ($order->items->values() as $index => $line) {
            $next = $pricedOrder['items'][$index];
            // Backfill a legacy base rate only when the line otherwise needs to be replaced.
            if ($line->base_tax_rate_basis_points === null && $next['base_tax_rate_basis_points'] === $line->tax_rate_basis_points) {
                unset($next['base_tax_rate_basis_points']);
            }

            if ((clone $line)->forceFill($next)->isDirty()) {
                return $pricedOrder['items'];
            }
        }

        return null;
    }

    /**
     * @param  list<OrderInputItem>  $items
     * @param  Collection<int, OrderItem>  $existingItems
     * @return list<LineInput>
     */
    private function resolveLines(array $items, Collection $existingItems, string $currency, bool $lockProducts = false): array
    {
        $maximum = Config::integer('orders.max_items');
        if (count($items) > $maximum) {
            throw ValidationException::withMessages([
                'items' => Lang::string('validation.max.array', ['attribute' => 'items', 'max' => $maximum]),
            ]);
        }

        $existing = $existingItems->keyBy(static fn (OrderItem $line): int => $line->product_id);
        $newProductIds = array_values(array_diff(array_column($items, 'product_id'), $existing->keys()->all()));
        if ($newProductIds !== [] && $currency !== Config::string('orders.currency')) {
            throw ValidationException::withMessages(['items' => Lang::string('api.orders.catalog_currency_changed')]);
        }

        $query = Product::query()->whereKey($newProductIds)->where('is_active', true)->orderBy('id');
        if ($lockProducts) {
            $query->sharedLock();
        }
        $products = $query->get()->keyBy(static fn (Product $product): int => $product->id);

        $lines = [];
        foreach ($items as $index => $item) {
            $productId = $item['product_id'];
            $previous = $existing->get($productId);
            $product = $products->get($productId);

            if ($previous === null && $product === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => Lang::string('api.orders.product_unavailable'),
                ]);
            }

            $snapshot = $previous === null
                ? $this->catalogSnapshot($product)
                : $this->savedSnapshot($previous);

            $lines[] = [
                'product_id' => $productId,
                'quantity' => $item['quantity'],
                'item_discount_minor' => $item['item_discount_minor'] ?? 0,
                ...$snapshot,
                'tax_rate_basis_points' => $item['tax_rate_basis_points'] ?? $snapshot['tax_rate_basis_points'],
            ];
        }

        return $lines;
    }

    /**
     * @return array{product_name: string, product_sku: string, unit_price_minor: int, tax_rate_basis_points: int}
     */
    private function catalogSnapshot(Product $product): array
    {
        return [
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price_minor' => BigDecimal::of($product->price)->multipliedBy(100)->toInt(),
            'tax_rate_basis_points' => BigDecimal::of($product->tax_rate)->multipliedBy(100)->toInt(),
        ];
    }

    /**
     * @return array{product_name: string, product_sku: string, unit_price_minor: int, tax_rate_basis_points: int}
     */
    private function savedSnapshot(OrderItem $line): array
    {
        return [
            'product_name' => $line->product_name,
            'product_sku' => $line->product_sku,
            'unit_price_minor' => $line->unit_price_minor,
            'tax_rate_basis_points' => $line->base_tax_rate_basis_points ?? $line->tax_rate_basis_points,
        ];
    }

    /**
     * @param  PricedOrder  $priced
     * @return array{subtotal_minor: int, order_discount_minor: int, discount_total_minor: int, order_tax_rate_basis_points: int|null, tax_total_minor: int, grand_total_minor: int}
     */
    private function totals(array $priced): array
    {
        return [
            'subtotal_minor' => $priced['subtotal_minor'],
            'order_discount_minor' => $priced['order_discount_minor'],
            'discount_total_minor' => $priced['discount_total_minor'],
            'order_tax_rate_basis_points' => $priced['order_tax_rate_basis_points'],
            'tax_total_minor' => $priced['tax_total_minor'],
            'grand_total_minor' => $priced['grand_total_minor'],
        ];
    }

    /**
     * @param  list<PricedLine>  $items
     */
    private function insertItems(Order $order, array $items): void
    {
        foreach ($items as $attributes) {
            $line = (new OrderItem)->forceFill($attributes);
            if ($order->items()->save($line) === false) {
                throw new RuntimeException('An order item could not be saved.');
            }
        }

        $this->itemSearchCache->invalidateAfterCommit();
    }

    private function appendInitialNote(Order $order, User $actor, string $body): void
    {
        if (trim($body) === '' || mb_strlen($body) > Config::integer('orders.max_note_length')) {
            throw ValidationException::withMessages(['note' => Lang::string('api.orders.note_invalid')]);
        }

        $note = (new OrderNote)->forceFill(['author_id' => $actor->id, 'body' => $body]);
        if ($order->notes()->save($note) === false) {
            throw new RuntimeException('The initial order note could not be saved.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        return [
            ...$order->only(self::AUDIT_FIELDS),
            'order_date' => $order->order_date->toDateString(),
            'priority' => $order->priority->value,
            'status' => $order->status->value,
            'deleted_at' => $order->deleted_at?->toISOString(),
            'items' => $order->items->map(static fn (OrderItem $line): array => $line->only(self::AUDIT_ITEM_FIELDS))
                ->values()
                ->all(),
        ];
    }

    private function save(Order $order): void
    {
        if (! $order->save()) {
            throw new RuntimeException('The order could not be saved.');
        }
    }
}
