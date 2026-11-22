<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', static function (Blueprint $table): void {
            $table->index(['product_sku', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', static function (Blueprint $table): void {
            $table->dropIndex(['product_sku', 'order_id']);
        });
    }
};
