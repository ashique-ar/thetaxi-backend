# Booking Operations Monitoring Runbook

Use this runbook for the internal Booking Management workspace and its canonical assignment, lifecycle, tracking, payment, and settlement APIs.

## Ownership and safety boundary

- Angular route: `/bookings/:id?booking_item_id=...`, guarded by `bookings.view`.
- Laravel request timing: `BookingOperationsTelemetry` on assignment, booking observability, booking lifecycle, and financial settlement routes.
- Tracking health: `BookingOperationsHealthMonitor`, called by the booking-owned tracking summary and authenticated driver location upload controllers.
- Lifecycle integrity: `BookingLifecycleService`; `allowed_actions` and `blocking_reasons` remain authoritative.
- Settlement integrity: `FinancialAccountSettlementService`; monitoring consumes its reconciliation issue codes and never calculates balances independently.
- `booking_item_id` identifies the selected trip throughout. Do not aggregate item alerts into a booking-level state change.
- Operational GPS and route evidence have no contractual pricing effect. Monitoring must never feed coordinates, route distance, or freshness into pricing.

Never add customer names, phone/email, addresses, coordinates, route points, payment amounts, settlement balances, request bodies, blocker text, exception messages, document paths, or provider details to these signals.

## Production configuration

Set these values in the Laravel production environment:

```env
APP_DEBUG=false
LOG_CHANNEL=stack
LOG_STACK=single,sentry_logs
LOG_LEVEL=warning

SENTRY_LARAVEL_DSN=<backend-dsn>
SENTRY_ENVIRONMENT=production
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0
SENTRY_SEND_DEFAULT_PII=false

BOOKING_OPERATIONS_READ_TARGET_MS=2000
BOOKING_OPERATIONS_ACTION_TARGET_MS=3000
BOOKING_HEALTH_ALERT_THROTTLE_SECONDS=900
```

If logs are shipped by another supported channel, keep that channel in `LOG_STACK`; `sentry_logs` is required only when Sentry Logs is the alert source. After changing values:

```bash
php artisan config:clear
php artisan config:cache
```

The read target applies to safe HTTP methods. The action target applies to lifecycle, assignment, payment, and settlement mutations. Every instrumented response includes `Server-Timing: booking;dur=<milliseconds>`.

## Signal catalog

| Signal | Level | Meaning | Safe fields |
|---|---:|---|---|
| `booking_operations_api_slow` | warning | Canonical request exceeded its configured read/action target. | `route`, `method`, `status`, `duration_ms`, `target_ms`, `booking_id`, `booking_item_id`, `actor_id` |
| `booking_operations_api_failed` | error | Canonical request threw or returned HTTP 5xx. | `route`, `method`, `status`, `duration_ms`, `booking_id`, `booking_item_id`, `actor_id`, optional `exception_class` |
| `booking_tracking_freshness_unhealthy` | warning | An assigned selected item is delayed, stale, or has never reported. Expected offline/unassigned states are excluded. | `booking_id`, `booking_item_id`, `assignment_id`, `driver_id`, `trip_phase`, `freshness`, `last_reported_age_seconds` |
| `booking_location_upload_failed` | error | Unexpected single or buffered driver upload failure. Expected no-session and rate-limit responses are excluded. | `booking_id`, `booking_item_id`, `assignment_id`, `session_id`, `driver_id`, `upload_type`, `exception_class` |
| `booking_lifecycle_state_mismatch` | error | Selected-item lifecycle summary status differs from the canonical lifecycle contract status. | `booking_id`, `booking_item_id`, `summary_status`, `contract_status` |
| `booking_settlement_reconciliation_mismatch` | error | Persisted settlement aggregates or item outstanding state disagree with canonical settlement reconciliation. | `settlement_id`, `issue_codes` |
| `booking_management_route_viewed` | info | Opt-in pilot evidence for a normalized canonical or compatibility Angular route. | `route_key`, `resolved_route_key`, anonymous per-tab `session_id`, `actor_id` |

Health signals are throttled per signal and safe context for `BOOKING_HEALTH_ALERT_THROTTLE_SECONDS`. Their event count is therefore not a raw failure count; dashboard distinct IDs and continuing presence instead.

Route evidence is disabled by default and fails closed unless all three settings define a current approved window: `BOOKING_ROUTE_USAGE_ENABLED=true`, `BOOKING_ROUTE_USAGE_STARTS_AT=<ISO-8601>`, and `BOOKING_ROUTE_USAGE_ENDS_AT=<ISO-8601>`. Clear/cache configuration after setting them and disable collection after evidence is exported. The client sends fixed route keys only; booking IDs, item IDs, query parameters, URLs, and referrers are never included. Use distinct `session_id` for visits and `actor_id` for authenticated-user coverage. The endpoint accepts the union of permissions used by the measured Booking routes, so least-privilege create, edit, report, availability, and VIP actors are not silently omitted.

## Saved searches and dashboards

Create one dashboard named **Booking Operations Health**. Use these exact message searches in Sentry Logs or the equivalent structured-log backend:

```text
message:"booking_operations_api_slow"
message:"booking_operations_api_failed"
message:"booking_tracking_freshness_unhealthy"
message:"booking_location_upload_failed"
message:"booking_lifecycle_state_mismatch"
message:"booking_settlement_reconciliation_mismatch"
```

Recommended widgets:

1. **API latency** — `booking_operations_api_slow`; count and `duration_ms` by `route` and `method` over 15 minutes and 24 hours.
2. **API reliability** — `booking_operations_api_failed`; count by `route`, `status`, and `exception_class`.
3. **Tracking health** — `booking_tracking_freshness_unhealthy`; distinct `assignment_id` by `freshness` and `trip_phase`.
4. **Upload failures** — `booking_location_upload_failed`; distinct `assignment_id`/`session_id` by `upload_type` and `exception_class`.
5. **Contract integrity** — both mismatch signals; count by lifecycle status pair or settlement `issue_codes`.
6. **Browser/API correlation** — Sentry browser transactions for `/bookings/:id` beside Laravel route transactions. Use IDs only for incident drill-down, never as high-cardinality dashboard groupings.

For a server without log aggregation, use PowerShell:

```powershell
Get-Content storage/logs/laravel.log | Select-String -Pattern 'booking_operations_api_slow|booking_operations_api_failed|booking_tracking_freshness_unhealthy|booking_location_upload_failed|booking_lifecycle_state_mismatch|booking_settlement_reconciliation_mismatch'
```

Or Linux:

```bash
grep -E 'booking_operations_api_slow|booking_operations_api_failed|booking_tracking_freshness_unhealthy|booking_location_upload_failed|booking_lifecycle_state_mismatch|booking_settlement_reconciliation_mismatch' storage/logs/laravel.log
```

## Alert routing

| Condition | Initial severity | Route to | First response |
|---|---|---|---|
| Any lifecycle or settlement mismatch | High | Backend on-call plus operations/finance owner | Preserve IDs, verify canonical records read-only, and stop manual data rewriting until the owner service is traced. |
| Five API failures in five minutes, or any sustained 5xx on one route | High | Backend on-call | Check release, database/cache health, route transaction, and affected item scope. |
| Five distinct upload-failure assignments in 15 minutes | High | Backend/mobile on-call and dispatch | Check API/mobile release, authentication, sessions, queue/database health, and network pattern. Do not request raw coordinates in the incident channel. |
| Three distinct stale/never-reported active assignments in 15 minutes | Medium | Dispatch plus mobile support | Confirm driver connectivity/session state in Booking Management; retain last-known evidence and avoid treating it as current. |
| Slow requests exceed 5% of sampled canonical requests for 15 minutes | Medium | Backend on-call | Compare route/SQL spans, deployment time, data volume, and cache/database saturation. |

Tune thresholds only after a representative production baseline. Record every threshold or routing change in the deployment/change record.

## Triage sequence

1. Confirm environment and release; exclude local/staging signals from production incidents.
2. Identify the exact signal, route, booking ID, and selected `booking_item_id` or settlement ID.
3. Open the permitted Booking Management workspace. Do not bypass `bookings.view`, replay, export, finance, or lifecycle permissions.
4. Compare UI state with the canonical Laravel response. Backend `allowed_actions` and `blocking_reasons` win.
5. For tracking, confirm assignment/session identity, driver online state, freshness, and last-reported time. Keep last-known evidence labelled stale/offline.
6. For lifecycle mismatch, inspect the selected item’s dispatch, assignment, return, QC, and completion records without changing them.
7. For settlement mismatch, inspect settlement items, allocations, adjustments, refunds, and aggregate fields through the canonical settlement service. Never “fix” totals with direct SQL.
8. Correlate Sentry browser and Laravel transactions by time, route, actor ID, and operational IDs.
9. Resolve through the canonical service or a reviewed repair command/migration, then verify the signal does not recur after the throttle window.

## Deployment validation

Run focused tests before deployment:

```bash
php artisan test tests/Unit/BookingOperationsTelemetryTest.php tests/Unit/BookingOperationsHealthMonitorTest.php --compact
php artisan route:list --path=booking-lifecycle
php artisan route:list --path=financial-settlements
```

In staging:

1. Confirm `SENTRY_SEND_DEFAULT_PII=false` and the production-equivalent log stack.
2. Temporarily set `BOOKING_OPERATIONS_READ_TARGET_MS=0`, clear/cache config, and load one permitted Booking Workspace item.
3. Confirm the response has a `Server-Timing` booking metric and one `booking_operations_api_slow` event with only allowlisted fields.
4. Restore the normal target immediately and clear/cache config again.
5. Run the focused health-monitor tests to validate throttling and field allowlists. Do not manufacture lifecycle or financial corruption in a shared environment.
6. Confirm dashboard widgets and alert routes receive a test event in the correct environment and that the notification contains no prohibited data.

Production acceptance requires a real authenticated Booking Workspace read, monitoring-backend receipt, and alert-routing check. Static configuration or local tests alone are not production acceptance.
