<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AccessAuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class AccessAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/admin/access-audit-logs';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_filter_audit_and_read_direct_grant_replacement_and_clear_snapshots(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $staff = User::factory()->create()->assignRole('staff');
        $permissions = '/api/admin/users/'.$staff->id.'/permissions';
        $this->withToken($admin->createToken('Audit test')->plainTextToken);

        $this->patchJson($permissions, ['direct_permissions' => ['products.view']])->assertOk();
        $this->patchJson($permissions, ['direct_permissions' => []])->assertOk();
        $this->postJson('/api/admin/roles', ['name' => 'Review helper'])->assertCreated();

        $query = http_build_query([
            'action' => 'user.permissions.replaced',
            'actor_id' => $admin->id,
            'target_type' => 'user',
            'target_id' => $staff->id,
            'per_page' => 1,
        ]);
        $clear = AccessAuditLog::query()->where('target_type', 'user')->latest('id')->firstOrFail();

        $list = $this->getJson(self::ENDPOINT.'?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.logs.0.id', $clear->id)
            ->assertJsonPath('data.logs.0.actor_id', $admin->id)
            ->assertJsonPath('data.logs.0.actor_email', $admin->email)
            ->assertJsonPath('data.logs.0.target_type', 'user')
            ->assertJsonPath('data.logs.0.target_id', $staff->id)
            ->assertJsonPath('data.logs.0.action', 'user.permissions.replaced')
            ->assertJsonPath('data.logs.0.created_at', $clear->created_at->toISOString())
            ->assertJsonPath('data.logs.0.before_values.direct_permissions', ['products.view'])
            ->assertJsonPath('data.logs.0.after_values.direct_permissions', []);
        self::assertStringContainsString('"after_values":{"direct_permissions":[]}', (string) $list->getContent());

        $this->getJson(self::ENDPOINT.'?'.$query.'&page=2')
            ->assertOk()
            ->assertJsonPath('data.logs.0.before_values.direct_permissions', [])
            ->assertJsonPath('data.logs.0.after_values.direct_permissions', ['products.view']);

        $this->getJson(self::ENDPOINT.'/'.$clear->id)
            ->assertOk()
            ->assertJsonPath('data.log.id', $clear->id)
            ->assertJsonPath('data.log.actor_email', $admin->email)
            ->assertJsonPath('data.log.before_values.direct_permissions', ['products.view'])
            ->assertJsonPath('data.log.after_values.direct_permissions', []);

        $this->getJson(self::ENDPOINT.'?target_type=role')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.logs.0.before_values', null)
            ->assertJsonPath('data.logs.0.after_values.name', 'Review helper');

        $this->getJson(self::ENDPOINT.'/'.($clear->id + 100))->assertNotFound();
    }

    public function test_access_audit_keeps_the_log_when_its_actor_is_deleted(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $actor = User::factory()->create();
        $log = AccessAuditLog::query()->create([
            'actor_id' => $actor->id,
            'target_type' => 'role',
            'target_id' => 42,
            'action' => 'role.created',
            'before_values' => null,
            'after_values' => ['name' => 'Review helper'],
        ]);
        $actor->delete();
        $this->withToken($admin->createToken('Audit test')->plainTextToken);

        $this->getJson(self::ENDPOINT.'?actor_id='.$actor->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonStructure(['data' => ['logs' => [0 => ['actor_email']]]])
            ->assertJsonPath('data.logs.0.actor_id', $actor->id)
            ->assertJsonPath('data.logs.0.actor_email', null);

        $this->getJson(self::ENDPOINT.'/'.$log->id)
            ->assertOk()
            ->assertJsonStructure(['data' => ['log' => ['actor_email']]])
            ->assertJsonPath('data.log.actor_id', $actor->id)
            ->assertJsonPath('data.log.actor_email', null);
    }

    public function test_audit_reads_deny_guests_and_non_admins_even_with_the_audit_grant(): void
    {
        $endpoint = self::ENDPOINT.'/1';
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->getJson($endpoint)->assertUnauthorized();

        Auth::forgetGuards();
        $manager = User::factory()->create()->assignRole('manager')->givePermissionTo('audit.view');
        $this->withToken($manager->createToken('Audit test')->plainTextToken);
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->getJson($endpoint)->assertForbidden();
    }

    public function test_invalid_audit_filters_are_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->withToken($admin->createToken('Audit test')->plainTextToken)
            ->getJson(self::ENDPOINT.'?per_page=101&actor_id=0&target_type=product&target_id=-1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'actor_id', 'target_type', 'target_id'], 'errors.fields');
    }

    public function test_access_audit_pagination_uses_configured_page_sizes(): void
    {
        Config::set([
            'pagination.access_audit_logs.default_per_page' => 2,
            'pagination.access_audit_logs.max_per_page' => 3,
        ]);

        $admin = User::factory()->create()->assignRole('admin');
        $this->withToken($admin->createToken('Audit test')->plainTextToken);

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
}
