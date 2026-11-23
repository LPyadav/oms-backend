<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Constants\ApiErrorCode;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/products';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 20,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_manage_product_metadata_and_adjust_stock_with_history(): void
    {
        $admin = $this->authenticateAs('admin');

        $this->postJson(self::ENDPOINT, [
            'sku' => '  harbor-01.a_2  ',
            'name' => 'Harbor Anvil',
            'price' => '24.5',
        ])
            ->assertCreated()
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.product.sku', 'HARBOR-01.A_2')
            ->assertJsonPath('data.product.stock_quantity', 0)
            ->assertJsonPath('data.product.stock_version', 0)
            ->assertJsonPath('data.product.tax_rate', '0.00');

        $product = Product::query()->sole();
        $path = self::ENDPOINT.'/'.$product->id;

        $this->patchJson($path, ['name' => 'Harbor Anvil XL', 'tax_rate' => '18'])
            ->assertOk()
            ->assertJsonPath('data.product.price', '24.50')
            ->assertJsonPath('data.product.tax_rate', '18.00')
            ->assertJsonPath('data.product.stock_quantity', 0);

        $this->patchJson($path.'/stock', self::stockAdjustment(8, 0))
            ->assertOk()
            ->assertJsonPath('data.product.stock_quantity', 8)
            ->assertJsonPath('data.product.stock_version', 1);
        $this->patchJson($path.'/stock', self::stockAdjustment(3, 1))
            ->assertOk()
            ->assertJsonPath('data.product.stock_quantity', 3)
            ->assertJsonPath('data.product.stock_version', 2);

        $this->patchJson($path.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.product.is_active', false);
        $this->patchJson($path.'/deactivate')->assertOk();

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.product', [
                'id' => $product->id,
                'sku' => 'HARBOR-01.A_2',
                'name' => 'Harbor Anvil XL',
                'price' => '24.50',
                'tax_rate' => '18.00',
                'stock_quantity' => 3,
                'stock_version' => 2,
                'is_active' => false,
            ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'actor_id' => $admin->id,
            'type' => 'adjustment',
            'quantity_before' => 0,
            'quantity_after' => 8,
            'quantity_delta' => 8,
            'stock_version' => 1,
            'reason' => 'Physical count',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'actor_id' => $admin->id,
            'type' => 'adjustment',
            'quantity_before' => 8,
            'quantity_after' => 3,
            'quantity_delta' => -5,
            'stock_version' => 2,
        ]);
        $this->assertDatabaseCount('stock_movements', 2);

        $creation = AuditLog::query()->where('action', 'product.created')->sole();
        self::assertSame($admin->id, $creation->actor_id);
        self::assertSame('product', $creation->target_type);
        self::assertSame($product->id, $creation->target_id);
        self::assertNull($creation->before_values);

        $update = AuditLog::query()->where('action', 'product.updated')->sole();
        self::assertSame(['name' => 'Harbor Anvil', 'tax_rate' => '0.00'], $update->before_values);
        self::assertSame(['name' => 'Harbor Anvil XL', 'tax_rate' => '18.00'], $update->after_values);

        $deactivation = AuditLog::query()->where('action', 'product.deactivated')->sole();
        self::assertSame(['is_active' => true], $deactivation->before_values);
        self::assertSame(['is_active' => false], $deactivation->after_values);
        $adjustments = AuditLog::query()
            ->where('action', 'product.stock_adjusted')
            ->where('actor_id', $admin->id)
            ->where('target_type', 'product')
            ->where('target_id', $product->id)
            ->orderBy('id')
            ->get();
        self::assertSame([0, 8], $adjustments->pluck('before_values.stock_quantity')->all());
        self::assertSame([0, 1], $adjustments->pluck('before_values.stock_version')->all());
        self::assertSame([8, 3], $adjustments->pluck('after_values.stock_quantity')->all());
        self::assertSame([1, 2], $adjustments->pluck('after_values.stock_version')->all());
        $this->assertDatabaseCount('audit_logs', 5);
    }

    public function test_list_applies_literal_search_status_and_product_pagination(): void
    {
        $this->authenticateAs('manager');
        Config::set([
            'pagination.products.default_per_page' => 1,
            'pagination.products.max_per_page' => 2,
        ]);

        $first = Product::factory()->create(['sku' => 'SKU_1', 'name' => 'Dock item']);
        $second = Product::factory()->create(['sku' => 'WH-02', 'name' => 'SKU_ basket']);
        Product::factory()->create(['sku' => 'SKU-X', 'name' => 'SKUX Cabinet']);
        $inactive = Product::factory()->inactive()->create(['name' => 'Dock% shelf']);

        $query = self::ENDPOINT.'?search=SKU%5F&is_active=1';
        $this->getJson($query)
            ->assertOk()
            ->assertJsonPath('data.products.0.id', $first->id)
            ->assertJsonPath('meta', [
                'current_page' => 1,
                'per_page' => 1,
                'total' => 2,
                'last_page' => 2,
            ]);
        $this->getJson($query.'&page=2')
            ->assertOk()
            ->assertJsonPath('data.products.0.id', $second->id);
        $this->getJson(self::ENDPOINT.'?search=Dock%25&is_active=0')
            ->assertOk()
            ->assertJsonPath('data.products.0.id', $inactive->id)
            ->assertJsonPath('meta.total', 1);
        $this->getJson(self::ENDPOINT.'?page=0&per_page=3&is_active=01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page', 'per_page', 'is_active'], 'errors.fields');
    }

    public function test_manager_can_read_but_cannot_write_even_with_a_manage_grant(): void
    {
        $manager = $this->authenticateAs('manager');
        $manager->givePermissionTo('products.manage');

        $product = Product::factory()->create();
        $path = self::ENDPOINT.'/'.$product->id;

        $this->getJson(self::ENDPOINT)->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('data.product.id', $product->id);
        $this->postJson(self::ENDPOINT, self::newProduct())->assertForbidden();

        foreach ([$path, self::ENDPOINT.'/999999'] as $target) {
            $this->patchJson($target, ['name' => 'Unauthorized'])
                ->assertForbidden()
                ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);
            $this->patchJson($target.'/deactivate')->assertForbidden();
            $this->patchJson($target.'/stock', self::stockAdjustment(1, 0))->assertForbidden();
        }

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_staff_cannot_list_products_or_distinguish_missing_product_ids(): void
    {
        $this->authenticateAs('staff');
        $product = Product::factory()->create();

        $this->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);
        foreach ([self::ENDPOINT.'/'.$product->id, self::ENDPOINT.'/999999'] as $target) {
            $this->getJson($target)->assertForbidden();
            $this->patchJson($target.'/stock', self::stockAdjustment(1, 0))->assertForbidden();
        }
    }

    public function test_guest_needs_a_bearer_token_and_admin_gets_not_found_for_a_missing_product(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
        $this->patchJson(self::ENDPOINT.'/999999/stock')->assertUnauthorized();

        $this->authenticateAs('admin');
        $this->getJson(self::ENDPOINT.'/999999')
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);
    }

    public function test_admin_without_products_manage_cannot_write(): void
    {
        Role::findByName('admin', 'web')->revokePermissionTo('products.manage');
        $this->authenticateAs('admin');

        $this->postJson(self::ENDPOINT, self::newProduct())->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_duplicate_sku_is_reported_as_a_conflict_after_normalization(): void
    {
        $this->authenticateAs('admin');
        Product::factory()->create(['sku' => 'HARBOR-01']);
        $product = Product::factory()->create(['sku' => 'HARBOR-02']);

        $this->postJson(self::ENDPOINT, self::newProduct(' harbor-01 '))
            ->assertConflict()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(['sku'], 'errors.fields');
        $this->patchJson(self::ENDPOINT.'/'.$product->id, ['sku' => 'harbor-01'])
            ->assertConflict()
            ->assertJsonValidationErrors(['sku'], 'errors.fields');

        self::assertSame('HARBOR-02', $product->refresh()->sku);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_product_writes_validate_body_only_and_keep_stock_fields_out_of_metadata(): void
    {
        $this->authenticateAs('admin');
        $product = Product::factory()->create(['sku' => 'ORIGINAL', 'stock_quantity' => 0]);
        $path = self::ENDPOINT.'/'.$product->id;

        $this->postJson(self::ENDPOINT.'?sku=QUERY&name=Query&price=25')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'name', 'price'], 'errors.fields');
        $this->patchJson($path.'?sku=QUERY')
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED);
        $this->patchJson($path.'/stock?stock_quantity=3&expected_stock_version=0&reason=Query')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_quantity', 'expected_stock_version', 'reason'], 'errors.fields');

        $protectedFields = ['stock_quantity' => 5, 'stock_version' => 1, 'is_active' => false];
        $this->postJson(self::ENDPOINT, self::newProduct() + $protectedFields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($protectedFields), 'errors.fields');
        $this->patchJson($path, ['name' => 'Changed'] + $protectedFields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($protectedFields), 'errors.fields');

        self::assertSame('ORIGINAL', $product->refresh()->sku);
        self::assertSame(0, $product->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_metadata_and_stock_values_return_field_errors(): void
    {
        $this->authenticateAs('admin');
        $product = Product::factory()->create();

        $this->postJson(self::ENDPOINT, [
            'sku' => '-INVALID',
            'name' => str_repeat('x', 256),
            'price' => '10000000000.00',
            'tax_rate' => '100.01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'name', 'price', 'tax_rate'], 'errors.fields');
        $this->postJson(self::ENDPOINT, array_replace(self::newProduct(), [
            'price' => 25,
            'tax_rate' => '0.125',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'tax_rate'], 'errors.fields');
        $this->patchJson(self::ENDPOINT.'/'.$product->id.'/stock', [
            'stock_quantity' => 2147483648,
            'expected_stock_version' => -1,
            'reason' => str_repeat('x', 256),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('meta', null)
            ->assertJsonValidationErrors(['stock_quantity', 'expected_stock_version', 'reason'], 'errors.fields');
    }

    public function test_stale_stock_version_returns_conflict_and_unchanged_quantity_does_not_write(): void
    {
        $this->authenticateAs('admin');
        $product = Product::factory()->create(['stock_quantity' => 0]);
        $path = self::ENDPOINT.'/'.$product->id.'/stock';

        $this->patchJson($path, self::stockAdjustment(4, 0))->assertOk();
        $this->patchJson($path, self::stockAdjustment(5, 1))->assertOk();

        $this->patchJson($path, self::stockAdjustment(3, 1))
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonPath('meta', ['stock_version' => 2])
            ->assertJsonValidationErrors(['expected_stock_version'], 'errors.fields');

        $this->patchJson($path, self::stockAdjustment(5, 1))
            ->assertConflict()
            ->assertJsonPath('meta.stock_version', 2);

        $this->patchJson($path, self::stockAdjustment(5, 2))
            ->assertOk()
            ->assertJsonPath('data.product.stock_quantity', 5)
            ->assertJsonPath('data.product.stock_version', 2);

        $this->assertDatabaseCount('stock_movements', 2);
        self::assertSame(5, $product->refresh()->stock_quantity);
        self::assertSame(2, $product->stock_version);
    }

    public function test_stock_balance_and_version_roll_back_if_the_movement_cannot_be_saved(): void
    {
        $this->authenticateAs('admin');
        $product = Product::factory()->create(['stock_quantity' => 0]);
        StockMovement::saving(static fn (): bool => false);

        $this->patchJson(self::ENDPOINT.'/'.$product->id.'/stock', self::stockAdjustment(5, 0))
            ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        self::assertSame(0, $product->refresh()->stock_quantity);
        self::assertSame(0, $product->stock_version);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function authenticateAs(string $role): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->withToken($user->createToken('Product management test')->plainTextToken);

        return $user;
    }

    /** @return array{sku: string, name: string, price: string} */
    private static function newProduct(string $sku = 'HARBOR-99'): array
    {
        return ['sku' => $sku, 'name' => 'Harbor crate', 'price' => '25.00'];
    }

    /** @return array{stock_quantity: int, expected_stock_version: int, reason: string} */
    private static function stockAdjustment(int $quantity, int $expectedVersion): array
    {
        return [
            'stock_quantity' => $quantity,
            'expected_stock_version' => $expectedVersion,
            'reason' => 'Physical count',
        ];
    }
}
