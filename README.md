# OMS Backend

Laravel 13 and MySQL API for the Order Management System. The companion
`oms-frontend` repository contains the Vue application. Web and mobile clients
can use the same Sanctum Bearer token API.

## Prerequisites

- PHP 8.3 or later with the Laravel extensions and `pdo_mysql`
- Composer 2 and ZIP support through the PHP `zip` extension or an archive tool
- MySQL running locally, for example through Laragon on port `3306`
- `pdo_sqlite` to run the automated tests

On Windows, use a PowerShell terminal with PHP and Composer on `PATH`. Verify
both with `php -v` and `composer --version`. Check that the CLI PHP has the
required extensions enabled.

## Local setup

### 1. Create the MySQL database

For a new local database, run the following as a MySQL administrator. Replace
`<local-password>` with a password for the development account.

```sql
CREATE DATABASE oms
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'oms'@'127.0.0.1' IDENTIFIED BY '<local-password>';

GRANT ALL PRIVILEGES ON oms.* TO 'oms'@'127.0.0.1';
```

### 2. Install dependencies and configure the environment

Run these commands from the repository root in PowerShell.

```powershell
composer install

if (-not (Test-Path -LiteralPath .env)) {
    Copy-Item -LiteralPath .env.example -Destination .env
}
```

Set the MySQL connection and local API URL in `.env`.

```dotenv
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=oms
DB_USERNAME=oms
DB_PASSWORD="<local-password>"
```

Generate the application key if `APP_KEY` is empty in a new `.env`.

```powershell
php artisan key:generate
```

Keep local credentials and `APP_KEY` in `.env`. Commit `.env.example` as the
template.

### 3. Configure Sanctum and the dummy password hash

`composer install` installs Sanctum from the lock file. The repository includes
Sanctum's published `personal_access_tokens` migration, which runs with the
application's other migrations.

After setting the application's password hash driver and work factor, generate a
dummy hash. Clear stale configuration first if it was cached locally.

```powershell
php artisan config:clear
php artisan tinker --execute='echo \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(48)), PHP_EOL;'
```

Copy the output into `AUTH_LOGIN_DUMMY_PASSWORD_HASH` in `.env`, enclosed in
single quotes to preserve any `$` characters. Generate a fresh dummy hash if the
hash driver or work factor changes. The login service uses this hash when an
email has no matching account so that failed logins still perform password
verification. The dummy hash must be configured before testing login.

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `AUTH_LOGIN_DUMMY_PASSWORD_HASH` | Empty | Dummy verification hash generated during setup |
| `AUTH_TIMEBOX_DURATION_MICROSECONDS` | `200000` | Minimum duration for failed credential verification, in microseconds |
| `AUTH_DEFAULT_TOKEN_NAME` | `OMS client` | Token label when the client omits `device_name` |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | `60` | Token lifetime in minutes |

`AUTH_TIMEBOX_DURATION_MICROSECONDS=200000` sets a 200-millisecond minimum for
failed credential verification. This helps reduce timing differences between
an unknown email and an incorrect password. If verification itself takes
longer, the request can exceed this minimum.

After updating environment values, clear cached configuration locally or
rebuild it on deployment. [Laravel configuration caching](https://laravel.com/docs/13.x/configuration#configuration-caching)

### 4. Apply migrations and start the API

```powershell
php artisan migrate
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8000
```

## API endpoints

Successful and failed JSON responses use the same envelope with `success`,
`message`, `data`, `errors`, and `meta`. On failure, use `errors.code` to decide
how the client should respond. Field validation messages appear in
`errors.fields`.

| Method | Endpoint | Authentication | Successful response |
| --- | --- | --- | --- |
| `GET` | `/api/health` | Public | `200` with `data.status` set to `ok` |
| `POST` | `/api/login` | Email and password in the JSON body | `200` with `data.access_token`, `data.expires_at`, and `data.user` |
| `GET` | `/api/me` | Bearer token | `200` with `data.user` |
| `POST` | `/api/logout` | Bearer token | `200` with a confirmation message and `data: null` |

Login accepts `email`, `password`, and optional `device_name`. The `user` object
contains `id`, `name`, and `email`. `expires_at` is a UTC ISO 8601 timestamp.
Use the issued token in the `Authorization: Bearer <token>` header. Logout
revokes the token used for that request. Other device tokens can remain active.
[Laravel Sanctum API tokens](https://laravel.com/docs/13.x/sanctum#api-token-authentication)

| Status | Error code | Meaning |
| --- | --- | --- |
| `401` | `AUTH_INVALID_CREDENTIALS` | Login credentials did not match |
| `401` | `AUTH_UNAUTHENTICATED` | A protected endpoint requires a valid Bearer token |
| `422` | `VALIDATION_FAILED` | The request contains invalid or missing fields |
| `429` | `RATE_LIMIT_EXCEEDED` | Wait for the interval in `Retry-After` before retrying |

Use HTTPS for deployed clients and keep passwords and Bearer tokens out of logs.

## Verify the authentication flow

Use an existing local development user, or create one from `php artisan tinker`.
Paste the following into Tinker once and save the generated password for this
local account.

```php
$password = \Illuminate\Support\Str::random(32);

\App\Models\User::query()->create([
    'name' => 'OMS Reviewer',
    'email' => 'reviewer@example.test',
    'password' => $password,
]);

echo $password, PHP_EOL;
```

With Laravel running, use a separate PowerShell terminal.

```powershell
$api = 'http://127.0.0.1:8000/api'

Invoke-RestMethod -Uri "$api/health"

$credential = Get-Credential `
    -UserName 'reviewer@example.test' `
    -Message 'Enter the password for the local OMS account.'

$login = Invoke-RestMethod `
    -Method Post `
    -Uri "$api/login" `
    -ContentType 'application/json' `
    -Headers @{ Accept = 'application/json' } `
    -Body (@{
        email = $credential.UserName
        password = $credential.GetNetworkCredential().Password
        device_name = 'PowerShell smoke test'
    } | ConvertTo-Json)

$headers = @{
    Accept = 'application/json'
    Authorization = "Bearer $($login.data.access_token)"
}

Invoke-RestMethod -Method Get -Uri "$api/me" -Headers $headers
Invoke-RestMethod -Method Post -Uri "$api/logout" -Headers $headers
```

Calling `/api/me` again with the same token should return `401`. With the
companion Vue dev server running, `http://127.0.0.1:5173/api/health` should return
the same health response through the Vite proxy.

## Rate limits

| Environment variable | Default | Scope |
| --- | ---: | --- |
| `RATE_LIMIT_API_PER_IP` | 120 per minute | Shared IP quota across registered API routes |
| `RATE_LIMIT_LOGIN_PER_IP` | 30 per minute | Additional login quota for the client IP |
| `RATE_LIMIT_LOGIN_PER_EMAIL` | 5 per minute | Additional login quota for the normalized email, across IPs |

Login checks all applicable quotas before password verification. Limiter keys
use HMAC-SHA-256 with `APP_KEY` for IP and email identifiers. Multiple API
instances must use the same `APP_KEY` and a shared limiter cache. Behind a
reverse proxy, configure trusted proxies so Laravel resolves the correct client
IP.

## Development checks

```powershell
php vendor/bin/pint --test
php artisan test
```

PHPUnit uses SQLite in memory. Use `php artisan migrate:status` to check the local
MySQL schema separately.
