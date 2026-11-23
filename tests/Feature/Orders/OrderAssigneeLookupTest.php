<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Constants\ApiErrorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OrderAssigneeLookupTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/orders/assignees';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'rate_limits.api.per_ip' => 30,
        ]);
    }

    public function test_manager_can_search_active_accounts_without_access_to_admin_users(): void
    {
        $manager = User::factory()->create(['name' => 'Operations Lead'])->assignRole('manager');
        $customRole = Role::create(['name' => 'dispatch-coordinator', 'guard_name' => 'web']);
        $staff = User::factory()->create(['name' => 'Dispatch Avery'])->assignRole('staff', $customRole);
        $coordinator = User::factory()->create(['name' => 'Dispatch Cameron'])->assignRole($customRole);
        $unassigned = User::factory()->create(['name' => 'Dispatch Drew']);
        User::factory()->create(['name' => 'Dispatch Inactive', 'is_active' => false])->assignRole('staff');
        User::factory()->create(['name' => 'Outside Team', 'email' => 'dispatch@example.test']);

        $this->authenticate($manager);

        $this->getJson(self::ENDPOINT.'?search='.rawurlencode('  dIsPaTcH  '))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.assignees', [
                $this->assigneeData($staff, ['dispatch-coordinator', 'staff']),
                $this->assigneeData($coordinator, ['dispatch-coordinator']),
                $this->assigneeData($unassigned),
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', Config::integer('pagination.order_assignees.default_per_page'));

        $this->getJson('/api/admin/users')
            ->assertForbidden()
            ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);
    }

    public function test_search_treats_wildcards_and_escape_character_as_literal_text(): void
    {
        $this->authenticate(User::factory()->create()->assignRole('manager'));
        $match = User::factory()->create(['name' => 'Team RATE%_! Lead']);
        User::factory()->create(['name' => 'Team RATEXY! Lead']);

        $this->getJson(self::ENDPOINT.'?search='.rawurlencode('rate%_!'))
            ->assertOk()
            ->assertJsonPath('data.assignees', [$this->assigneeData($match)]);
    }

    public function test_lookup_pages_by_name_then_id_and_treats_blank_search_as_unfiltered(): void
    {
        Config::set([
            'pagination.order_assignees.default_per_page' => 1,
            'pagination.order_assignees.max_per_page' => 2,
        ]);

        $manager = User::factory()->create(['name' => 'Zulu Lead'])->assignRole('manager');
        $first = User::factory()->create(['name' => 'Same Name']);
        $second = User::factory()->create(['name' => 'Same Name']);
        $alpha = User::factory()->create(['name' => 'Alpha Porter']);

        $this->authenticate($manager);

        foreach ([$alpha, $first, $second] as $index => $assignee) {
            $page = $index + 1;

            $this->getJson(self::ENDPOINT.'?search=%20%20&page='.$page)
                ->assertOk()
                ->assertJsonPath('data.assignees', [$this->assigneeData($assignee)])
                ->assertJsonPath('meta', [
                    'current_page' => $page,
                    'per_page' => 1,
                    'total' => 4,
                    'last_page' => 4,
                ]);
        }

        $this->getJson(self::ENDPOINT.'?page=2&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.assignees.*.id', [$second->id, $manager->id])
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_lookup_validates_query_and_ignores_body_parameters(): void
    {
        Config::set('pagination.order_assignees.max_per_page', 2);
        Config::set('pagination.order_assignees.default_per_page', 1);
        $this->authenticate(User::factory()->create()->assignRole('manager'));
        $match = User::factory()->create(['name' => 'Query Target']);

        $query = http_build_query(['search' => str_repeat('x', 256), 'page' => 0, 'per_page' => 3]);
        $this->getJson(self::ENDPOINT.'?'.$query)
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ApiErrorCode::VALIDATION_FAILED)
            ->assertJsonValidationErrors(['search', 'page', 'per_page'], 'errors.fields');

        $query = http_build_query(['search' => ['Target']]);
        $this->getJson(self::ENDPOINT.'?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search', 'errors.fields');

        $this->json('GET', self::ENDPOINT.'?search=Query%20Target', [
            'search' => ['Invalid body input'],
            'page' => 0,
            'per_page' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.assignees', [$this->assigneeData($match)])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_default_admin_and_custom_roles_with_both_grants_can_use_lookup(): void
    {
        $customRole = Role::create(['name' => 'order-dispatcher', 'guard_name' => 'web']);
        $customRole->givePermissionTo('orders.view.any', 'orders.assign');

        foreach (['admin', $customRole] as $role) {
            $this->authenticate(User::factory()->create()->assignRole($role));

            $this->getJson(self::ENDPOINT.'?search=unmatched-employee-name')
                ->assertOk()
                ->assertJsonPath('data.assignees', [])
                ->assertJsonPath('meta.total', 0);
        }
    }

    public function test_lookup_requires_both_order_view_and_assignment_grants(): void
    {
        $staff = User::factory()->create()->assignRole('staff');
        $viewOnly = User::factory()->create()->givePermissionTo('orders.view.any');
        $assignOnly = User::factory()->create()->givePermissionTo('orders.assign');

        foreach ([$staff, $viewOnly, $assignOnly] as $actor) {
            $this->authenticate($actor);

            $this->getJson(self::ENDPOINT)
                ->assertForbidden()
                ->assertJsonPath('errors.code', ApiErrorCode::ACCESS_FORBIDDEN);
        }
    }

    public function test_guest_and_disabled_actor_cannot_use_lookup(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);

        $manager = User::factory()->create()->assignRole('manager');
        $this->authenticate($manager);
        $this->getJson(self::ENDPOINT)->assertOk();

        $manager->is_active = false;
        $manager->saveOrFail();
        Auth::forgetGuards();

        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertJsonPath('errors.code', ApiErrorCode::AUTH_UNAUTHENTICATED);
    }

    /**
     * @param  list<string>  $roles
     * @return array{id: int, name: string, email: string, roles: list<string>}
     */
    private function assigneeData(User $user, array $roles = []): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles,
        ];
    }

    private function authenticate(User $actor): void
    {
        Auth::forgetGuards();
        $this->withToken($actor->createToken('order-assignee-lookup')->plainTextToken);
    }
}
