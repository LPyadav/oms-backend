<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ProductSeeder extends Seeder
{
    public function run(InventoryService $inventoryService): void
    {
        $products = [
            'CAT-DEMO-001' => [
                'name' => 'Shipping Box',
                'price' => '100.00',
                'tax_rate' => '18.00',
                'opening_stock' => 100,
                'is_active' => true,
            ],
            'CAT-DEMO-002' => [
                'name' => 'Packing Tape',
                'price' => '49.50',
                'tax_rate' => '5.00',
                'opening_stock' => 0,
                'is_active' => true,
            ],
            'CAT-DEMO-003' => [
                'name' => 'Retired Label Pack',
                'price' => '25.00',
                'tax_rate' => '0.00',
                'opening_stock' => 15,
                'is_active' => false,
            ],
        ];

        DB::transaction(static function () use ($products, $inventoryService): void {
            foreach ($products as $sku => $attributes) {
                $openingStock = $attributes['opening_stock'];
                unset($attributes['opening_stock']);

                $product = Product::query()->firstOrCreate(['sku' => $sku], $attributes);
                if ($product->wasRecentlyCreated && $openingStock > 0) {
                    $inventoryService->initialize($product, $openingStock, 'Demo opening stock.');
                }
            }
        });
    }
}
