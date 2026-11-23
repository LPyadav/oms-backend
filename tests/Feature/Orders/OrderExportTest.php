<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class OrderExportTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/orders/export';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    }

    public function test_export_requires_an_account_with_global_order_access(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        $staff = User::factory()->create()->assignRole('staff');
        Order::factory()->create(['assignee_id' => $staff->id]);

        $this->withToken($staff->createToken('order export')->plainTextToken)
            ->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_export_preserves_filters_and_sorting_and_protects_spreadsheet_cells(): void
    {
        $this->authenticateAsManager();

        $email = 'customer-contact@example.test';
        $customer = Customer::factory()->create([
            'name' => ' =2+2',
            'email' => $email,
        ]);
        $assignee = User::factory()->create(['name' => 'Doe, "Morgan"']);
        $orders = Order::factory()->state([
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
            'status' => OrderStatus::Pending,
            'order_date' => '2026-11-23',
        ]);

        $larger = $orders->create([
            'subtotal_minor' => 20000,
            'discount_total_minor' => 2500,
            'tax_total_minor' => 875,
            'grand_total_minor' => 18375,
        ]);
        $smaller = $orders->create(['subtotal_minor' => 5, 'grand_total_minor' => 5]);
        $orders->create(['status' => OrderStatus::Draft]);
        $orders->create(['order_date' => '2026-11-24']);
        Order::factory()->create();

        $response = $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'status' => 'pending',
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
            'date_to' => '2026-11-23',
            'sort' => 'total',
            'direction' => 'asc',
            'page' => 2,
            'per_page' => 1,
        ]))->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('orders.csv');

        $csv = $response->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringNotContainsString($email, $csv);

        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $headers = fgetcsv($stream, escape: '');
        $first = fgetcsv($stream, escape: '');
        $second = fgetcsv($stream, escape: '');
        self::assertFalse(fgetcsv($stream, escape: ''));
        fclose($stream);

        self::assertSame([
            'Order number', 'Customer', 'Order date', 'Status', 'Priority', 'Assigned employee',
            'Currency', 'Subtotal', 'Discount', 'Tax', 'Total',
        ], $headers);
        self::assertSame(
            [$smaller->order_number, "' =2+2", '2026-11-23', 'pending', $smaller->priority->value,
                'Doe, "Morgan"', 'USD', '0.05', '0.00', '0.00', '0.05'],
            $first,
        );
        self::assertSame(
            [$larger->order_number, "' =2+2", '2026-11-23', 'pending', $larger->priority->value,
                'Doe, "Morgan"', 'USD', '200.00', '25.00', '8.75', '183.75'],
            $second,
        );
    }

    public function test_export_reports_a_row_limit_instead_of_silently_truncating(): void
    {
        $this->authenticateAsManager();
        Config::set('orders.export_max_rows', 2);
        Order::factory()->count(3)->create();

        $this->getJson(self::ENDPOINT)->assertUnprocessable()
            ->assertJsonPath('errors.fields.export.0', 'More than 2 orders match. Narrow the filters and try again.');
    }

    public function test_export_is_rate_limited_per_account(): void
    {
        $this->freezeTime();
        $this->authenticateAsManager();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->getJson(self::ENDPOINT)->assertOk();
        }

        $this->getJson(self::ENDPOINT)->assertTooManyRequests();

        Auth::forgetGuards();
        $this->authenticateAsManager();
        $this->getJson(self::ENDPOINT)->assertOk();
    }

    private function authenticateAsManager(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->withToken($manager->createToken('order export')->plainTextToken);
    }
}
