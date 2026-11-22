<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $usesSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('products', static function (Blueprint $table) use ($usesSqlite): void {
            $table->id();
            $sku = $table->string('sku', 64)->unique();

            if ($usesSqlite) {
                $sku->collation('nocase');
            }

            $table->string('name');

            // Inline checks run in the CREATE statement on both supported databases.
            $table->rawColumn('price', 'decimal(12, 2) check (price between 0 and 9999999999.99)');
            $table->rawColumn('tax_rate', 'decimal(5, 2) check (tax_rate between 0 and 100)')->default(0);
            $table->rawColumn(
                'stock_quantity',
                'integer check (stock_quantity between 0 and 2147483647 and stock_quantity = cast(stock_quantity as signed integer))',
            )->default(0);

            $table->unsignedBigInteger('stock_version')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
