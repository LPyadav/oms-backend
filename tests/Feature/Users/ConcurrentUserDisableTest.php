<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Services\Users\UserStatusService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConcurrentUserDisableTest extends TestCase
{
    private const string PASSWORD = 'concurrent-login-fixture-password';

    public function test_login_waits_for_disable_and_rejects_after_commit(): void
    {
        $database = DB::connection();

        if ($database->getDriverName() !== 'mysql') {
            self::markTestSkipped('This regression requires MySQL.');
        }

        $session = $database->selectOne(
            'SELECT DATABASE() AS schema_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id',
            [],
            false,
        );
        $schema = (string) $session->schema_name;

        if (preg_match('/\Aomsauthverify(?:[a-f0-9]{12})?\z/', $schema) !== 1) {
            self::markTestSkipped('Use the disposable omsauthverify MySQL schema.');
        }

        self::assertSame(0, $database->transactionLevel());
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $adminRole = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => Guard::getDefaultName(User::class),
        ]);
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $login = new Process(
            [PHP_BINARY, '-r', <<<'PHP'
                require 'vendor/autoload.php';

                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $database = $app->make('db')->connection();
                $session = $database->selectOne(
                    'SELECT DATABASE() AS schema_name, CURRENT_USER() AS account_name, CONNECTION_ID() AS connection_id',
                    [],
                    false,
                );

                if ($session->schema_name !== getenv('OMS_TEST_SCHEMA')
                    || $session->account_name !== getenv('OMS_TEST_ACCOUNT')) {
                    exit(2);
                }

                echo $session->connection_id, PHP_EOL;
                fflush(STDOUT);

                try {
                    $app->make(App\Services\Auth\AuthenticationService::class)->login([
                        'email' => (string) getenv('OMS_TEST_EMAIL'),
                        'password' => (string) getenv('OMS_TEST_PASSWORD'),
                    ]);
                } catch (App\Exceptions\Auth\InvalidCredentialsException) {
                    echo 'AUTH_INVALID_CREDENTIALS', PHP_EOL;
                    exit(0);
                }

                exit(1);
                PHP],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_CONFIG_CACHE' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-test-'.Str::uuid().'.php',
                'DB_CONNECTION' => 'mysql',
                'DB_URL' => '',
                'DB_HOST' => (string) $database->getConfig('host'),
                'DB_PORT' => (string) $database->getConfig('port'),
                'DB_SOCKET' => (string) $database->getConfig('unix_socket'),
                'DB_DATABASE' => $schema,
                'DB_USERNAME' => (string) $database->getConfig('username'),
                'DB_PASSWORD' => (string) $database->getConfig('password'),
                'LOG_CHANNEL' => 'null',
                'OMS_TEST_SCHEMA' => $schema,
                'OMS_TEST_ACCOUNT' => (string) $session->account_name,
                'OMS_TEST_EMAIL' => $user->email,
                'OMS_TEST_PASSWORD' => self::PASSWORD,
            ],
            timeout: 20,
        );

        try {
            $token = $user->createToken('Existing device');
            $database->beginTransaction();
            app(UserStatusService::class)->disable($user);

            $login->start();
            $this->waitForLoginRowLock($login, $database, $schema, (int) $session->connection_id);

            $database->commit();

            self::assertSame(0, $login->wait());
            self::assertStringContainsString('AUTH_INVALID_CREDENTIALS', $login->getOutput());
            $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
            $this->assertModelMissing($token->accessToken);
            self::assertFalse($user->tokens()->exists());
        } finally {
            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }

            if ($login->isRunning()) {
                $login->stop(0);
            }

            $user->tokens()->delete();
            $user->delete();

            if ($adminRole->wasRecentlyCreated) {
                $adminRole->delete();
            }
        }
    }

    private function waitForLoginRowLock(
        Process $login,
        Connection $database,
        string $schema,
        int $parentId,
    ): void {
        $deadline = hrtime(true) + 10_000_000_000;

        do {
            $login->checkTimeout();

            if (preg_match('/\A(\d+)\R/', $login->getOutput(), $matches) === 1) {
                $childId = (int) $matches[1];

                foreach ($database->select('SHOW FULL PROCESSLIST', [], false) as $thread) {
                    $sql = strtolower((string) $thread->Info);

                    if ((int) $thread->Id === $childId
                        && $thread->db === $schema
                        && str_contains($sql, 'users')
                        && str_contains($sql, 'for update')) {
                        self::assertNotSame($parentId, $childId);

                        return;
                    }
                }
            }

            if (! $login->isRunning()) {
                self::fail('Login exited before its row-lock query.');
            }

            usleep(20_000);
        } while (hrtime(true) < $deadline);

        self::fail('The login row-lock query did not appear before the timeout.');
    }
}
