<?php

namespace Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $this->assertInMemoryCache($app);

        $connection = $app->make(DatabaseManager::class)->connection();

        if (defined('OMS_MYSQL_TEST_CONTEXT')) {
            $this->assertDisposableMySqlConnection(
                $app,
                $connection,
                constant('OMS_MYSQL_TEST_CONTEXT'),
            );
        } elseif ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('The default test database must use SQLite :memory:.');
        }

        return $app;
    }

    private function assertInMemoryCache(Application $app): void
    {
        $cache = $app->make(CacheManager::class);
        $permissions = $app->make(PermissionRegistrar::class);

        if (! $cache->store()->getStore() instanceof ArrayStore || ! $permissions->getCacheStore() instanceof ArrayStore) {
            throw new RuntimeException('Tests must use in-memory array stores for the application and permission caches.');
        }
    }

    /**
     * @param  array{host: string, port: int, database: string, account: string, config_cache: string}  $context
     */
    private function assertDisposableMySqlConnection(
        Application $app,
        Connection $connection,
        array $context,
    ): void {
        if (
            ! $app->environment('testing')
            || $app->getCachedConfigPath() !== $app->basePath($context['config_cache'])
            || $app->configurationIsCached()
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== $context['database']
            || $connection->getConfig('host') !== $context['host']
            || ! in_array($connection->getConfig('port'), [$context['port'], (string) $context['port']], true)
            || $connection->getConfig('unix_socket') !== ''
            || $connection->getConfig('username') !== strstr($context['account'], '@', true)
        ) {
            throw new RuntimeException('The configured MySQL test connection does not match the disposable run.');
        }

        $identity = $connection->selectOne(
            'SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name',
            [],
            false,
        );

        if (
            ($identity->database_name ?? null) !== $context['database']
            || ($identity->account_name ?? null) !== $context['account']
        ) {
            throw new RuntimeException('The MySQL writer session does not match the disposable schema and account.');
        }
    }
}
