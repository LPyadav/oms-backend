<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notification_events', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->rawColumn(
                'order_version',
                'bigint check (order_version >= 1 and order_version = cast(order_version as signed integer))',
            );
            $table->enum('event_type', ['order.assigned', 'order.confirmed', 'order.cancelled']);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('skip_reason', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_id', 'order_version', 'event_type'], 'order_notification_events_version_type_unique');
            $table->unique(['id', 'recipient_id'], 'order_notification_events_id_recipient_unique');
            $table->index(['processed_at', 'available_at', 'id'], 'order_notification_events_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notification_events');
    }
};
