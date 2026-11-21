<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Users\UserStatusService;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_disabling_a_user_revokes_all_of_their_tokens_only(): void
    {
        $target = User::factory()->create()->assignRole('admin');
        $otherAdmin = User::factory()->create()->assignRole('admin');
        $desktop = $target->createToken('Desktop');
        $mobile = $target->createToken('Mobile');
        $otherToken = $otherAdmin->createToken('Other Admin device');

        $disabled = app(UserStatusService::class)->disable($otherAdmin, $target->id);

        self::assertFalse($disabled->is_active);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id, 'is_active' => true]);
        $this->assertModelMissing($desktop->accessToken);
        $this->assertModelMissing($mobile->accessToken);
        $this->assertModelExists($otherToken->accessToken);
    }

    public function test_disabled_actor_cannot_disable_a_user_with_stale_authorization(): void
    {
        $actor = User::factory()->create()->assignRole('admin')->refresh();
        $otherAdmin = User::factory()->create()->assignRole('admin');
        $target = User::factory()->create();
        $token = $target->createToken('Target device');

        $policy = app(UserPolicy::class);
        self::assertTrue($policy->changeStatus($actor));

        $service = app(UserStatusService::class);
        $disabledActor = $service->disable($otherAdmin, $actor->id);
        self::assertFalse($disabledActor->is_active);
        self::assertTrue($actor->is_active);

        try {
            $service->disable($actor, $target->id);
            self::fail('Expected the service to deny a disabled actor.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
            $this->assertModelExists($token->accessToken);
        }
    }

    public function test_last_active_admin_cannot_be_disabled(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $inactiveAdmin = User::factory()->create()->assignRole('admin');
        $inactiveAdmin->is_active = false;
        $inactiveAdmin->saveOrFail();
        $token = $admin->createToken('Admin device');

        try {
            app(UserStatusService::class)->disable($admin, $admin->id);
            self::fail('Expected the last active Admin to be protected.');
        } catch (DomainException $exception) {
            self::assertSame(
                'The last active Admin cannot be disabled.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
        $this->assertModelExists($token->accessToken);
    }
}
