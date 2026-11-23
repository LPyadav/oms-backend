<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('recipient_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event_id', 'recipient_id'], 'order_notifications_event_recipient_unique');
            $table->foreign(['event_id', 'recipient_id'], 'order_notifications_event_recipient_foreign')
                ->references(['id', 'recipient_id'])
                ->on('order_notification_events')
                ->restrictOnDelete();

            $table->index(['recipient_id', 'id'], 'order_notifications_recipient_latest_index');
            $table->index(['recipient_id', 'read_at', 'id'], 'order_notifications_recipient_read_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
