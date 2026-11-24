<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SeedDemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_command_is_available_only_in_the_local_environment(): void
    {
        self::assertSame(Command::FAILURE, Artisan::call('oms:seed-demo-data'));
        self::assertStringContainsString('Demo data can only be seeded in the local environment.', Artisan::output());

        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_data_uses_one_password_and_reruns_without_duplicate_records(): void
    {
        $this->app->instance('env', 'local');
        $sharedPassword = 'Local<info>Demo</info>Fixture!1';
        Config::set('demo_data.password', $sharedPassword);
        $existingUser = User::factory()->create(['email' => 'admin@oms.test']);
        $existingPasswordHash = $existingUser->getAuthPassword();
        $options = ['--users' => 3, '--customers' => 2, '--products' => 2, '--orders' => 7];

        $firstRun = Artisan::call('oms:seed-demo-data', $options);
        $firstOutput = Artisan::output();
        $secondRun = Artisan::call('oms:seed-demo-data', $options);
        $secondOutput = Artisan::output();
        self::assertSame([Command::SUCCESS, Command::SUCCESS], [$firstRun, $secondRun]);

        foreach ([$firstOutput, $secondOutput] as $output) {
            self::assertStringContainsString('Shared password for demo accounts: '.$sharedPassword, $output);
            self::assertStringContainsString('load-demo-admin-0001@oms.test', $output);
            self::assertStringContainsString('load-demo-manager-0002@oms.test', $output);
            self::assertStringContainsString('load-demo-staff-0003@oms.test', $output);
        }

        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('orders', 7);
        $this->assertDatabaseCount('order_items', 14);
        $this->assertDatabaseCount('order_notes', 7);
        $this->assertDatabaseCount('order_status_histories', 18);
        $this->assertDatabaseCount('order_notification_events', 13);
        $this->assertDatabaseCount('stock_movements', 14);
        self::assertSame($existingPasswordHash, $existingUser->refresh()->getAuthPassword());

        foreach (['admin' => '0001', 'manager' => '0002', 'staff' => '0003'] as $role => $number) {
            $user = User::query()->where('email', "load-demo-{$role}-{$number}@oms.test")->firstOrFail();
            self::assertTrue($user->hasExactRoles($role));
            self::assertTrue(Hash::check($sharedPassword, $user->getAuthPassword()));
        }

        foreach (OrderStatus::cases() as $status) {
            $this->assertDatabaseHas('orders', ['status' => $status->value]);
        }
    }

    public function test_large_demo_target_keeps_committed_orders_and_can_resume_after_a_batch_fails(): void
    {
        $this->app->instance('env', 'local');
        Config::set('demo_data.order_batch_size', 2);
        $attempts = 0;
        Order::creating(static function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 4) {
                throw new DomainException('Interrupted demo batch.');
            }
        });

        $options = ['--users' => 3, '--customers' => 2, '--products' => 2];
        self::assertSame(Command::FAILURE, Artisan::call('oms:seed-demo-data', $options + ['--orders' => 50000]));
        self::assertStringContainsString('Interrupted demo batch.', Artisan::output());
        self::assertStringNotContainsString('Shared password for demo accounts:', Artisan::output());
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('order_creation_keys', 2);
        $this->assertDatabaseCount('order_notification_events', 2);
        $completedOrderIds = Order::query()->orderBy('id')->pluck('id')->all();

        $admin = User::query()->where('email', 'load-demo-admin-0001@oms.test')->firstOrFail();
        $product = Product::query()->where('sku', 'LOAD-DEMO-0001')->firstOrFail();
        $this->app->make(InventoryService::class)->adjust($admin, $product->id, 0, $product->stock_version, 'Deplete demo stock before resuming');

        $resumed = Artisan::call('oms:seed-demo-data', $options + ['--orders' => 7]);
        self::assertSame(Command::SUCCESS, $resumed);
        $this->assertDatabaseCount('orders', 7);
        $this->assertDatabaseCount('order_creation_keys', 7);
        $this->assertDatabaseCount('order_items', 14);
        $this->assertDatabaseCount('order_status_histories', 18);
        $this->assertDatabaseCount('order_notification_events', 13);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'reason' => 'Demo stock replenishment']);
        self::assertSame($completedOrderIds, Order::query()->orderBy('id')->limit(2)->pluck('id')->all());
    }
}
