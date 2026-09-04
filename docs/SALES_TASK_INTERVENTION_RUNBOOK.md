# Sales task intervention fact runbook

## Boundary

Sales tasks belong to internal `Staff` through an effective `SalesProfile`. Overdue-task and repeatedly-missed-next-action facts are management support evidence only. They never create discipline, change employment status, hold commission, alter payroll, or expose another salesperson's customer or task details to peers.

## Governed deadline contract

New task deadlines must arrive as ISO 8601 instants with an explicit offset. The API normalizes due, reminder, and escalation times to UTC and freezes `explicit_utc_v1` deadline evidence plus its checksum. Existing tasks without this evidence are not backfilled or guessed. They require reviewed historical disposition and cause the affected alert rules to suppress with visible data-quality evidence.

## Closed-period calculation

- Overdue task count uses tasks that were open or in progress at the closed period-end instant and attributes them to the owner at that instant.
- A missed next action is a governed task that was open or in progress at its deadline. It is attributed to the owner at the deadline, even if later completed or transferred.
- Repeated-miss lookback length, count threshold, overdue count/age thresholds, severities, and enablement come only from the approved policy version frozen by period close.
- Status and owner are reconstructed from append-only task events as of the relevant instant. Missing deadline, event, or in-scope owner history suppresses task alerts; it is never interpreted as zero.
- Frozen KPI rows retain aggregate counts, age, lookback, missing-history count, and an evidence checksum. Task title, description, customer, inquiry, booking, and opportunity details are excluded.

## Pre-activation and reconciliation

Keep CRM, performance snapshot, and alert-evaluation features disabled until the deadline migration, current task writers, approved policy, and historical disposition are reviewed. Reconcile each governed task deadline checksum to its stored UTC instant and each profile aggregate to task events at period end and due time. Exercise on-time completion, late completion, open/in-progress overdue, cancellation before/after due, transfer before/after due, backdated creation, missing history, current/former-manager scope, peer/direct-ID denial, timezone boundaries, idempotency, concurrency, rebuild, and superseded-snapshot cases.

If evidence diverges, disable evaluation, retain the immutable snapshot and task events, record the affected company/period/checksums without customer details, and escalate to the Sales application and privacy owners. Rollback is refused after governed deadline evidence exists until it is exported, reconciled, and given an approved disposition.
