<?php

declare(strict_types=1);

use App\Services\Roles\BuiltInAccessProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BuiltInAccessProvisioner::class)->provision();
    }

    public function down(): void
    {
        // Keep roles and grants that may predate this migration or have live assignments.
    }
};
