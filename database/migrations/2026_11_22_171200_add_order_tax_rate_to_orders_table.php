<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->rawColumn(
                'order_tax_rate_basis_points',
                'integer check (order_tax_rate_basis_points between 0 and 10000 and order_tax_rate_basis_points = cast(order_tax_rate_basis_points as signed integer))',
            )->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->dropColumn('order_tax_rate_basis_points');
        });
    }
};
