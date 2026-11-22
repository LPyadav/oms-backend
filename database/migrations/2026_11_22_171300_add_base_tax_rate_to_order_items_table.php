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
            // A legacy line uses its saved tax rate as the base until its next pricing change.
            $table->rawColumn(
                'base_tax_rate_basis_points',
                'integer check (base_tax_rate_basis_points between 0 and 10000 and base_tax_rate_basis_points = cast(base_tax_rate_basis_points as signed integer))',
            )->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', static function (Blueprint $table): void {
            $table->dropColumn('base_tax_rate_basis_points');
        });
    }
};
