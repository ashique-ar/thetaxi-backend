# Sales performance alert action runbook

## Boundary and activation

Sales alerts are support records for internal `Staff` who have an effective `SalesProfile`; Sales is a Staff category/capability, not a separate employee master. Alert evidence must never create, recommend, or execute discipline, termination, payroll withholding, commission withholding, or another HR action.

Keep `SALES_PERFORMANCE_ALERT_ACTIONS_ENABLED=false` until migration preflight/apply, permission review, denial testing, approved owner/on-call and escalation rules, and reconciliation are complete. The capability fails closed while disabled. Grant `sales.performance.alerts.escalate` only through an approved role bundle; the additive permission seeder deliberately does not grant it to a baseline role.

## Operating procedure

1. Confirm the actor is in the alert's current central self/team/all Sales scope and the alert still shows the expected event version.
2. Review the frozen metric, period, threshold, comparison, policy version, severity, owner, and source drill-down. Do not copy customer or sensitive Staff data into the reason.
3. Record a factual reason for acknowledgement, resolution, reopen, snooze, or escalation. Snooze timestamps must include an explicit timezone offset.
4. On a conflict, refresh and review the later action; never overwrite it. Reusing an idempotency key with different input is an incident, not a retry.
5. Escalation increments evidence only. Reassignment or notification requires a separately approved owner/on-call policy and must not be inferred by this command.

## Reconciliation and incident response

Reconcile each alert's `event_version` to exactly one append-only action event per version and its matching outbox event/checksum. Confirm currently snoozed alerts are absent from the active dashboard and return after their due time; resolved alerts remain available through the scoped history API. Review direct-ID, former-manager, peer, cross-entity, idempotency, concurrency, timezone, accessibility, and privacy cases before activation.

If action evidence diverges, disable the feature flag, retain all rows, preserve request and outbox evidence, and escalate to the Sales application owner and security/privacy owner. The migration refuses rollback after governed action evidence exists; export, reconcile, and obtain an approved disposition before any rollback.
