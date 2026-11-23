<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_audit_logs', static function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'access_audit_logs_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('access_audit_logs', static function (Blueprint $table): void {
            $table->dropIndex('access_audit_logs_listing_index');
        });
    }
};
