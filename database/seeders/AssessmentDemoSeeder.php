<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderService;
use App\Services\Products\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AssessmentDemoSeeder extends Seeder
{
    public function run(
        CustomerService $customers,
        ProductService $products,
        InventoryService $inventory,
        OrderService $orders,
        OrderLifecycleService $lifecycle,
    ): void {
        if (! app()->environment('local')) {
            throw new LogicException('Use the assessment demo seed only in a local database.');
        }

        $admin = User::query()->where('email', 'admin@oms.test')->firstOrFail();
        $assignees = User::query()
            ->whereIn('email', ['staff@oms.test', 'staff2@oms.test'])
            ->where('is_active', true)
            ->orderBy('email')
            ->get();

        if ($assignees->count() !== 2) {
            throw new LogicException('Run oms:create-demo-users --extended first.');
        }

        $customerRows = [];
        for ($index = 1; $index <= 100; $index++) {
            $email = sprintf('review-customer-%03d@oms.test', $index);
            $customerRows[] = Customer::query()->where('email', $email)->first()
                ?? $customers->create($admin, [
                    'name' => sprintf('Review Customer %03d', $index),
                    'email' => $email,
                    'address' => sprintf('%d Market Street, Review City', $index),
                ]);
        }

        $productRows = [];
        $taxRates = ['0.00', '5.00', '18.00'];
        for ($index = 1; $index <= 50; $index++) {
            $sku = sprintf('REVIEW-%03d', $index);
            $productRows[] = Product::query()->where('sku', $sku)->first()
                ?? DB::transaction(static function () use ($admin, $products, $inventory, $index, $sku, $taxRates): Product {
                    $product = $products->create($admin, [
                        'sku' => $sku,
                        'name' => sprintf('Review Product %03d', $index),
                        'price' => (string) (15 + $index).'.00',
                        'tax_rate' => $taxRates[$index % count($taxRates)],
                    ]);

                    return $inventory->adjust($admin, $product->id, 500, $product->stock_version, 'Assessment opening stock');
                });
        }

        $start = $customerRows[0]->created_at ?? now();
        $priorities = OrderPriority::cases();
        $statuses = OrderStatus::cases();

        for ($index = 0; $index < 504; $index++) {
            $customer = $customerRows[$index % count($customerRows)];
            $product = $productRows[($index * 13) % count($productRows)];
            $otherProduct = $productRows[($index * 13 + 7) % count($productRows)];
            $assignee = $assignees->get($index % $assignees->count());
            $target = $statuses[$index % count($statuses)];

            $order = $orders->create($admin, [
                'customer_id' => $customer->id,
                'order_date' => $start->copy()->subDays($index % 28)->toDateString(),
                'priority' => $priorities[$index % count($priorities)]->value,
                'assignee_id' => $assignee?->id,
                'note' => 'Review order reference '.($index + 1),
                'order_discount_minor' => $index % 4 === 0 ? 100 : 0,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1 + $index % 3],
                    ['product_id' => $otherProduct->id, 'quantity' => 1, 'item_discount_minor' => 25],
                ],
            ], 'assessment-demo-'.($index + 1));

            $this->advance($admin, $order, $target, $lifecycle);
        }
    }

    private function advance(User $admin, Order $order, OrderStatus $target, OrderLifecycleService $lifecycle): void
    {
        $path = match ($target) {
            OrderStatus::Draft => [OrderStatus::Draft],
            OrderStatus::Pending => [OrderStatus::Draft, OrderStatus::Pending],
            OrderStatus::Cancelled => [OrderStatus::Draft, OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Cancelled],
            default => array_slice(OrderStatus::cases(), 0, array_search($target, OrderStatus::cases(), true) + 1),
        };

        $position = array_search($order->status, $path, true);
        if ($position === false) {
            return;
        }

        foreach (array_slice($path, $position + 1) as $next) {
            $order = $lifecycle->transition(
                $admin,
                (string) $order->id,
                $next,
                $order->version,
                'Assessment workflow seed',
            );
        }
    }
}
