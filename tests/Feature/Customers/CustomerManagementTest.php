<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Constants\ApiErrorCode;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/customers';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 20,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_create_update_and_deactivate_a_customer_with_audits(): void
    {
        $admin = $this->authenticateAs('admin');

        $this->postJson(self::ENDPOINT.'?is_active=0', [
            'name' => 'Harbor Retail',
            'email' => 'buyer@example.test',
            'phone' => '+15550101010',
            'address' => 'Dock 4',
        ])
            ->assertCreated()
            ->assertJsonPath('data.customer.name', 'Harbor Retail')
            ->assertJsonPath('data.customer.is_active', true)
            ->assertJsonPath('meta', null);

        $customer = Customer::query()->sole();
        $path = self::ENDPOINT.'/'.$customer->id;

        $this->patchJson($path.'?is_active=0', ['phone' => null])
            ->assertOk()
            ->assertJsonPath('data.customer.email', 'buyer@example.test')
            ->assertJsonPath('data.customer.phone', null)
            ->assertJsonPath('data.customer.is_active', true);

        $this->patchJson($path, ['phone' => null])->assertOk();

        $this->patchJson($path.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.customer.is_active', false);
        $this->patchJson($path.'/deactivate')->assertOk();

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.customer', [
                'id' => $customer->id,
                'name' => 'Harbor Retail',
                'email' => 'buyer@example.test',
                'phone' => null,
                'address' => 'Dock 4',
                'is_active' => false,
            ]);

        $creation = AuditLog::query()->where('action', 'customer.created')->sole();
        self::assertSame($admin->id, $creation->actor_id);
        self::assertSame('customer', $creation->target_type);
        self::assertSame($customer->id, $creation->target_id);
        self::assertNull($creation->before_values);
        self::assertSame(true, $creation->after_values['is_active']);

        $update = AuditLog::query()->where('action', 'customer.updated')->sole();
        self::assertSame(['phone' => '+15550101010'], $update->before_values);
        self::assertSame(['phone' => null], $update->after_values);

        $deactivation = AuditLog::query()->where('action', 'customer.deactivated')->sole();
        self::assertSame(['is_active' => true], $deactivation->before_values);
        self::assertSame(['is_active' => false], $deactivation->after_values);

        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_list_applies_literal_search_status_and_stable_pagination(): void
    {
        $this->authenticateAs('manager');
        Config::set([
            'pagination.customers.default_per_page' => 1,
            'pagination.customers.max_per_page' => 2,
        ]);

        $first = Customer::factory()->create(['name' => 'Harbor_One', 'email' => null, 'phone' => null]);
        $second = Customer::factory()->create(['name' => 'Harbor_Two', 'email' => null, 'phone' => null]);
        Customer::factory()->create(['name' => 'HarborXThree', 'email' => null, 'phone' => null]);
        $archived = Customer::factory()->inactive()->create([
            'name' => 'Former account',
            'email' => null,
            'phone' => 'Desk%100',
        ]);

        $this->getJson(self::ENDPOINT.'?search=Harbor%5F&is_active=1')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $first->id)
            ->assertJsonPath('meta', [
                'current_page' => 1,
                'per_page' => 1,
                'total' => 2,
                'last_page' => 2,
            ]);

        $this->getJson(self::ENDPOINT.'?search=Harbor%5F&is_active=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $second->id);

        $this->getJson(self::ENDPOINT.'?search=Desk%25&is_active=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.customers.0.id', $archived->id);

        $this->getJson(self::ENDPOINT.'?page=0&per_page=3&is_active=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page', 'per_page', 'is_active'], 'errors.fields');

        $this->getJson(self::ENDPOINT.'?is_active=01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active'], 'errors.fields');
    }

    public function test_customer_writes_validate_body_fields_and_ignore_query_inputs(): void
    {
        $this->authenticateAs('admin');
        $customer = Customer::factory()->create(['name' => 'Original name']);
        $path = self::ENDPOINT.'/'.$customer->id;

        $this->postJson(self::ENDPOINT.'?name=QueryOnly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->postJson(self::ENDPOINT, [
            'name' => '',
            'email' => 'invalid',
            'phone' => str_repeat('7', 33),
            'address' => str_repeat('x', 1001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'address'], 'errors.fields');

        $this->patchJson($path.'?name=QueryOnly')
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        $this->patchJson($path, ['name' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'], 'errors.fields');

        self::assertSame('Original name', $customer->refresh()->name);
        self::assertTrue($customer->is_active);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('statusValues')]
    public function test_customer_create_and_update_reject_status_in_the_body(?bool $requestedStatus, bool $currentStatus): void
    {
        $this->authenticateAs('admin');

        $this->postJson(self::ENDPOINT, ['name' => 'New customer', 'is_active' => $requestedStatus])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(['is_active'], 'errors.fields');

        $this->assertDatabaseCount('customers', 0);

        $customer = Customer::factory()->create(['name' => 'Original name', 'is_active' => $currentStatus]);

        $this->patchJson(self::ENDPOINT.'/'.$customer->id, ['name' => 'Unsaved name', 'is_active' => $requestedStatus])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(['is_active'], 'errors.fields');

        self::assertSame('Original name', $customer->refresh()->name);
        self::assertSame($currentStatus, $customer->is_active);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array<string, array{bool|null, bool}> */
    public static function statusValues(): array
    {
        return [
            'deactivation' => [false, true],
            'reactivation' => [true, false],
            'null' => [null, true],
        ];
    }

    public function test_manager_can_read_customers_but_cannot_write_even_with_a_manage_grant(): void
    {
        $manager = $this->authenticateAs('manager');
        $manager->givePermissionTo('customers.manage');

        $customer = Customer::factory()->create();
        $path = self::ENDPOINT.'/'.$customer->id;

        $this->getJson(self::ENDPOINT)->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('data.customer.id', $customer->id);

        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertForbidden();
        foreach ([$path, self::ENDPOINT.'/999999'] as $target) {
            $this->patchJson($target, ['name' => 'Unauthorized'])
                ->assertForbidden()
                ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);
            $this->patchJson($target.'/deactivate')->assertForbidden();
        }

        self::assertTrue($customer->refresh()->is_active);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_staff_cannot_list_customers_or_distinguish_missing_customer_ids(): void
    {
        $this->authenticateAs('staff');
        $customer = Customer::factory()->create();

        $this->getJson(self::ENDPOINT)->assertForbidden();
        foreach ([self::ENDPOINT.'/'.$customer->id, self::ENDPOINT.'/999999'] as $target) {
            $this->getJson($target)
                ->assertForbidden()
                ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);
            $this->patchJson($target, ['name' => 'Unauthorized'])->assertForbidden();
            $this->patchJson($target.'/deactivate')->assertForbidden();
        }
    }

    public function test_admin_without_customers_manage_cannot_write_customers(): void
    {
        Role::findByName('admin', 'web')->revokePermissionTo('customers.manage');
        $this->authenticateAs('admin');

        $this->postJson(self::ENDPOINT, ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_guest_requests_are_unauthenticated_and_admin_gets_not_found_for_a_missing_id(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);

        $this->authenticateAs('admin');
        $missing = self::ENDPOINT.'/999999';

        $this->getJson($missing)
            ->assertNotFound()
            ->assertJsonPath('errors.code', ApiErrorCode::RESOURCE_NOT_FOUND);
        $this->patchJson($missing, ['name' => 'Missing'])->assertNotFound();
        $this->patchJson($missing.'/deactivate')->assertNotFound();
    }

    public function test_customer_update_rolls_back_when_audit_save_fails(): void
    {
        $this->authenticateAs('admin');
        $customer = Customer::factory()->create(['name' => 'Original name']);
        AuditLog::saving(static fn (): bool => false);

        $this->patchJson(self::ENDPOINT.'/'.$customer->id, ['name' => 'Unsaved name'])
            ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJsonPath('errors.code', ApiErrorCode::INTERNAL_SERVER_ERROR);

        self::assertSame('Original name', $customer->refresh()->name);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function authenticateAs(string $role): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->withToken($user->createToken('Customer management test')->plainTextToken);

        return $user;
    }
}
