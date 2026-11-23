<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_histories', static function (Blueprint $table): void {
            $statuses = [
                'draft',
                'pending',
                'confirmed',
                'processing',
                'ready',
                'completed',
                'cancelled',
            ];

            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->enum('previous_status', $statuses);
            $table->enum('new_status', $statuses);
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
