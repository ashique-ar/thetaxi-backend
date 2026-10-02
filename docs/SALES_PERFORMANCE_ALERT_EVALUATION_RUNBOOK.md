# Sales performance alert evaluation

This process evaluates management-support alerts from one immutable, reconciled monthly Sales KPI snapshot. Every employee remains canonical `Staff`; a `SalesProfile` only supplies the effective Sales capability and row scope. Alerts are not disciplinary findings and cannot change HR, payroll, commission, target, booking, receipt, or snapshot facts.

## Activation prerequisites

- Apply and rehearse rollback for `2026_08_14_101000_govern_sales_performance_alert_evaluations.php` before enabling `SALES_PERFORMANCE_ALERT_EVALUATIONS_ENABLED`.
- Configure the approved `SALES_BUSINESS_TIMEZONE` and an effective policy prepared and approved by different users.
- The policy must explicitly declare severities, factual thresholds, completed-month grain, once-after-close local schedule, grace/minimum elapsed days, baseline completeness, missing-data behavior, comparison normalization, prior-period-booking commission basis, and one accountable active internal Staff owner in the same legal entity.
- Do not copy recommended percentages into production without management approval. Old-format policies, legacy snapshots without frozen policy lineage, and pre-governance alerts fail closed for reviewed disposition.

## Preview and commit

1. Run `sales:process-performance-alerts` without `--commit`. Review every due and blocked snapshot; this mode writes nothing.
2. Confirm the snapshot is the current `frozen` monthly version, its close reconciliation freezes the expected policy ID, the policy was approved before snapshot generation, and the scheduled UTC instant derives from the approved local timezone.
3. Reconcile commission facts carrying both `collection_cohort` and `commission_category`. Prior-booking commission ratio drives the reliance rule; prior-booking collection ratio and long-term category share remain separate displayed evidence.
4. Resolve missing dimensions, targets, or comparison months according to the policy. `require_complete` blocks the whole evaluation; `suppress_rule_and_flag` records visible quality evidence without inventing a result.
5. Run with `--commit`. Retain the evaluation-run ID, snapshot/policy checksums, scheduled/evaluated instants, alert/suppression counts, result checksum, alerts, and outbox evidence.

The unique snapshot/policy evaluation ledger and alert deduplication keys make retries idempotent. A superseded policy remains valid only when it is the exact version frozen by the period close. Rebuilding a period creates a new snapshot and therefore a separate evaluation; alerts from the superseded snapshot are excluded from current dashboards.

## Recovery and verification

- Disable the evaluation feature flag to stop new evaluations; do not delete existing alerts or evaluation runs.
- Migration rollback intentionally refuses after immutable evidence exists. Use an approved forward correction or snapshot rebuild, not row edits.
- In consolidated verification, execute policy maker-checker/overlap, due-time/timezone/DST, dry-run/no-write, replay/concurrency, missing baseline/dimension/target, cohort-versus-category, current/superseded snapshot, direct-ID/current-former-manager/peer scope, accessibility/320px, migration/restore, and alert-to-source reconciliation scenarios.

