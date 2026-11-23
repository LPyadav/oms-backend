<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', static function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'audit_logs_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', static function (Blueprint $table): void {
            $table->dropIndex('audit_logs_listing_index');
        });
    }
};
