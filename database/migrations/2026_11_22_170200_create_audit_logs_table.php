<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->string('action', 64);
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['target_type', 'target_id', 'created_at', 'id'],
                'audit_logs_target_timeline_index',
            );
            $table->index(
                ['actor_id', 'created_at', 'id'],
                'audit_logs_actor_timeline_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
