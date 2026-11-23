<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\LoginEmail;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

final class CreateDemoUsersCommand extends Command
{
    protected $signature = 'oms:create-demo-users
        {--assign-existing : Assign roles to existing demo users who have no role}
        {--extended : Include two more accounts for the assessment scenario}';

    protected $description = 'Create local OMS demo users and assign their default roles';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is available only in the local environment.');

            return self::FAILURE;
        }

        $accounts = [
            ['role' => 'admin', 'name' => 'OMS Admin', 'email' => 'admin@oms.test'],
            ['role' => 'manager', 'name' => 'OMS Manager', 'email' => 'manager@oms.test'],
            ['role' => 'staff', 'name' => 'OMS Staff', 'email' => 'staff@oms.test'],
        ];
        if ($this->option('extended')) {
            $accounts[] = ['role' => 'manager', 'name' => 'OMS Review Manager', 'email' => 'manager2@oms.test'];
            $accounts[] = ['role' => 'staff', 'name' => 'OMS Review Staff', 'email' => 'staff2@oms.test'];
        }

        $assignExisting = (bool) $this->option('assign-existing');

        try {
            $rows = DB::transaction(static function () use ($accounts, $assignExisting): array {
                $guardName = Guard::getDefaultName(User::class);
                $roles = Role::query()
                    ->where('guard_name', $guardName)
                    ->whereIn('name', array_column($accounts, 'role'))
                    ->get()
                    ->keyBy('name');

                $rows = [];

                foreach ($accounts as ['role' => $roleName, 'name' => $name, 'email' => $email]) {
                    $role = $roles->get($roleName);

                    if (! $role instanceof Role || $role->guard_name !== $guardName) {
                        throw new DomainException("Run php artisan migrate to provision the {$roleName} role before creating demo users.");
                    }

                    $user = User::query()->where('email', $email)->lockForUpdate()->first();
                    $password = null;

                    if ($user === null) {
                        $password = Str::password(symbols: false);
                        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
                    } elseif (
                        LoginEmail::normalize($user->email) !== $email
                        || $user->getDirectPermissions()->isNotEmpty()
                    ) {
                        throw new DomainException("Existing account {$email} does not match the demo user requirements.");
                    }

                    if (! $user->hasExactRoles($role, $guardName)) {
                        if ($user->roles->isNotEmpty()) {
                            throw new DomainException("Existing account {$email} has conflicting role assignments.");
                        }

                        if ($password === null && ! $assignExisting) {
                            throw new DomainException("Existing account {$email} has no role. Use --assign-existing to assign it.");
                        }

                        $user->assignRole($role);
                    }

                    $rows[] = [$roleName, $email, $password ?? 'Use existing password'];
                }

                return $rows;
            });
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Role', 'Email', 'Password'], $rows);

        return self::SUCCESS;
    }
}
