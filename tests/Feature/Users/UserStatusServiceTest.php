<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Services\Users\UserStatusService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UserStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', Guard::getDefaultName(User::class));
    }

    public function test_disabling_a_user_revokes_all_of_their_tokens_only(): void
    {
        $target = User::factory()->create()->assignRole('admin');
        $otherAdmin = User::factory()->create()->assignRole('admin');
        $desktop = $target->createToken('Desktop');
        $mobile = $target->createToken('Mobile');
        $otherToken = $otherAdmin->createToken('Other Admin device');

        $disabled = app(UserStatusService::class)->disable($target);

        self::assertFalse($disabled->is_active);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id, 'is_active' => true]);
        $this->assertModelMissing($desktop->accessToken);
        $this->assertModelMissing($mobile->accessToken);
        $this->assertModelExists($otherToken->accessToken);
    }

    public function test_last_active_admin_cannot_be_disabled(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $inactiveAdmin = User::factory()->create()->assignRole('admin');
        $inactiveAdmin->is_active = false;
        $inactiveAdmin->saveOrFail();
        $token = $admin->createToken('Admin device');

        try {
            app(UserStatusService::class)->disable($admin);
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
