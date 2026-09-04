# Sales commission refund recovery runbook

## Scope and activation

This runbook covers commission recovery created from an immutable commission-eligible cash decrease. It does not authorize refund creation, rates, payout, payroll settlement, historical backfill, or feature activation. Keep the Sales and commission feature flags disabled until migrations, reconciliation, permissions, Finance approval, and the consolidated verification gate pass.

The original internal employee remains a canonical `Staff`. Recovery follows the immutable Sales Profile and commission-decision beneficiary snapshot; it must not be reassigned from the employee's current category, team, manager, or portfolio.

## Triage

1. Record the legal entity, recovery case ID, payment adjustment ID, original commission decision ID, correlation ID, and incident time. Do not copy customer, bank, evidence-file, or employee-sensitive content into incident channels.
2. Confirm the case is pending and inspect the no-write decision preview. A blocked preview is a reconciliation condition, not permission to edit history.
3. Confirm exactly zero or one non-void source statement line exists. Multiple lines require Finance reconciliation before any decision.
4. Classify the previewed disposition:
   - `unstatemented_liability_adjustment`: append the liability adjustment before statement generation.
   - `unpaid_statement_liability_adjustment`: append against unpaid statement liability; never edit the source line.
   - `paid_negative_carry_forward`: retain paid history and carry the negative amount forward.
   - `post_payment_credit`: retain paid history and append the positive credit.
   - `waived_recovery` or `no_change`: retain reason, authority, and frozen financial impact.
5. Confirm the frozen entitlement source is the original earned decision, one released hold, or one late-attribution entitlement. Escalate a missing source, multiple entitlement sources, multiple statement lines, checksum/version conflict, or cross-entity evidence. Do not repair these with SQL or a duplicate decision.
6. If the cash decrease occurred while commission was still held, verify the later release/adjustment opened the payment-adjustment-unique recovery case. A shadow calculation is not a payable entitlement and must not produce a recovery liability.

## Decision controls

- Deduct/credit requires the approved `sales.refunds.decide` authority; waiver requires the separately approved `sales.refunds.waive` authority. Registration is deny by default and is not approval of a production role bundle.
- The original beneficiary and the source-adjustment preparer or approver cannot decide the recovery.
- Submit the preview checksum, expected case version, reason, and a unique idempotency key. On conflict, refresh and re-review; never reuse a key with changed input.
- A reporting-FX correction retains its separately governed recovery authority and never changes source-currency cash.

## Reconciliation and close

Before close, reconcile the payment adjustment, base commission decision, frozen entitlement source, decision preview checksum, recovery decision, occurrence-period metric fact, statement adjustment, opening carry-forward, closing carry-forward, outbox event, and accounting treatment. A paid source remains paid; recovery is append-only.

Do not close the incident or activate payout reliance until Finance confirms the disposition and the target environment passes migration, concurrency, authorization, privacy, statement/carry-forward, metric, outbox, and accounting reconciliation.

## Rollback and restore

The additive migration refuses rollback after checksummed decisions exist. Export and reconcile affected cases first, disable the owning feature flags, stop new writes through the approved operational change process, and restore from the rehearsed backup only under the release/incident owner. Never delete or rewrite an earning, statement line, payment adjustment, recovery case, or recovery decision to force rollback.
