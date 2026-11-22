<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Roles\BuiltInAccessProvisioner;
use Illuminate\Database\Seeder;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(BuiltInAccessProvisioner::class)->provision();
    }
}
