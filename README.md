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

### 3. Configure Sanctum

`composer install` installs Sanctum from the lock file. The repository includes
Sanctum's published `personal_access_tokens` migration, which runs with the
application's other migrations.

| Environment variable | Default | Purpose |
| --- | --- | --- |
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
php artisan oms:create-demo-users --assign-existing
php artisan serve --host=127.0.0.1 --port=8000
```

Migrations install the permission catalog and any missing built-in roles with
their initial grants. Existing Manager and Staff selections are preserved.
The local demo command prints a generated password when it creates an account.
Existing demo accounts keep their passwords.

### 5. Seed the optional demo catalog

After applying the migrations to your local development database, run these
commands from the repository root. Use a second terminal if the API is running.

```powershell
php artisan db:seed --class=CustomerSeeder
php artisan db:seed --class=ProductSeeder
```

On a fresh database, the seeders add two demo customers and three demo products,
including active and inactive records. Rerunning them preserves records with
matching demo email addresses or SKUs, including current stock. New products
with positive opening stock receive a stock movement entry.
Rolling back the opening-stock backfill preserves the ledger entries it wrote.

Catalog writes make up to two transaction attempts on database contention by
default. Set `CATALOG_WRITE_ATTEMPTS` to an integer from 1 to 5 to change the
total attempts. Each attempt uses the database connection's lock wait timeout.
When attempts are exhausted, the API returns `503` with error
code `WRITE_CONTENTION` and a request ID in both `X-Request-ID` and
`meta.request_id`. The `Retry-After` header uses
`CATALOG_WRITE_RETRY_AFTER_SECONDS`, which defaults to one second and accepts
values from 1 to 60.

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
`GET /api/me` also returns assigned `roles` and effective `permissions`
inside `data.user`.
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

### Orders

| Method | Endpoint | Operation |
| --- | --- | --- |
| GET | /api/orders | List orders visible to the caller |
| POST | /api/orders | Create a draft order |
| POST | /api/orders/preview | Calculate pricing for a new or editable order |
| GET | /api/orders/assignees | List active employees for order assignment |
| GET | /api/orders/{order} | Read order details, notes, history, and system activity |
| PATCH | /api/orders/{order} | Edit a draft or pending order |
| DELETE | /api/orders/{order} | Soft-delete an eligible order |
| POST | /api/orders/{order}/status | Change status and record the transition |
| POST | /api/orders/{order}/assign | Assign or unassign the order |
| POST | /api/orders/{order}/notes | Append a note |

Use search to match order number, customer name or email, product name or SKU
as stored on the order, and assigned employee name. Combine it with status,
priority, customer_id, assignee_id, date_from, and date_to. Date bounds
are inclusive and can be supplied independently.

Pass sort as order_date, total, status, priority, or customer and direction
as asc or desc. Default sort is order_date desc, with order ID as a tie-breaker.
page and per_page control server-side pagination. The default page size is 25
and the default maximum is 100. Both are configurable.

Order writes accept JSON. Send an active customer ID, an order date, and a
non-empty list of distinct product IDs and positive quantities to create a
draft. Optional fields include priority, an active assignee, an initial
note, and fixed item and order discounts.

Send one `Idempotency-Key` header with each new `POST /api/orders` request.
Use a fresh value such as a UUID for each order and reuse the original key
and payload for retries. Keys are scoped to the authenticated user.

The first request and authorized replays return `201` with the same order ID
and `Location`. A replay returns the current order details and checks
current permissions. A changed valid payload with the same key returns
`409 ORDER_IDEMPOTENCY_CONFLICT`. A replay after soft deletion returns
`404`, and the key stays bound to the original order. A rolled-back attempt
leaves the key available for retry.

The server snapshots the product name, SKU, price, and applicable tax rate.
All pricing values use integer minor units in the configured two-decimal
business currency. For example, 2000 USD minor units represent $20.00.
Keep ORDERS_CURRENCY aligned with the Catalog currency.

PATCH accepts expected_version in its JSON body. DELETE accepts it in the
query string. A stale version returns 409 ORDER_VERSION_CONFLICT and the saved version in
meta.version. An edit preview requires the complete items array. Set order_id
to reuse existing snapshots and the saved order discount. Supply an explicit
order_discount_minor to preview a different discount.

Order status advances from Draft to Pending, Confirmed, Processing, Ready,
and Completed. Admin and Manager can cancel through Ready with the required
grants. Staff with assigned-status permission can move assigned orders from
Confirmed to Processing and from Processing to Ready. Completed and Cancelled
are terminal.

Send status, expected_version, and optional remarks as JSON to change the
status. Confirmation deducts stock once. Cancellation restores the actual
deduction, if one exists. Stock, history, and audit update in the same
transaction. Completion records completed_at for reporting.

Order create, edit, delete, status, assignment, and note writes use bounded
transaction retries. Exhausted lock contention returns
`503 WRITE_CONTENTION` with `Retry-After` and `X-Request-ID`. Configure the
attempt count with `ORDERS_WRITE_ATTEMPTS` and the retry interval with
`ORDERS_RETRY_AFTER_SECONDS`. Wait for the retry interval before resending the
request. For creation, reuse the original `Idempotency-Key`. For a versioned
write that returns `409 ORDER_VERSION_CONFLICT`, fetch the current order and
review the change before retrying.

Use `GET /api/orders/assignees` to populate the assignee selector. The
caller must be active and have `orders.view.any` and `orders.assign`.
The lookup lists active users by name ignoring case, then by ID. Each entry in
`data.assignees` contains only `id` and `name`.

Pass an optional `search` query parameter to match a literal substring
of the name, ignoring case. Leading and trailing whitespace is trimmed,
and a blank term is ignored. The maximum search length is 255 characters.
Use `page` and `per_page` to page through matches. The page size defaults
to 25 with a maximum of 100. Configure those limits with
`PAGINATION_ORDER_ASSIGNEES_DEFAULT_PER_PAGE` and
`PAGINATION_ORDER_ASSIGNEES_MAX_PER_PAGE`.

The lookup supports selection. Order creation and assignment revalidate
new assignees within the write transaction.

Assignment takes assignee_id and expected_version. Supply null to unassign.
Changes require an active assignee and an eligible order. Assignment history
shows the old and new assignee IDs, acting user, and time.

To append a note, send body as JSON. The server records the authenticated
author. Staff with assigned-note permission can add notes to their own
assigned orders. Notes are append-only and preserve the order version.

Order detail responses include `data.order.system_activity` for callers
who can view the order. Each entry provides `id`, `action`, `actor_id`,
`actor_name` when available, and the ISO 8601 `created_at` timestamp.
The two actions are `order.created` and `order.updated`. Entries appear
newest first, with higher IDs first when timestamps match. The default
limit is 50 entries. Set `ORDERS_SYSTEM_ACTIVITY_LIMIT` to a value from
1 to 200. Notes, status history, and assignment history are still
available in their respective fields.

### Admin user, role, and permission endpoints

| Method | Endpoint | Operation |
| --- | --- | --- |
| GET | /api/admin/users | List users |
| POST | /api/admin/users | Create a user |
| PATCH | /api/admin/users/{user} | Update a user's profile |
| PATCH | /api/admin/users/{user}/disable | Disable an account and revoke its tokens |
| PATCH | /api/admin/users/{user}/enable | Reactivate an account so the user can sign in again |
| PATCH | /api/admin/users/{user}/roles | Replace a user's assigned roles |
| GET | /api/admin/roles | List roles and their assigned permissions |
| POST | /api/admin/roles | Create a custom role |
| PATCH | /api/admin/roles/{role} | Rename a custom role |
| PATCH | /api/admin/roles/{role}/permissions | Update the checked permissions for a Manager, Staff, or custom role |
| GET | /api/admin/permissions | List permission names and the Admin-only subset |

These endpoints require an active Admin and a valid Bearer token. User
management requires `users.manage`. Role and permission management requires
`roles.manage`, and replacing a user's roles requires both grants.
The built-in roles keep their names. The built-in Admin role's grants are
protected.
The enable and disable requests take no body. On reactivation the user keeps
their roles and must sign in for a new Bearer token.

Use `GET /api/admin/permissions` for all checkbox options and match them
against each role's `permissions` array from `GET /api/admin/roles` to show
its checked values. Save the full checked list as `{"permissions":[...]}`.
Omitted grants are removed from that role, and an empty list clears all its
grants. The change applies to users assigned to the role and is audited.
The catalog's `data.admin_only_permissions` identifies restricted options.
Editable roles can retain or remove any existing grants in that subset.
Adding a new one returns `422`.

The [OpenAPI contract](docs/openapi.yaml) describes request bodies, pagination,
and the shared response schemas.

### Authentication trade-off

The original plan specified Sanctum session cookies and CSRF protection
for the Vue app. This PR uses Sanctum Bearer tokens so the Vue app and
future mobile clients can share the same login, logout, and user APIs.

The proposed Vue implementation will hold the access token in memory.
A full page reload requires signing in again. If a protected request
returns `401`, Vue will clear the token, send the user to the login
screen, and return them to the previous page after successful login.

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
composer check
```

The default test suite uses SQLite in memory. Run the MySQL regression
profile separately from the repository root.

```powershell
php tests/run-mysql.php
```

The launcher provisions a new disposable schema and a schema-specific account
on `127.0.0.1`, runs PHPUnit, and removes both after the run. The provisioning
connection defaults to port `3306`, user `root`, and an empty password. Override
these through the process environment variables `OMS_MYSQL_PORT`,
`OMS_MYSQL_ADMIN_USER`, and `OMS_MYSQL_ADMIN_PASSWORD`.
