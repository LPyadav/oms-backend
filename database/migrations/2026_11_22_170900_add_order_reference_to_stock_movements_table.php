<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $usesSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('stock_movements', static function (Blueprint $table) use ($usesSqlite): void {
            if ($usesSqlite) {
                // An inline reference avoids rebuilding the table and losing its CHECK constraints.
                $table->rawColumn('order_id', 'integer references orders(id) on delete restrict')->nullable();
            } else {
                $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            }

            $table->unique(['order_id', 'product_id', 'type'], 'stock_movements_order_product_type_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('stock_movements')->whereNotNull('order_id')->exists()) {
            throw new LogicException('Cannot remove order references while order stock movements exist.');
        }

        $connection = Schema::getConnection();
        $dropsInlineReference = $connection->getDriverName() === 'sqlite'
            && version_compare($connection->getServerVersion(), '3.35', '>=');

        Schema::table('stock_movements', static function (Blueprint $table) use ($dropsInlineReference): void {
            if (! $dropsInlineReference) {
                $table->dropForeign(['order_id']);
            }

            $table->dropUnique('stock_movements_order_product_type_unique');
            $table->dropColumn('order_id');
        });
    }
};
