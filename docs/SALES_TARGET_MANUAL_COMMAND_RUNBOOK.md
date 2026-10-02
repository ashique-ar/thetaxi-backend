# Manual Sales target command runbook

## Ownership and scope

Every employee remains canonical `Staff`. A target belongs to one effective Sales Profile in one legal entity and does not create Staff, change Sales eligibility, calculate commission, or write payroll.

Canonical target administration uses `/api/sales/targets/versions`; the former `/api/sales/performance/targets` paths remain compatibility aliases during caller convergence. Reads require an explicit legal entity and remain inside central self/team/all Profile scope.

## Draft creation

1. Select one authorized Sales Profile and one complete calendar month.
2. Configure New Sales, eligible collections, or both. Blank means not configured; zero is an explicit target.
3. Record a reason of at least ten characters. Changes after a period starts use the same required evidence and permission; no value is inferred.
4. Submit one persistent idempotency key. A replay with identical canonical evidence returns the original draft and its original audit event; reuse with different evidence or missing original audit evidence fails closed.
5. Confirm the draft version, request checksum, payload checksum, preparer, timestamp, `audit_event_id`, correlation ID, and replay indicator. A draft never changes dashboard denominators until independently approved.

## Independent approval

1. Refresh the legal-entity/Profile/month history and select the exact draft version.
2. A user other than the preparer records an approval reason and submits the displayed expected version plus a persistent idempotency key.
3. Approval locks the legal entity, target, and prior approved rows. A stale version, reused key with different evidence, self-approval, invalid timezone, or non-draft state fails closed.
4. If the matching monthly performance period is locked, approval is prohibited. Reopen through the governed period workflow, approve the replacement target, then reconcile and rebuild; never rewrite a frozen snapshot silently.
5. Approval supersedes the prior approved row without deleting it and records the superseded IDs, approval checksum, approver, reason, timestamp, audit event/correlation IDs, and replay indicator.

## Verification and reconciliation

The consolidated stage must prove identical and mismatched idempotent retries, concurrent draft version allocation, concurrent approval, self-approval denial, cross-company/Profile denial, current/former-manager scope, zero/missing values, period-start changes, locked/reopened/rebuilt months, stable filters/pagination, source history, outbox evidence, and Staff/commission/payroll non-mutation.

## Rollback

The schema addition is nullable and does not rewrite historical rows. Before rollback, stop new manual target commands and preserve/export command and outbox evidence. Roll back only after confirming no retained target relies on the new idempotency or approval fields; never delete target versions or frozen KPI snapshots to force a rollback.
