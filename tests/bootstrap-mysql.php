<?php

declare(strict_types=1);

$runId = getenv('OMS_MYSQL_RUN_ID');

if ($runId === false || preg_match('/\A[a-f0-9]{12}\z/', $runId) !== 1) {
    throw new RuntimeException('Start the MySQL regression profile with the disposable database launcher.');
}

$port = filter_var(getenv('DB_PORT'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65_535],
]);
$password = getenv('DB_PASSWORD');

if ($port === false || $password === false || $password === '') {
    throw new RuntimeException('The launcher must supply a valid MySQL port and test password.');
}

$database = 'omsauthverify'.$runId;
$username = 'omsauth'.$runId;
$host = '127.0.0.1';
$cachePath = 'bootstrap/cache/oms-mysql-auth-config-'.$runId.'.php';

$requiredEnvironment = [
    'APP_ENV' => 'testing',
    'APP_CONFIG_CACHE' => $cachePath,
    'DB_CONNECTION' => 'mysql',
    'DB_URL' => '',
    'DB_HOST' => $host,
    'DB_PORT' => (string) $port,
    'DB_SOCKET' => '',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $username,
    'DB_PASSWORD' => $password,
    'DB_CHARSET' => 'utf8mb4',
    'DB_COLLATION' => 'utf8mb4_unicode_ci',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
];

foreach ($requiredEnvironment as $key => $expected) {
    $actual = getenv($key);

    if ($actual !== $expected && ! ($expected === '' && $actual === false)) {
        throw new RuntimeException("Unexpected MySQL test setting [{$key}].");
    }
}

$absoluteCachePath = dirname(__DIR__).'/'.$cachePath;

if (file_exists($absoluteCachePath) || is_link($absoluteCachePath)) {
    throw new RuntimeException('The disposable MySQL run requires a fresh configuration cache path.');
}

$_SERVER = array_replace($_SERVER, $requiredEnvironment);
$_ENV = array_replace($_ENV, $requiredEnvironment);

require_once dirname(__DIR__).'/vendor/autoload.php';

define('OMS_MYSQL_TEST_CONTEXT', [
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'account' => $username.'@'.$host,
    'config_cache' => $cachePath,
]);
