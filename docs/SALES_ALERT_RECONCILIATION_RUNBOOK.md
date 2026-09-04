# Sales alert reconciliation runbook

## Purpose and authority

Use the alert reconciliation endpoint to trace one already-authorized intervention alert to the frozen KPI row, approved policy, evaluation run, action history, and domain outbox evidence. It is read-only support evidence. It must never create an employment, disciplinary, commission, payroll, or payout decision.

All employees remain canonical `Staff`. A Sales Profile is an additional effective category/capability and does not replace Staff or widen HR access.

## Privacy boundary

- Resolve the alert's Sales Profile and apply central self/team/all scope before loading or returning reconciliation evidence.
- Self/team scope returns only the authorized alert, its frozen Profile row, policy/evaluation checks, and its action history. It withholds company totals, source reconciliation payloads, other Profile identifiers, actor user identifiers, and evaluation suppression rows.
- Only `sales.performance.view-all` receives the frozen company reconciliation and current company source checksums.
- Never accept `staff_id`, `sales_profile_id`, `company_id`, or a scope override from the reconciliation request.

## Source review and expected checks

1. Confirm the snapshot is frozen and the alert, Profile row, policy, evaluation run, and legal entity agree.
2. Recompute the selected row from source facts as of the frozen cutoff using the frozen alert policy. Compare it to the immutable row checksum.
3. Compare the persisted policy rules, alert policy snapshot, threshold, comparison, and metric evidence to the evaluation checksum.
4. Verify the evaluation-run result checksum and its outbox payload link.
5. Verify the period-close snapshot outbox carries the frozen reconciliation checksum.
6. Verify action versions are continuous from version one and each action checksum is carried by the same-version alert outbox event.
7. In view-all scope only, compare current fact, target, row, and task-intervention checksums to the frozen company reconciliation.

Any missing or mismatched link is a failed reconciliation. Do not edit frozen evidence to make it match. Investigate source mutation, legacy lineage, migration order, or outbox corruption; retain the mismatch evidence and follow the governed reopen/rebuild process where authorized.

## Deferred executable verification

During source implementation, do not run migrations, tests, builds, queues, schedulers, or application servers. In the consolidated verification stage, execute direct-ID self/team/all denial cases, peer/current-former-manager privacy cases, checksum tamper cases, missing/out-of-order action and outbox cases, period rebuild/supersession cases, responsive keyboard/screen-reader checks, and source-to-alert-to-action reconciliation. Record exact commands and results under the canonical plan evidence contract.

## Rollback

The endpoint and portal panel are read-only and add no schema. Source rollback removes the route, controller method, service, portal control, and tests. Never delete or rewrite existing alert, snapshot, action, or outbox evidence as part of rollback.
