# OMS Technical Notes

## Architecture

The OMS runs as a Laravel JSON API backed by MySQL and a separate Vue 3 SPA.
Laravel Form Requests validate inputs. Policies and query scopes enforce role
and record access. Domain services calculate prices and control transactional
writes. API resources and response classes expose the approved fields through
the [OpenAPI contract](openapi.yaml).

[Vue Router and Pinia](https://github.com/LPyadav/oms-frontend/blob/dba392cff0921a3a1379124ba031bd78f77abb94/README.md#architecture-and-trade-offs)
own navigation and shared session state. Feature composables manage local data
and cancel stale reads. Vuetify provides shared UI primitives. The browser
uses relative API URLs through the Vite proxy in development and requires a
same-origin API route in deployment.

## Decisions and implementation challenges

- **Reproducible pricing.** [Pricing logic](../app/Services/Orders/OrderPricingService.php)
  uses integer minor units and persists item price and tax snapshots. Order-level
  discounts are allocated before tax. The server calculates previews and write
  totals using validated inputs. Existing snapshots retain their price and tax
  rate when the catalog changes.
- **Concurrent inventory and writes.** [Order lifecycle](../app/Services/Orders/OrderLifecycleService.php)
  applies stock deduction on confirmation and restores the actual deduction on
  eligible cancellation. Stock, status history, and audit updates are atomic.
  Version preconditions reject stale edits. Per-user idempotency keys allow
  creation retries, and bounded retries handle database lock contention.
- **Reliable notification delivery.** An [outbox event](../app/Services/Notifications/OrderNotificationOutbox.php)
  is recorded in the order transaction. Delivery is dispatched after commit.
  A scheduled recovery command retries due events, with one notification per
  event and recipient. Access is checked at delivery and when reading.
- **Reporting and history.** Fulfillment filters by order date. Sales & Tax
  filters by completion date in the configured reporting timezone. Reports
  group amounts by stored currency and read rows and totals in one snapshot.
  Separate access and operational audits record permitted before and after
  values, and operational snapshots redact contact fields.

## Performance and security

Order listings filter and paginate in SQL. Indexed exact lookups cover order
number and item SKU. Deep order-date and customer-sort pages select keys
before loading full rows. In the documented local MySQL 8.2 benchmark, a
50,000-order fixture at page 400 measured 46.7 ms p50 and 91.3 ms p95 for the
page SELECT. A broader substring benchmark measured 35.85 s p95 for a no-match
search over 50,000 orders and 250,000 item snapshots. The [benchmark details](../README.md#orders)
describe the workloads and limits.

Sanctum bearer tokens expire and can be revoked. Vue restores same-tab
sessions from session storage and checks them with `GET /api/me`. Same-origin
JavaScript can read the stored token, so an XSS defect can expose it until
expiry or revocation. Backend policies enforce permissions even when the UI
hides actions. With the default Staff grants, order reads are scoped to
assigned orders and status changes are limited to allowed transitions.
Login throttles and token revocation are covered by tests. The [database exception reporter](../bootstrap/app.php)
logs a fixed message, exception type, and validated SQLSTATE. Unexpected API
failures return a safe error envelope.

## Verification and reviewer walkthrough

Use the [assessment demo](../README.md#assessment-demo-dataset) and
[development checks](../README.md#development-checks) to reproduce setup
and tests. Sign in as Admin to review user access and audit history. As Manager,
create, assign, and confirm an order and check the stock movement. As the
assigned Staff user, advance the order and open its notification. As Manager,
complete it and verify the Sales & Tax totals.

Use a separate Staff-assigned order for cancellation. Confirm it, record an
independent Admin stock adjustment, and cancel as Manager. Check that the
original deduction is restored once. Verify the stock movements, status
history, audit entry, and assigned Staff user's cancellation notification.

The G4 fresh-clone walkthrough used backend
[`9b6aa08`](https://github.com/LPyadav/oms-backend/commit/9b6aa08a0db0a763ca634c40646490c552ebd964)
and frontend
[`dba392c`](https://github.com/LPyadav/oms-frontend/commit/dba392cff0921a3a1379124ba031bd78f77abb94).
That pair passed the three-role browser walkthrough, 457 frontend tests, and
243 MySQL tests. Dependency audits and tracked-file and history secret scans
reported no findings for that pair. The later backend release fix
[`00be732`](https://github.com/LPyadav/oms-backend/commit/00be732b209eb17560e55682068c9c732f2c9760)
passed two logging regression tests, Pint, PHPStan level 8, and strict OpenAPI
validation. Redocly and Laravel route comparison matched all 53 API operations.

On 23 November 2026, a fresh backend clone at
[`b87499f`](https://github.com/LPyadav/oms-backend/commit/b87499f3c12eff99a5d4ef2ca8adbb3552dcb5c0)
passed the API smoke on isolated MySQL 8.2.0. All 27 migrations applied.
The demo commands created 5 users, 100 customers, 50 products, 504 orders,
and 1,008 order items. The database worker delivered 936 unique notifications
with zero pending or failed jobs. All 37 live HTTP checks passed, covering
three-role access, bearer login and logout, notifications, and report totals.

On the same date, a live cancellation run at backend `c157e7b` passed 14 HTTP
checks on isolated MySQL 8.2.0. Confirming a five-unit order reduced stock
from 100 to 95. An Admin adjustment set stock to 80. Manager cancellation
restored five units, leaving 85. MySQL recorded one deduction, one reversal,
and three matching status history and status audit rows. A stale cancellation
returned HTTP 409 and a repeated cancellation returned HTTP 422 without
additional writes. The worker delivered the assignment, confirmation, and
cancellation notifications once each to the assigned Staff user.

## AI assistance and review

Codex helped map assessment requirements, draft implementation and tests,
investigate issues, and prepare documentation and release checks. The project
owner reviewed exact diffs, supplied the account status API and order-tax
changes for frontend integration, and controlled approvals and merges. Review
revisions corrected the post-login redirect and added regression coverage for
session expiry and sanitized database logging.

## Future improvements

- Measure substring search and count queries at realistic load before choosing
  a search index. Evaluate cursor-based pagination where existing sort and
  filter semantics can be preserved.
- Evaluate an HttpOnly cookie session for a browser-only same-origin deployment.
  Include CSRF protection and account for clients that use bearer tokens.
- Automate lockfile audits, secret scans, OpenAPI contract checks, and disposable
  MySQL tests so release evidence is recorded against deployed revisions.
