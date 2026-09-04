# Sales performance monthly period close and rebuild

This runbook governs the Sales KPI snapshot only. Every employee remains a canonical `Staff` record; `SalesProfile` is an effective-dated Sales category/capability for eligible Staff and does not create a second employee master.

## Preconditions

- Keep `SALES_PERFORMANCE_SNAPSHOTS_ENABLED=false` until the schema, permissions, denial matrix, and rollback rehearsal have passed in the target environment.
- Set `SALES_BUSINESS_TIMEZONE` only to the Finance/operations-approved IANA business timezone. Missing or invalid configuration must block preview and close.
- Approve exactly one alert-policy version covering the whole local calendar month. Do not substitute suggested thresholds or application defaults for approval.
- Reconcile booking attribution, canonical receipt/finality facts, commission facts, Sales activities, approved target versions, and Profile-to-Staff/company ownership before close.
- Grant `sales.performance.snapshots.generate` only to the approved close operators. `sales.performance.snapshots.reopen` is separate, deny-by-default, and must not be bundled into Sales Manager automatically.

## Preview and close

1. Select one authorised legal entity, local calendar month, and explicit evidence cutoff.
2. Run the no-write preview. Confirm `write_performed=false`, the local timezone and UTC half-open interval, fact/target/row counts and checksums, every zero delta, the policy ID, and no blockers.
3. Investigate blockers at their canonical owner. Do not edit snapshot rows or introduce balancing facts.
4. Record a specific reason and commit the exact preview checksum and expected lock version with a fresh idempotency key.
5. Retain the returned period-lock version, frozen snapshot checksum, reconciliation checksum, close event, and outbox evidence.

The close serialises against company-scoped metric writers, rechecks source rows under locks, freezes immutable source reconciliation, and locks the shared Sales monthly period. A repeated idempotency key is safe only for the identical actor-bound request.

## Reopen and rebuild

1. Obtain the independent reopen authorisation and document the correction reason.
2. Reopen only the current locked version. A stale version or a non-Sales lock must fail.
3. Correct facts only through their canonical booking, receipt, attribution, commission, activity, target, or Profile owner paths. Never mutate a frozen snapshot.
4. Preview the same month again. Resolve every reconciliation blocker, then commit the rebuild against the reopened lock version.
5. Confirm the prior snapshot is retained as `superseded`, the replacement links through `supersedes_snapshot_id`, and current dashboards/alerts use only the new `frozen` snapshot. Existing HR review links remain historical evidence and are not silently rewritten.

## Migration, recovery, and deferred verification

- Preflight the foundation and Sales migrations in order, including `2026_08_14_100000_create_sales_period_close_workflow.php`.
- Rehearse apply and rollback on a production-like copy. Rollback intentionally refuses once close events or linked snapshots exist; export/reconcile immutable evidence and use an approved forward recovery instead of deleting it.
- Do not backfill historical periods unless source attribution, finality, target, policy, timezone, and cutoff evidence is unambiguous. Run eligible history in shadow, reconcile it, and obtain the required owner/Finance sign-offs before publication.
- In consolidated final verification, execute backend and portal tests/builds, actor/current-and-former-manager denial cases, preview/close/replay/stale-version/reopen/rebuild concurrency cases, timezone boundaries, zero/missing-target ranking, privacy, 320px/accessibility, migration/restore, and source-to-snapshot reconciliation. Record §24.1 evidence before checking any completion gate.

