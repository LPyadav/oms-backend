<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('type', 32);
            $table->rawColumn('quantity_before', 'integer check (quantity_before between 0 and 2147483647)');
            $table->rawColumn('quantity_after', 'integer check (quantity_after between 0 and 2147483647)');
            $table->rawColumn('quantity_delta', 'integer check (quantity_delta <> 0)');
            $table->unsignedBigInteger('stock_version');
            $table->string('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'stock_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
