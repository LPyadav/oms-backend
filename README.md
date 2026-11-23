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

### Assessment demo dataset

Use a fresh local MySQL database. These commands create five review accounts
across Admin, Manager and Staff, plus 100 customers, 50 products, and 504 orders
with mixed statuses, priorities, taxes, discounts, assignment, notes and
history. The seeder uses the domain services so stock and audit records follow
the same rules as interactive orders. The demo account command prints generated
passwords in your own terminal for newly created accounts.

```powershell
php artisan migrate
php artisan oms:create-demo-users --extended
php artisan db:seed --class=AssessmentDemoSeeder
php artisan serve --host=127.0.0.1 --port=8000
```

Run `php artisan queue:work --queue=notifications,default` and
`php artisan schedule:work` in separate terminals for notification delivery.

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

Use search to match order number, customer name, product name or SKU as stored on
the order, and assigned employee name. Customer email is searched only within
the caller’s customer contact scope. Combine search with status, priority,
customer_id, assignee_id, date_from, and date_to. Date bounds are inclusive and
can be supplied independently.

Order responses include customer email, phone, and address when the caller has
`customers.view`, or when the caller has `customers.view.assigned-orders` and
is the current assignee. For other visible orders, those fields are `null`.

For exact lookup, explicitly send `order_number` or `sku`. The `search`
parameter keeps its literal substring behavior for every term. Exact
filters trim surrounding whitespace and use equality under the stored
column collation. The limits are 40 characters for `order_number` and 64 for
`sku`. Blank, array and over-length values return `422 VALIDATION_FAILED`.

The `sku` filter matches item SKU snapshots. An order with multiple
matching items contributes one result to the page and one to the total.
When combined with `search` or other filters, every criterion must match
within the caller’s order visibility scope. Sorting and pagination apply
to that filtered set.

```http
GET /api/orders?order_number=ORD-01KABCDEF23456789GHJKMNPQR
GET /api/orders?sku=ABC-42&status=confirmed
GET /api/orders?sku=ABC-42&search=fastener
```

Pass sort as order_date, total, status, priority, or customer and direction
as asc or desc. Default sort is order_date desc, with order ID as a tie-breaker.
page and per_page control server-side pagination. The default page size is 25
and the default maximum is 100. Both are configurable.

Numbered pages use an offset over the current visible results. The order ID
tie-breaker gives a stable sequence while those results stay the same. An
insert ahead of a page boundary between requests can move the last row of
one page onto the next page. Refresh from page 1 to reload the current
sequence and total.

Customer sort and order-date pages after page 1 select page keys before
loading full rows. The query still advances through the skipped keys and
calculates an exact total for the current filters.

Local MySQL 8.2.0 benchmarks used one sequential client with a 128 MiB
InnoDB buffer pool. In the 50,000-order date-sort fixture without item rows,
Manager page 400 at 100 orders per page improved from 400.8 to 46.7 ms p50
and from 433.2 to 91.3 ms p95 across 10 samples per query. These timings
cover the page SELECT. Its plan scanned 40,000 index entries and loaded
100 full order rows.

An earlier HTTP benchmark with 50,000 orders and 250,000 item snapshots
recorded 5.30 s p50 and 35.85 s p95 for a no-match substring across 12
local requests. A separate paired listing-service run on MySQL 8.2.0
used the same fixture size and five timed samples per version. No-match
latency moved from 5.50 s p50 and 8.83 s p95 to 2.08 s p50 and
4.17 s p95. A rare item-snapshot match moved from 36.89 s to 8.85 s
p50 across three timed samples, with 25.58 s as the slowest candidate
sample. The paired timings cover the listing service and database.

Substring search uses independent customer, item and assignee ID queries
so the database can reuse each set of matches. Broad substrings still
scan searchable data. For a known identifier, use the indexed
`order_number` or `sku` filter.

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

The optional `order_tax_rate_basis_points` field sets one rate for the whole
order. For example, `1000` means 10%. The server applies both discounts,
calculates tax on the remaining order amount, rounds half-up once, and
allocates the rounded amount across the items. Ties use product ID. The
response includes the current order rate and each item's applied rate and
tax amount. Their tax amounts sum to `tax_total_minor`.

Create and new-order preview require `orders.update` for a numeric override.
Draft and pending orders can update the rate by sending it with
`expected_version`. The saved items are used when `items` is omitted.
Omit the rate to keep the saved tax mode on edit and existing-order preview.
An explicit `0` produces zero tax. An explicit `null` restores the saved item
rates. New orders use catalog item rates when the order rate is omitted.
Send legacy `items.*.tax_rate_basis_points` only when using item-based tax
and omitting the order rate from the request.

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
The lookup lists active users by name ignoring case, then by ID. Each entry
includes `id`, `name`, `email`, and the sorted names of assigned roles.
Users without roles have an empty role list.

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

Order detail and full order write responses include the current assignee email
and roles for callers with both `orders.view.any` and `orders.assign`.
Other viewers and order lists receive the assignee ID and name. An unassigned
order has `assignee: null`.

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

### Order notifications

The current assignee receives an in-app notification when an order is assigned
to them, confirmed, or cancelled. An initial assignment on order creation also
counts. The assignee must be active and still have access to the assigned order
at delivery and when reading notifications.

All notification endpoints require a Sanctum Bearer token.

| Method | Endpoint | Operation |
| --- | --- | --- |
| GET | /api/notifications | List your notifications, newest first |
| GET | /api/notifications/unread-count | Get your current unread count |
| PATCH | /api/notifications/{notification}/read | Mark one notification as read |
| PATCH | /api/notifications/read-all | Mark all currently visible unread notifications as read |

The list supports `status=read` or `status=unread` and the `page` and `per_page`
parameters. It defaults to 25 results per page with a maximum of 100. Configure
those limits with `PAGINATION_NOTIFICATIONS_DEFAULT_PER_PAGE` and
`PAGINATION_NOTIFICATIONS_MAX_PER_PAGE`. Each entry includes its event type,
order ID, order number, creation time, and nullable read time. Repeating a read
request preserves the original read time.

Run migrations, the queue worker, and the scheduler for local delivery with the
default `QUEUE_CONNECTION=database`. Run the worker and scheduler in separate
terminals.

```sh
php artisan migrate
php artisan queue:work --queue=notifications,default
php artisan schedule:work
```

An order write records the notification event in the same transaction and queues
delivery after commit. The scheduler scans for due events every minute so a
failed dispatch or worker can be retried. To run a recovery pass manually, use
`php artisan oms:notifications:recover`. Retries preserve one notification per
event and recipient.

Set `ORDER_NOTIFICATIONS_QUEUE` to change the queue name and update the worker's
queue list accordingly. The recovery delay and batch size default to 60 seconds
and 100 events. Job delivery defaults to 3 attempts with a 10-second backoff.
All four settings are listed in `.env.example`.

### Dashboard

| Method | Endpoint | Operation |
| --- | --- | --- |
| GET | /api/dashboard | Retrieve all-time order metrics and recent orders |
| GET | /api/dashboard/revenue-trend | Retrieve completed-order revenue by day, week, or month |

Send a Sanctum Bearer token with `dashboard.view` and `orders.view.any`.
These are default Admin and Manager grants. The customer and product totals
also require `customers.view` and `products.view`, respectively. A hidden
count is `null` and an authorized empty count is zero. Catalog counts include
active and inactive records. Recent orders contain order summary fields and
sort by order date descending, then ID descending.

The overview counts every non-deleted order and includes all seven order
statuses. Revenue and average order value use stored totals including tax for
non-deleted Completed orders with a completion timestamp. Financial metrics
are calculated separately for each stored currency. The overview returns
`total_revenue_minor` as an exact decimal string in minor units, with `"0"` for
zero revenue. Average order value rounds half up from the exact total to
the nearest minor unit and is returned as an integer. Recent order amounts
are also integers in minor units.

The revenue trend accepts `group_by=day|week|month` and an optional pair
of inclusive `date_from` and `date_to` dates in `YYYY-MM-DD` format. By
default it returns daily points for the last 30 calendar days including
today. Each order contributes to the period containing the local date of
its `completed_at` in `DASHBOARD_TIMEZONE`, which defaults to UTC.
Weeks start on Monday. Response dates and bucket labels preserve the
requested calendar range, including any date skipped in the reporting
timezone. A daily bucket for a skipped date has zero orders and revenue.
Every bucket returns `revenue_minor` as an exact decimal string in minor
units. Empty buckets return `"0"` and zero completed orders. An empty result
uses the configured Orders currency.

Set `DASHBOARD_RECENT_ORDERS_LIMIT` to change the overview recent-order
count. Set `DASHBOARD_REVENUE_DEFAULT_DAYS` and
`DASHBOARD_REVENUE_MAX_DAYS` to configure the date window. The maximum
is capped at 366 days.

### Order and fulfillment report

Send `GET /api/reports/orders` with a Sanctum Bearer token that has
`reports.view` and `orders.view.any`. Admins and Managers have both
grants by default. Each row includes the order number, business order date,
priority, status, customer and assignee ID and name, currency, and stored
total. Use the row's ID with `GET /api/orders/{order}` for details.

Pass `date_from` and `date_to` together in `YYYY-MM-DD` format. Both
endpoints of that range are inclusive for `order_date`. An omitted pair
defaults to the last 30 calendar days, including today in
`DASHBOARD_TIMEZONE`. Skipped local dates still count toward the span.
Optional filters are `status`, `customer_id`, and `assignee_id`. Rows sort
by order date descending, then by ID descending.
Use `page` and `per_page` for pagination. The default page size is 25.

Totals cover every order matching the filters, including cancelled orders
when they match. Amounts are grouped by stored currency. Each aggregate
`grand_total_minor` is a decimal integer string so clients can preserve
precision for large totals. MySQL uses a repeatable-read transaction so
each page and its totals share the same snapshot. The response includes
`meta.snapshot_at` in UTC. Each page request starts its own snapshot.

Set `REPORTS_DEFAULT_DAYS` and `REPORTS_MAX_DAYS` to configure the date
window, up to 366 days. Set `PAGINATION_REPORTS_DEFAULT_PER_PAGE` and
`PAGINATION_REPORTS_MAX_PER_PAGE` to configure page sizes up to 100.

### Sales and tax report

Send `GET /api/reports/sales-tax` with the same report permissions and
pagination options. `date_from` and `date_to` are inclusive calendar dates
in `DASHBOARD_TIMEZONE`. An omitted pair uses the same calendar window as
the Order and Fulfillment report. The report includes non-deleted
Completed orders whose `completed_at` falls on one of those local dates.
A range containing only skipped dates returns no orders and preserves the
date filters in the response. Rows sort by completion time descending,
then ID descending. The response includes the reporting timezone and a
UTC `snapshot_at`.

Optional `customer_id` and `product_id` filters match saved order records.
A product match includes the whole order and all of its stored amounts
once. Use the row's order ID with `GET /api/orders/{order}` for details.

Each row shows the stored subtotal, item discount, order discount, net total,
tax, and final amount including tax. These amounts retain the pricing and tax
applied at order completion, including order-level tax overrides. Report totals
use the same filtered snapshot as the rows and are grouped by saved currency.
Aggregate minor-unit amounts are decimal integer strings.

### Report history beyond 366 days

Split the requested history into consecutive, non-overlapping windows of
at most `REPORTS_MAX_DAYS` days. The default limit is 366. Both
`date_from` and `date_to` are inclusive. Start each new window on the
calendar day after the previous `date_to`. Keep the report endpoint and
optional filters fixed across windows. Fulfillment uses `order_date`.
Sales & Tax uses completion dates in `DASHBOARD_TIMEZONE`.

With the default limit, retrieve `2024-01-01` through `2025-06-30` in
these windows.

| `date_from` | `date_to` | Inclusive days |
| --- | --- | ---: |
| `2024-01-01` | `2024-12-31` | 366 |
| `2025-01-01` | `2025-06-30` | 181 |

Start at `page=1` for each window, keep `per_page` fixed, and retrieve
pages through `meta.last_page`.

For a newest-first export, concatenate Order & Fulfillment windows from
newest to oldest and keep the row order within each window. For Sales &
Tax, combine rows from all windows and sort globally by
`completed_at DESC`, then `id DESC`. Completion timestamps from adjacent
local-date windows can interleave when clocks roll back across midnight.

Use `data.totals` once per window. Each page includes totals for the
entire filtered window. Sum `order_count` across windows and combine
`data.totals.by_currency` entries by currency. Add each minor-unit
amount with exact integer arithmetic such as JavaScript `BigInt` and
serialize the combined money totals as decimal strings.

Each page request has its own snapshot and UTC `meta.snapshot_at`.
Use a stable dataset throughout retrieval when the combined export
needs to represent one consistent view.

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

### Customer summary, audit and account maintenance

| Method | Endpoint | Access and behavior |
| --- | --- | --- |
| GET | /api/admin/users/{user} | Active Admin with user-management access. Returns the profile consumed by Manage. |
| GET | /api/admin/users | Supports name/email search, active status, exact role and pagination. |
| GET | /api/customers/{customer}/order-summary | Existing customer visibility plus order-view access. Returns visible order counts, exact lifetime values per currency, and five most recent visible orders. |
| GET | /api/admin/access-audit-logs | Active Admin with audit.view. Paged user/role events, with action, actor and target filters. |
| GET | /api/admin/access-audit-logs/{log} | Reads user/role changes, including direct-permission additions or clears. |
| GET | /api/admin/audit-logs | Active Admin with audit.view. Paged order/customer/product events, with action, actor, target and UTC date filters. |
| GET | /api/admin/audit-logs/{log} | Reads the event's permitted before and after values, including retained history for deleted orders. |
| PATCH | /api/me/password | Checks the old password and revokes every bearer token after a successful change. |

Both audit list and detail responses include `actor_email` from the actor's
current account, or `null` if the account is unavailable. `actor_id` remains
the identifier used by audit filters. The actor field is separate from
the permitted before and after values.

Both audit lists have a default page size of 25 and a default maximum of 100.
Configure the access audit list with `PAGINATION_ACCESS_AUDIT_LOGS_DEFAULT_PER_PAGE`
and `PAGINATION_ACCESS_AUDIT_LOGS_MAX_PER_PAGE`. Configure the operational list
with `PAGINATION_OPERATIONAL_AUDIT_LOGS_DEFAULT_PER_PAGE` and
`PAGINATION_OPERATIONAL_AUDIT_LOGS_MAX_PER_PAGE`.

### Authentication trade-off

The original plan specified Sanctum session cookies and CSRF protection
for the Vue app. The API uses expiring Sanctum bearer tokens so Vue and
other clients can share the same login, logout, and user APIs.

Vue stores the token in browser `sessionStorage`. After a reload in the
same tab, Vue calls `GET /api/me` to validate the token and restore the
user and effective permissions. An expired token or a `401` from a
protected request clears the session. After signing in again, Vue can
return the user to a valid internal route, subject to its access checks.

JavaScript on the same origin can read the stored token. An XSS flaw
could expose it until expiry or revocation. Use HTTPS in production to
protect the token in transit.

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
| `RATE_LIMIT_PASSWORD_CHANGE_PER_ACCOUNT` | 6 requests | Additional password change quota for each authenticated account |
| `RATE_LIMIT_PASSWORD_CHANGE_WINDOW_MINUTES` | 1 minute | Window for the account password change quota |

Login checks all applicable quotas before password verification. Password
changes add an authenticated account quota to the shared API IP limit.
Limiter keys use HMAC-SHA-256 with `APP_KEY` for IP, email, and account
identifiers. Multiple API instances must use the same `APP_KEY` and a shared
limiter cache. Behind a reverse proxy, configure trusted proxies so Laravel
resolves the correct client IP.

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

The PHPUnit process timeout defaults to 600 seconds. Set `OMS_MYSQL_TIMEOUT_SECONDS`
to a positive whole number of seconds in the shell to override it.

```powershell
$env:OMS_MYSQL_TIMEOUT_SECONDS = '900'
php tests/run-mysql.php
```

## Technical notes

See the [assessment technical notes](docs/technical-notes.md) for design
decisions, verification evidence, and future improvements.

- Laravel controllers return an API envelope. Form Requests validate input,
  policies and scoped queries authorize reads and writes, and services own
  transactions, financial calculations, and inventory.
- Pricing uses stored item snapshots in integer minor units. Order-level
  discounts are allocated before line tax. Confirmation reserves stock in
  the same transaction as status/history; an eligible cancellation reverses
  it once. Idempotency keys protect retries and versions protect stale writes.
- Access and operational audit have separate read endpoints guarded by the
  active Admin and audit grant. Operational responses allowlist snapshots
  and redact contact values. The data remains available after soft deletion.
- Reports keep totals grouped by currency and use separate order-date and
  completion-date semantics. Each report response has a database snapshot.
  Numbered deep pages and text search are bounded; revisit measured slow paths
  before adding new indexing or aggregation strategies.
- Codex assisted with assessment traceability, code and test drafts, API
  integration, and documentation. The owner reviewed implementation patches
  and controlled commits and PRs. The reviewer demo should trace one order
  from pricing through assignment, confirmation, stock, and notification.
- Record exact compatible backend/frontend commits, dependency and secret
  scan results, MySQL contention evidence, and the three-role browser demo
  with release sign-off.
