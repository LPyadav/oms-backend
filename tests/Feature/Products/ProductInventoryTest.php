<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_records_opening_stock_once(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductSeeder::class);

        $product = Product::query()->where('sku', 'CAT-DEMO-001')->firstOrFail();

        $this->assertSame(100, $product->stock_quantity);
        $this->assertSame(1, $product->stock_version);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'actor_id' => null,
            'type' => 'opening_balance',
            'quantity_before' => 0,
            'quantity_after' => 100,
            'quantity_delta' => 100,
            'stock_version' => 1,
        ]);
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_existing_balances_are_backfilled_at_their_current_versions(): void
    {
        $product = Product::factory()->create([
            'stock_quantity' => 12,
            'stock_version' => 4,
        ]);
        Product::factory()->create(['stock_quantity' => 0]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_22_170400_backfill_opening_stock_movements.php';
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 12,
            'stock_version' => 4,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'actor_id' => null,
            'type' => 'opening_balance',
            'quantity_before' => 0,
            'quantity_after' => 12,
            'quantity_delta' => 12,
            'stock_version' => 4,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
