<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email', 254)->nullable()->index();
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
