<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/api/admin/users';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_filters_name_or_email_as_literal_text_before_paginating(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $first = User::factory()->create(['name' => 'Shift_A Evening', 'is_active' => false])->assignRole('staff');
        $second = User::factory()->create(['email' => 'shift_a@example.test', 'is_active' => false])->assignRole('staff');
        User::factory()->create(['name' => 'ShiftXA Morning', 'is_active' => false])->assignRole('staff');
        User::factory()->create(['name' => 'Shift_A Manager', 'is_active' => false])->assignRole('manager');
        User::factory()->create(['name' => 'Shift_A Active'])->assignRole('staff');

        $endpoint = self::ENDPOINT.'?search=Shift_A&is_active=0&role=staff&per_page=1';
        $this->withToken($admin->createToken('Directory test')->plainTextToken)
            ->getJson($endpoint.'&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $first->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson($endpoint.'&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $second->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_admin_can_load_a_disabled_users_account_without_sensitive_fields(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $staff = User::factory()->create(['is_active' => false])->assignRole('staff');

        $this->withToken($admin->createToken('Directory test')->plainTextToken)
            ->getJson(self::ENDPOINT.'/'.$staff->id)
            ->assertOk()
            ->assertJsonPath('data.user', [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'is_active' => false,
                'roles' => ['staff'],
            ]);

        $this->getJson(self::ENDPOINT.'/'.($staff->id + 100))->assertNotFound();
    }

    public function test_directory_reads_require_a_current_admin(): void
    {
        $manager = User::factory()->create()->assignRole('manager')->givePermissionTo('users.manage');
        $endpoint = self::ENDPOINT.'/'.$manager->id;

        $this->getJson($endpoint)->assertUnauthorized();

        Auth::forgetGuards();
        $this->withToken($manager->createToken('Directory test')->plainTextToken);
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->getJson($endpoint)->assertForbidden();
        $this->getJson(self::ENDPOINT.'/'.($manager->id + 100))->assertForbidden();
    }
}
