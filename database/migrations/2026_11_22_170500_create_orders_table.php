<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', static function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('order_date');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', [
                'draft',
                'pending',
                'confirmed',
                'processing',
                'ready',
                'completed',
                'cancelled',
            ])->default('draft');
            $table->char('currency', 3);

            $moneyColumns = [
                'subtotal_minor',
                'order_discount_minor',
                'discount_total_minor',
                'tax_total_minor',
                'grand_total_minor',
            ];

            foreach ($moneyColumns as $column) {
                $table->rawColumn(
                    $column,
                    "bigint check ({$column} >= 0 and {$column} = cast({$column} as signed integer))",
                );
            }

            $table->rawColumn(
                'version',
                'bigint check (version >= 1 and version = cast(version as signed integer))',
            )->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'order_date', 'id']);
            $table->index(['status', 'deleted_at', 'order_date', 'id']);
            $table->index(['customer_id', 'deleted_at', 'order_date', 'id']);
            $table->index(['assignee_id', 'deleted_at', 'order_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
