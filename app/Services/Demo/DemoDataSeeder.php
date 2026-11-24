<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCreationKey;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\LoginEmail;
use App\Services\Customers\CustomerService;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderService;
use App\Services\Products\ProductService;
use App\Services\Users\AdminUserWriteLock;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

final readonly class DemoDataSeeder
{
    public function __construct(
        private CustomerService $customers,
        private ProductService $products,
        private InventoryService $inventory,
        private OrderService $orders,
        private OrderLifecycleService $lifecycle,
        private AdminUserWriteLock $writeLock,
    ) {}

    /** @param  (Closure(int): void)|null  $batchCompleted */
    public function seed(
        int $userCount,
        int $customerCount,
        int $productCount,
        int $orderCount,
        int $batchSize,
        #[SensitiveParameter] string $password,
        ?Closure $batchCompleted = null,
    ): void {
        $stockTarget = max(500, 4 * (int) ceil($orderCount / $productCount));
        [$admin, $staff, $customers, $products] = DB::transaction(function () use ($userCount, $customerCount, $productCount, $stockTarget, $password): array {
            [$admin, $staff] = $this->seedUsers($userCount, $password);
            $customers = $this->seedCustomers($admin, $customerCount);
            $products = $this->seedProducts($admin, $productCount, $stockTarget);

            return [$admin, $staff, $customers, $products];
        });

        for ($offset = 0; $offset < $orderCount; $offset += $batchSize) {
            $keys = [];
            for ($index = $offset; $index < min($offset + $batchSize, $orderCount); $index++) {
                $keys[$index] = 'load-demo-order-'.($index + 1);
            }

            DB::transaction(fn () => $this->seedOrderBatch($admin, $staff, $customers, $products, $keys, $stockTarget));
            $batchCompleted?->__invoke(count($keys));
        }
    }

    /**
     * @return array{User, non-empty-list<User>}
     */
    private function seedUsers(int $count, #[SensitiveParameter] string $password): array
    {
        $guard = Guard::getDefaultName(User::class);
        $adminRole = Role::query()->where('guard_name', $guard)->where('name', 'admin')->lockForUpdate()->first();

        if ($adminRole === null) {
            throw new DomainException('Run the database migrations before seeding demo data.');
        }

        $roles = Role::query()->where('guard_name', $guard)->whereIn('name', ['manager', 'staff'])->get()->keyBy('name');
        $roles->put('admin', $adminRole);

        if ($roles->count() !== 3) {
            throw new DomainException('The built-in Admin, Manager and Staff roles are required.');
        }

        $accounts = [];
        for ($index = 1; $index <= $count; $index++) {
            $roleName = match (true) {
                $index === 1 => 'admin',
                $index % 10 === 2 => 'manager',
                default => 'staff',
            };
            $email = sprintf('load-demo-%s-%04d@oms.test', $roleName, $index);
            $accounts[$email] = ['role' => $roleName, 'name' => sprintf('Demo %s %04d', ucfirst($roleName), $index)];
        }

        $existingUsers = User::query()->with(['roles', 'permissions'])->whereIn('email', array_keys($accounts))->get()
            ->keyBy(static fn (User $user): string => LoginEmail::normalize($user->email));
        $admin = null;
        $staff = [];
        $passwordHash = null;
        $verifiedHashes = [];

        foreach ($accounts as $email => $account) {
            $role = $roles->get($account['role']);
            if (! $role instanceof Role) {
                throw new DomainException('A required demo role could not be resolved.');
            }

            $user = $existingUsers->get($email);
            if ($user === null) {
                $passwordHash ??= Hash::make($password);
                $user = User::query()->create([
                    'name' => $account['name'],
                    'email' => $email,
                    'password' => $passwordHash,
                ]);
                $user->assignRole($role);
            } else {
                $hash = $user->getAuthPassword();

                if (
                    ! $user->is_active
                    || ! $user->hasExactRoles($role, $guard)
                    || $user->getDirectPermissions()->isNotEmpty()
                    || ! ($verifiedHashes[$hash] ??= Hash::check($password, $hash))
                ) {
                    throw new DomainException("Existing demo account {$email} has a different role, status, direct grant or password. Use a fresh local database or restore its demo settings.");
                }
            }

            if ($account['role'] === 'admin') {
                $admin = $user;
            }

            if ($account['role'] === 'staff') {
                $staff[] = $user;
            }
        }

        if ($admin === null || $staff === []) {
            throw new DomainException('The demo dataset requires an Admin and at least one Staff user.');
        }

        return [$admin->refresh(), $staff];
    }

    /**
     * @return non-empty-list<Customer>
     */
    private function seedCustomers(User $admin, int $count): array
    {
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $email = sprintf('load-demo-customer-%04d@oms.test', $index);
            $rows[] = Customer::query()->where('email', $email)->first() ?? $this->customers->create($admin, [
                'name' => sprintf('Demo Customer %04d', $index),
                'email' => $email,
                'address' => sprintf('%d Market Street, Demo City', $index),
            ]);
        }

        if ($rows === []) {
            throw new DomainException('At least one demo customer is required.');
        }

        return $rows;
    }

    /**
     * @return non-empty-list<Product>
     */
    private function seedProducts(User $admin, int $count, int $openingStock): array
    {
        $rows = [];
        $taxRates = ['0.00', '5.00', '18.00'];

        for ($index = 1; $index <= $count; $index++) {
            $sku = sprintf('LOAD-DEMO-%04d', $index);
            $product = Product::query()->where('sku', $sku)->first();
            if ($product === null) {
                $product = $this->products->create($admin, [
                    'sku' => $sku,
                    'name' => sprintf('Demo Product %04d', $index),
                    'price' => sprintf('%d.00', 15 + $index),
                    'tax_rate' => $taxRates[$index % count($taxRates)],
                ]);
                $product = $this->inventory->adjust($admin, $product->id, $openingStock, $product->stock_version, 'Demo opening stock');
            }

            $rows[] = $product;
        }

        if ($rows === []) {
            throw new DomainException('Demo products are required.');
        }

        return $rows;
    }

    /**
     * @param  non-empty-list<User>  $staff
     * @param  non-empty-list<Customer>  $customers
     * @param  non-empty-list<Product>  $products
     * @param  array<int, string>  $keys
     */
    private function seedOrderBatch(User $admin, array $staff, array $customers, array $products, array $keys, int $stockTarget): void
    {
        $admin = $this->writeLock->acquire($admin)['actor'];
        $keyHashes = array_map(static fn (string $key): string => hash('sha256', $key), $keys);
        $existingKeys = OrderCreationKey::query()
            ->where('actor_id', $admin->id)
            ->whereIn('key_hash', array_values($keyHashes))
            ->whereNotNull('order_id')
            ->pluck('key_hash')
            ->flip();
        $statuses = OrderStatus::cases();
        $plans = [];
        $requiredStock = [];

        foreach ($keys as $index => $key) {
            if ($existingKeys->has($keyHashes[$index])) {
                continue;
            }

            $target = $statuses[$index % count($statuses)];
            $items = [
                ['product_id' => $products[$index % count($products)]->id, 'quantity' => 1 + $index % 3],
                ['product_id' => $products[($index + 1) % count($products)]->id, 'quantity' => 1, 'item_discount_minor' => 25],
            ];
            $plans[$index] = ['key' => $key, 'target' => $target, 'items' => $items];

            if ($target === OrderStatus::Draft || $target === OrderStatus::Pending) {
                continue;
            }

            foreach ($items as ['product_id' => $productId, 'quantity' => $quantity]) {
                $requiredStock[$productId] = ($requiredStock[$productId] ?? 0) + $quantity;
            }
        }

        $this->replenishStock($admin, $requiredStock, $stockTarget);
        $priorities = OrderPriority::cases();
        $start = $customers[0]->created_at ?? now();

        foreach ($plans as $index => $plan) {
            $order = $this->orders->create($admin, [
                'customer_id' => $customers[$index % count($customers)]->id,
                'order_date' => $start->copy()->subDays($index % 28)->toDateString(),
                'priority' => $priorities[$index % count($priorities)]->value,
                'assignee_id' => $staff[$index % count($staff)]->id,
                'note' => 'Demo order reference '.($index + 1),
                'order_discount_minor' => $index % 4 === 0 ? 100 : 0,
                'items' => $plan['items'],
            ], $plan['key']);

            $this->advanceOrder($admin, $order, $plan['target']);
        }
    }

    /** @param  array<int, int>  $requiredStock */
    private function replenishStock(User $admin, array $requiredStock, int $stockTarget): void
    {
        if ($requiredStock === []) {
            return;
        }

        $products = Product::query()->whereKey(array_keys($requiredStock))->orderBy('id')->lockForUpdate()->get();
        foreach ($products as $product) {
            if ($product->stock_quantity < $requiredStock[$product->id]) {
                $this->inventory->adjust($admin, $product->id, max($stockTarget, $requiredStock[$product->id]), $product->stock_version, 'Demo stock replenishment');
            }
        }
    }

    private function advanceOrder(User $admin, Order $order, OrderStatus $target): void
    {
        $steps = $target === OrderStatus::Cancelled
            ? [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Cancelled]
            : array_slice(OrderStatus::cases(), 1, (int) array_search($target, OrderStatus::cases(), true));

        foreach ($steps as $step) {
            $order = $this->lifecycle->transition($admin, (string) $order->id, $step, $order->version, 'Demo workflow seed');
        }
    }
}
