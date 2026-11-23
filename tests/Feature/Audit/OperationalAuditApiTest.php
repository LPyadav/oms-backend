<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OperationalAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/admin/audit-logs';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 20,
        ]);
    }

    public function test_audit_reads_require_an_authenticated_admin_with_the_audit_permission(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson(self::ENDPOINT.'/999999')->assertUnauthorized();

        $manager = User::factory()->create()->assignRole('manager')->givePermissionTo('audit.view');
        $this->authenticate($manager);
        $this->getJson(self::ENDPOINT.'?per_page=0')->assertForbidden();
        $this->getJson(self::ENDPOINT.'/999999')->assertForbidden();

        Role::findByName('admin', 'web')->revokePermissionTo('audit.view');
        $this->authenticate(User::factory()->create()->assignRole('admin'));
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->getJson(self::ENDPOINT.'/999999')->assertForbidden();
    }

    public function test_audit_logs_filter_and_page_and_return_only_allowed_detail_fields(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->authenticate($admin);

        $earlier = $this->log($admin, 'customer', '2026-11-22 23:59:59');
        $first = $this->log($admin, 'customer', '2026-11-23 23:59:59');
        $latest = $this->log($admin, 'customer', '2026-11-23 23:59:59');
        $unrelated = $this->log($admin, 'role', '2026-11-23 23:59:59');
        $this->log(User::factory()->create(), 'customer', '2026-11-23 09:00:00');

        $filters = http_build_query([
            'actor_id' => $admin->id,
            'target_type' => 'customer',
            'target_id' => 42,
            'action' => 'customer.updated',
            'date_from' => '2026-11-23',
            'date_to' => '2026-11-23',
            'per_page' => 1,
        ]);
        $this->getJson(self::ENDPOINT.'?'.$filters)
            ->assertOk()
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonPath('data.logs.0.id', $latest->id)
            ->assertJsonPath('data.logs.0.actor_name', $admin->name)
            ->assertJsonMissingPath('data.logs.0.before_values')
            ->assertJsonMissingPath('data.logs.0.after_values')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson(self::ENDPOINT.'?'.$filters.'&page=2')
            ->assertOk()
            ->assertJsonPath('data.logs.0.id', $first->id)
            ->assertJsonPath('meta.total', 2);

        $this->getJson(self::ENDPOINT.'?date_to=2026-11-22')
            ->assertOk()
            ->assertJsonPath('data.logs.0.id', $earlier->id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson(self::ENDPOINT.'/'.$latest->id)
            ->assertOk()
            ->assertJsonPath('data.log.before_values.name', 'Previous name')
            ->assertJsonPath('data.log.after_values.name', 'New name')
            ->assertJsonPath('data.log.before_values.email', '[redacted]')
            ->assertJsonPath('data.log.after_values.email', '[redacted]')
            ->assertJsonMissingPath('data.log.before_values.api_token')
            ->assertJsonMissingPath('data.log.after_values.password')
            ->assertDontSee('not-for-output');

        $this->getJson(self::ENDPOINT.'/'.$unrelated->id)->assertNotFound();
        $this->getJson(self::ENDPOINT.'?date_from=2026-11-23&date_to=2026-11-22&per_page=101&target_type=role')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['fields' => ['date_to', 'per_page', 'target_type']]]);
    }

    public function test_stock_adjustment_records_before_and_after_once_and_hides_the_reason(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->authenticate($admin);
        $product = Product::factory()->create(['stock_quantity' => 0, 'stock_version' => 0]);
        $path = '/api/products/'.$product->id.'/stock';

        $this->patchJson($path, [
            'stock_quantity' => 5,
            'expected_stock_version' => 0,
            'reason' => 'not-for-output',
        ])->assertOk();

        $this->patchJson($path, [
            'stock_quantity' => 5,
            'expected_stock_version' => 1,
            'reason' => 'Unchanged stock',
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'product.stock_adjusted')->sole();
        self::assertSame($admin->id, $log->actor_id);

        $this->getJson(self::ENDPOINT.'/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.log.target_id', $product->id)
            ->assertJsonPath('data.log.before_values.stock_quantity', 0)
            ->assertJsonPath('data.log.after_values.stock_quantity', 5)
            ->assertJsonPath('data.log.after_values.stock_version', 1)
            ->assertDontSee('not-for-output');
    }

    public function test_operational_audit_pagination_uses_configured_page_sizes(): void
    {
        Config::set([
            'pagination.operational_audit_logs.default_per_page' => 2,
            'pagination.operational_audit_logs.max_per_page' => 3,
        ]);

        $this->authenticate(User::factory()->create()->assignRole('admin'));

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2);

        $this->getJson(self::ENDPOINT.'?per_page=3')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 3);

        $this->getJson(self::ENDPOINT.'?per_page=4')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page', 'errors.fields');
    }

    private function authenticate(User $user): void
    {
        Auth::forgetGuards();
        $this->withToken($user->createToken('operational-audit-test')->plainTextToken);
    }

    private function log(User $actor, string $type, string $occurredAt): AuditLog
    {
        $record = new AuditLog([
            'actor_id' => $actor->id,
            'target_type' => $type,
            'target_id' => 42,
            'action' => $type.'.updated',
            'before_values' => ['name' => 'Previous name', 'email' => 'old@example.test', 'api_token' => 'not-for-output'],
            'after_values' => ['name' => 'New name', 'email' => 'new@example.test', 'password' => 'not-for-output'],
        ]);
        $record->forceFill(['created_at' => $occurredAt])->saveOrFail();

        return $record;
    }
}
