# Sales collection schedule runbook

This runbook covers creation/revision of payment schedules, open-ended rolling-horizon generation, allocation correction, overdue escalation, and customer communication. It does not authorize production access, migration execution, customer messaging, financial adjustment, or historical repair.

## Ownership and severity

- Operational owner: Accounts/Collections. Sales Operations owns current handler and reviewed long-term contract facts. Engineering owns the scheduler, schema, and repair tooling.
- On-call routing and production topology must come from the approved target-environment register; do not infer either from local configuration.
- Severity 1: duplicate or missing canonical receipts/allocations, cross-tenant exposure, corrupted paid history, or unauthorized customer communication.
- Severity 2: an active rolling rule is not extended, reminders are delayed, aging disagrees with schedule minus allocations, or a current handler is missing.
- Severity 3: one future unpaid schedule or work item needs reviewed correction without financial-history impact.

## Preconditions

1. Confirm the legal entity, booking, current Sales attribution/handler, source currency, reviewed open-ended monthly value, feature-flag state, and deployed migration list.
2. Use read-only queries first. Record target, as-of timestamp/timezone, query version, row counts, source/LKR totals, and checksums without copying customer contact or evidence into tickets.
3. Do not enable `SALES_ROLLING_PAYMENT_SCHEDULES_ENABLED` until migration rehearsal, active-long-term review, role approval, and shadow reconciliation are signed.
4. Customer reminders remain disabled until channel, content, consent, escalation, retention, and owner are approved.

## Read-only diagnosis

- Compare one rule with its attribution, rule events, generated occurrences, collection work items, allocations, confirmed receipts, and current handler. A rule must be `open_ended`, monthly, source/LKR-equal to the reviewed attribution, and have unique consecutive occurrence numbers.
- Calculate generated outstanding as schedule amount minus net allocations. Separately reconcile Sales-target-eligible and excluded balances; never treat refundable security deposits as Sales collection or commission.
- Check the active rule's `last_generated_occurrence` and `last_generated_through` against the twelve-current/future-month target. Inspect scheduler history and rule-event checksums before retrying.
- For a reminder incident, compare current handler eligibility, work-item assignment, generic database notification, and immutable reminder-delivery idempotency key. Do not expose booking/customer amounts in notification payloads.

## Safe actions and approvals

- A permitted manager may create a rule only for an active reviewed long-term attribution with a configured collection-eligible handler and no unresolved non-initial schedule rows. Creation requires reason and idempotency key.
- Before a fixed-term future-unpaid revision, generate the no-write preview from the exact proposed rows and approval reason. Confirm retained plus replacement source and governed LKR totals equal the frozen attribution. `opening`, `balloon`, or `residual` requires exactly one matching labelled line; never use a mutable booking balance to absorb a variance.
- Pause extension during contract or data review. Resume only after the current handler and reviewed terms reconcile. Ending a rule stops new horizon generation but deliberately retains already-generated obligations.
- Future-unpaid rule rows may be replaced only after the rule is ended; replacement must reconcile exactly. Paid/allocated rows remain immutable. Any cancellation, reduction, write-off, credit, or FX correction uses its separately approved typed adjustment workflow.
- Retry the canonical daily processor only through the approved operations procedure. Never run a second scheduler concurrently; occurrence and event uniqueness are safeguards, not authorization.

## Reconciliation and closure evidence

- Prove rule occurrence numbers are consecutive and unique, generated work items are one-to-one, and retry creates zero additional logical occurrences/events.
- For a fixed-term revision, retain the preview checksum with the immutable revision, prove the mutation used those exact facts, and confirm allocated lines and allocation amounts did not move. Reconcile any previously unallocated confirmed receipt through the canonical oldest-due-first allocator.
- Reconcile monthly source amount, frozen LKR amount, generated horizon dates/value, receipts, allocations, generated outstanding, aging buckets, eligible/excluded totals, and Booking Management/Sales portal responses.
- Confirm no Sales attribution, New Sale, New Customer, commission earning, statement, or payout fact was created by horizon extension.
- Record actor/system identity, reason, idempotency key, event and payload checksums, before/after counts and totals, approvals, and any variance disposition.

## Communication, rollback, and review

- Accounts/Collections communicates operational impact; Sales Operations confirms handler/contract facts; Privacy approves any customer/contact disclosure. Use no customer message until the policy is approved.
- Before activation, rollback is feature disable plus reviewed migration rollback only when evidence tables are empty. Once rule/occurrence evidence exists, schema rollback is intentionally refused; pause the rule and use append-only correction/reconciliation.
- After Severity 1 or repeated Severity 2 incidents, complete a post-incident review covering trigger, detection, authorization, data/privacy impact, retry behavior, reconciliation, corrective source work, and prevention owner/date.
