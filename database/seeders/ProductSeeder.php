<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            'CAT-DEMO-001' => [
                'name' => 'Shipping Box',
                'price' => '100.00',
                'tax_rate' => '18.00',
                'stock_quantity' => 100,
                'is_active' => true,
            ],
            'CAT-DEMO-002' => [
                'name' => 'Packing Tape',
                'price' => '49.50',
                'tax_rate' => '5.00',
                'stock_quantity' => 0,
                'is_active' => true,
            ],
            'CAT-DEMO-003' => [
                'name' => 'Retired Label Pack',
                'price' => '25.00',
                'tax_rate' => '0.00',
                'stock_quantity' => 15,
                'is_active' => false,
            ],
        ];

        DB::transaction(static function () use ($products): void {
            foreach ($products as $sku => $attributes) {
                Product::query()->firstOrCreate(['sku' => $sku], $attributes);
            }
        });
    }
}
