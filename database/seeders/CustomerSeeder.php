<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            'catalog-active@example.test' => [
                'name' => 'Harbor Retail',
                'is_active' => true,
            ],
            'catalog-inactive@example.test' => [
                'name' => 'Maple Supplies',
                'is_active' => false,
            ],
        ];

        DB::transaction(static function () use ($customers): void {
            foreach ($customers as $email => $attributes) {
                Customer::query()->firstOrCreate(['email' => $email], $attributes);
            }
        });
    }
}
