<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->rawColumn(
                'position',
                'integer check (position between 1 and 2147483647 and position = cast(position as signed integer))',
            );
            $table->string('product_name');
            $table->string('product_sku', 64);
            $table->rawColumn(
                'quantity',
                'integer check (quantity between 1 and 2147483647 and quantity = cast(quantity as signed integer))',
            );

            $moneyColumns = [
                'unit_price_minor',
                'subtotal_minor',
                'item_discount_minor',
                'allocated_order_discount_minor',
                'tax_minor',
                'line_total_minor',
            ];

            foreach ($moneyColumns as $column) {
                $table->rawColumn(
                    $column,
                    "bigint check ({$column} >= 0 and {$column} = cast({$column} as signed integer))",
                );
            }

            $table->rawColumn(
                'tax_rate_basis_points',
                'integer check (tax_rate_basis_points between 0 and 10000 and tax_rate_basis_points = cast(tax_rate_basis_points as signed integer))',
            );
            $table->timestamps();

            $table->unique(['order_id', 'product_id']);
            $table->unique(['order_id', 'position']);
            $table->index(['product_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
