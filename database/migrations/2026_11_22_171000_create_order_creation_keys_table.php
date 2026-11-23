<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_creation_keys', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['actor_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_creation_keys');
    }
};
