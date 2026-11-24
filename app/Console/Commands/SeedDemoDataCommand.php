<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\Auth\PasswordRules;
use App\Services\Demo\DemoDataSeeder;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Output\OutputInterface;

final class SeedDemoDataCommand extends Command
{
    protected $signature = 'oms:seed-demo-data
        {--migrate : Apply pending migrations before seeding}
        {--users= : Target number of demo users}
        {--customers= : Target number of demo customers}
        {--products= : Target number of demo products}
        {--orders= : Target number of demo orders}';

    protected $description = 'Seed a local OMS demo dataset with a shared demo password';

    public function handle(DemoDataSeeder $seeder): int
    {
        if (! app()->environment('local')) {
            $this->error('Demo data can only be seeded in the local environment.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'users' => $this->option('users') ?? Config::get('demo_data.users'),
            'customers' => $this->option('customers') ?? Config::get('demo_data.customers'),
            'products' => $this->option('products') ?? Config::get('demo_data.products'),
            'orders' => $this->option('orders') ?? Config::get('demo_data.orders'),
            'order_batch_size' => Config::get('demo_data.order_batch_size'),
            'password' => Config::get('demo_data.password'),
        ], [
            'users' => ['required', 'integer', 'min:3', 'max:10000'],
            'customers' => ['required', 'integer', 'min:1', 'max:10000'],
            'products' => ['required', 'integer', 'min:2', 'max:10000'],
            'orders' => ['required', 'integer', 'min:1', 'max:50000'],
            'order_batch_size' => ['required', 'integer', 'between:1,1000'],
            'password' => PasswordRules::newPassword(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{users: int|numeric-string, customers: int|numeric-string, products: int|numeric-string, orders: int|numeric-string, order_batch_size: int|numeric-string, password: string} $settings */
        $settings = $validator->validated();

        // if ($this->option('migrate') && $this->call('migrate') !== self::SUCCESS) {
        //     return self::FAILURE;
        // }

        $this->info('Seeding the local OMS demo dataset...');
        $progress = $this->output->createProgressBar((int) $settings['orders']);
        $progress->start();

        try {
            $seeder->seed(
                (int) $settings['users'],
                (int) $settings['customers'],
                (int) $settings['products'],
                (int) $settings['orders'],
                (int) $settings['order_batch_size'],
                $settings['password'],
                static function (int $processed) use ($progress): void {
                    $progress->advance($processed);
                },
            );
        } catch (DomainException $exception) {
            $progress->clear();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $progress->finish();
        $this->newLine(2);

        $this->table(['Dataset', 'Target'], [
            ['Users', $settings['users']],
            ['Customers', $settings['customers']],
            ['Products', $settings['products']],
            ['Orders', $settings['orders']],
        ]);
        $this->output->writeln(
            'Shared password for demo accounts: '.$settings['password'],
            OutputInterface::OUTPUT_RAW,
        );
        $this->table(['Role', 'Example email'], [
            ['Admin', 'load-demo-admin-0001@oms.test'],
            ['Manager', 'load-demo-manager-0002@oms.test'],
            ['Staff', 'load-demo-staff-0003@oms.test'],
        ]);

        return self::SUCCESS;
    }
}
