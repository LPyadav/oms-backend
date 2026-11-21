<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
require_once $root.'/vendor/autoload.php';

$configuredPort = getenv('OMS_MYSQL_PORT');
$port = filter_var($configuredPort === false ? 3306 : $configuredPort, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65_535],
]);

if ($port === false) {
    fwrite(STDERR, "OMS_MYSQL_PORT must be a valid TCP port.\n");
    exit(1);
}

$host = '127.0.0.1';
$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
$adminPassword = getenv('OMS_MYSQL_ADMIN_PASSWORD');

try {
    $admin = new PDO(
        $dsn,
        getenv('OMS_MYSQL_ADMIN_USER') ?: 'root',
        $adminPassword === false ? '' : $adminPassword,
        $pdoOptions,
    );
    $serverPort = $admin->query('SELECT @@port');

    if ($serverPort === false || (int) $serverPort->fetchColumn() !== $port) {
        throw new RuntimeException('Unexpected local MySQL port.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Could not verify the local MySQL provisioning connection.\n");
    exit(1);
}

$runId = bin2hex(random_bytes(6));
$schema = 'omsauthverify'.$runId;
$username = 'omsauth'.$runId;
$account = "'{$username}'@'{$host}'";
$password = bin2hex(random_bytes(24));
$cachePath = 'bootstrap/cache/oms-mysql-auth-config-'.$runId.'.php';
$createdSchema = false;
$createdAccount = false;
$exitCode = 1;
$step = 'create the disposable schema';

try {
    $admin->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdSchema = true;

    $step = 'create the scoped test account';
    $admin->exec("CREATE USER {$account} IDENTIFIED BY '{$password}'");
    $createdAccount = true;
    $admin->exec("GRANT ALL PRIVILEGES ON {$schema}.* TO {$account}");

    $step = 'verify the disposable MySQL connection';
    $testConnection = new PDO($dsn.';dbname='.$schema, $username, $password, $pdoOptions);
    $identity = $testConnection->query('SELECT DATABASE() AS schema_name, CURRENT_USER() AS account_name');
    $session = $identity === false ? false : $identity->fetch(PDO::FETCH_ASSOC);

    if ($session === false || $session['schema_name'] !== $schema || $session['account_name'] !== $username.'@'.$host) {
        throw new RuntimeException('Unexpected disposable MySQL session.');
    }

    unset($identity, $testConnection);

    $step = 'run the regression profile';
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/phpunit', '--configuration', 'phpunit.mysql.xml'],
        $root,
        [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $cachePath,
            'BCRYPT_ROUNDS' => '4',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => $host,
            'DB_PORT' => (string) $port,
            'DB_SOCKET' => '',
            'DB_DATABASE' => $schema,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
            'DB_CHARSET' => 'utf8mb4',
            'DB_COLLATION' => 'utf8mb4_unicode_ci',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'LOG_CHANNEL' => 'null',
            'OMS_MYSQL_RUN_ID' => $runId,
            'OMS_MYSQL_ADMIN_USER' => false,
            'OMS_MYSQL_ADMIN_PASSWORD' => false,
        ],
        timeout: 120,
    );
    $exitCode = $process->run();
    fwrite(STDOUT, str_replace($password, '[redacted]', $process->getOutput()));
    fwrite(STDERR, str_replace($password, '[redacted]', $process->getErrorOutput()));
} catch (Throwable) {
    fwrite(STDERR, "Could not {$step}.\n");
} finally {
    if ($createdAccount) {
        try {
            $admin->exec("DROP USER {$account}");
        } catch (Throwable) {
            fwrite(STDERR, "Could not remove the disposable MySQL account {$account}.\n");
            $exitCode = 1;
        }
    }

    if ($createdSchema) {
        try {
            $admin->exec("DROP DATABASE {$schema}");
        } catch (Throwable) {
            fwrite(STDERR, "Could not remove the disposable MySQL schema {$schema}.\n");
            $exitCode = 1;
        }
    }
}

exit($exitCode);
