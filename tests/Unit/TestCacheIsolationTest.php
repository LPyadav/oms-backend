<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TestCacheIsolationTest extends TestCase
{
    public function test_default_test_profile_overrides_an_inherited_cache_store(): void
    {
        $bootstrap = tempnam(sys_get_temp_dir(), 'oms-test-cache-');
        self::assertNotFalse($bootstrap);

        try {
            self::assertNotFalse(file_put_contents($bootstrap, <<<'PHP'
                <?php

                use Illuminate\Foundation\Bootstrap\LoadConfiguration;
                use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

                require 'vendor/autoload.php';

                $app = require 'bootstrap/app.php';
                (new LoadEnvironmentVariables)->bootstrap($app);
                (new LoadConfiguration)->bootstrap($app);

                echo json_encode([
                    'process' => getenv('CACHE_STORE'),
                    'server' => $_SERVER['CACHE_STORE'] ?? null,
                    'config' => $app->make('config')->get('cache.default'),
                ], JSON_THROW_ON_ERROR);

                exit(0);
                PHP));

            $probe = new Process(
                [PHP_BINARY, 'vendor/bin/phpunit', '--configuration', 'phpunit.xml', '--bootstrap', $bootstrap, '--list-tests'],
                dirname(__DIR__, 2),
                ['CACHE_STORE' => 'file'],
            );
            $probe->mustRun();

            self::assertSame([
                'process' => 'array',
                'server' => 'array',
                'config' => 'array',
            ], json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            unlink($bootstrap);
        }
    }
}
