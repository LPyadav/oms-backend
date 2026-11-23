<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private const string FULFILLMENT = '/api/reports/orders/export';

    private const string SALES_TAX = '/api/reports/sales-tax/export';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'dashboard.timezone' => 'UTC',
        ]);
    }

    public function test_exports_require_both_report_and_global_order_permissions(): void
    {
        foreach ([self::FULFILLMENT, self::SALES_TAX] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }

        foreach (['reports.view', 'orders.view.any'] as $permission) {
            $actor = User::factory()->createOne()->givePermissionTo($permission);
            Auth::forgetGuards();
            $this->withToken($actor->createToken('report export')->plainTextToken);

            foreach ([self::FULFILLMENT, self::SALES_TAX] as $endpoint) {
                $this->getJson($endpoint)->assertForbidden();
            }
        }
    }

    public function test_fulfillment_export_includes_every_matching_order_regardless_of_page(): void
    {
        $this->authenticateAsManager();

        $customer = Customer::factory()->createOne(['name' => '=1+1']);
        $assignee = User::factory()->createOne(['name' => 'Doe, "Morgan"']);
        $orders = Order::factory()->state([
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
            'status' => OrderStatus::Pending,
            'order_date' => '2026-11-20',
            'grand_total_minor' => 405,
        ]);
        $earlier = $orders->createOne();
        $later = $orders->createOne(['order_date' => '2026-11-21', 'currency' => 'EUR']);
        $orders->createOne(['order_date' => '2026-11-22']);
        $orders->createOne(['status' => OrderStatus::Cancelled]);
        $orders->createOne(['assignee_id' => null]);
        $orders->createOne(['customer_id' => Customer::factory()->createOne()->id]);
        $orders->createOne()->delete();

        $response = $this->getJson(self::FULFILLMENT.'?'.http_build_query([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-21',
            'status' => OrderStatus::Pending->value,
            'customer_id' => $customer->id,
            'assignee_id' => $assignee->id,
            'page' => 2,
            'per_page' => 1,
        ]))->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('order-fulfillment.csv');

        self::assertSame([
            ['Order number', 'Order date', 'Customer', 'Priority', 'Status', 'Assigned employee', 'Currency', 'Total'],
            [$later->order_number, '2026-11-21', "'=1+1", $later->priority->value, 'pending', $assignee->name, 'EUR', '4.05'],
            [$earlier->order_number, '2026-11-20', "'=1+1", $earlier->priority->value, 'pending', $assignee->name, 'USD', '4.05'],
        ], $this->rows($response->streamedContent()));
    }

    public function test_sales_tax_export_reuses_completion_date_and_product_filters_and_whole_order_totals(): void
    {
        $this->authenticateAsManager();
        Config::set('dashboard.timezone', 'America/New_York');

        $customer = Customer::factory()->createOne(['name' => 'River Retail']);
        $product = Product::factory()->createOne();
        $orders = Order::factory()->state([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Completed,
            'order_date' => '2026-10-10',
            'completed_at' => '2026-11-21 04:59:59',
            'subtotal_minor' => 10000,
            'discount_total_minor' => 1500,
            'order_discount_minor' => 500,
            'tax_total_minor' => 850,
            'grand_total_minor' => 9350,
        ]);
        $included = $orders->createOne();
        $afterRange = $orders->createOne(['completed_at' => '2026-11-21 05:00:00']);
        $cancelled = $orders->createOne(['status' => OrderStatus::Cancelled]);
        $otherCustomer = $orders->createOne(['customer_id' => Customer::factory()->createOne()->id]);
        $orders->createOne();

        $items = OrderItem::factory()->state(['product_id' => $product->id]);
        foreach ([$included, $afterRange, $cancelled, $otherCustomer] as $order) {
            $items->createOne(['order_id' => $order->id]);
        }

        $response = $this->getJson(self::SALES_TAX.'?'.http_build_query([
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-20',
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'page' => 2,
            'per_page' => 1,
        ]))->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('sales-tax.csv');

        self::assertSame([
            ['Order number', 'Completed at (UTC)', 'Customer', 'Currency', 'Subtotal', 'Item discount', 'Order discount', 'Net before tax', 'Tax', 'Total'],
            [$included->order_number, '2026-11-21T04:59:59.000000Z', $customer->name, 'USD', '100.00', '10.00', '5.00', '85.00', '8.50', '93.50'],
        ], $this->rows($response->streamedContent()));
    }

    public function test_export_row_limit_rejects_filters_that_match_too_many_orders(): void
    {
        $this->authenticateAsManager();
        Config::set('reports.export_max_rows', 1);
        Order::factory()->count(2)->create([
            'order_date' => '2026-11-20',
            'status' => OrderStatus::Completed,
            'completed_at' => '2026-11-20 12:00:00',
        ]);

        foreach ([self::FULFILLMENT, self::SALES_TAX] as $endpoint) {
            $this->getJson($endpoint.'?date_from=2026-11-20&date_to=2026-11-20&per_page=1')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('export', 'errors.fields');
        }
    }

    public function test_exports_enforce_report_date_range_validation(): void
    {
        $this->authenticateAsManager();
        Config::set('reports.max_days', 2);

        foreach ([self::FULFILLMENT, self::SALES_TAX] as $endpoint) {
            $this->getJson($endpoint.'?date_from=2026-11-20&date_to=2026-11-22')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('date_to', 'errors.fields');
        }
    }

    private function authenticateAsManager(): void
    {
        $manager = User::factory()->createOne()->assignRole('manager');
        $this->withToken($manager->createToken('report export')->plainTextToken);
    }

    /** @return list<array<array-key, string|null>> */
    private function rows(string $csv): array
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
