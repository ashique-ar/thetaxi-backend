# Sales reporting-FX correction runbook

## Activation gate

Keep `SALES_FX_CORRECTIONS_ENABLED=false` until Finance has approved and configured all of:

- `SALES_FX_APPROVED_QUOTE_BASE`
- `SALES_FX_CALCULATION_MODE` (`multiply_source_by_rate` or `divide_source_by_rate` only after Finance chooses it)
- `SALES_FX_RATE_MAX_AGE_HOURS`
- `SALES_FX_ROUNDING_SCALE`
- the authorised FX evidence source and the separate accounting FX gain/loss process

An absent value fails closed. Activation does not authorise a provider call and does not define a statutory or accounting treatment.

## Operator procedure

1. Open **Sales & Performance > Financial Corrections** with an internal Staff context.
2. Select a confirmed, authorised receipt component. Do not use the workflow to change source cash, receipt finality, receivables, schedules, or allocations.
3. Enter the affected source amount, corrected governed LKR amount, replacement rate, rate timestamp, approved source, approved quote/base, evidence reference, effective timestamp, and reason.
4. Confirm the direction shown by corrected LKR minus the current effective corrected LKR (or proportional original LKR for the first correction). For a correction-of-correction, select the server-returned current leaf; the affected source amount is frozen from that leaf. A locked Sales period must first follow its separately approved reopen/new-snapshot workflow.
5. Run the no-write preview. It recalculates the corrected whole-payment basis with the frozen percentage/fixed/tiered formula and original version rounding. It shows the immutable sequence, prior effective amount, incremental and cumulative reporting deltas, and incremental commission delta. A missing or ambiguous tier blocks; no rate or tier is guessed.
6. Record the correction once with the preview checksum. Any changed financial/effective evidence invalidates the preview. Reuse the same idempotency key only for an exact retry.
7. Review the linked commission case. Percentage and uniquely matched tiered cases expose the signed delta; fixed cases require a no-change acknowledgement.
8. Apply a deduction or credit only to the original immutable beneficiary, category and cohort, or record the permitted waiver/no-change decision with a reason. Approved credits and deductions enter a later canonical statement as adjustment/recovery lines; they never edit the original earning.
9. Reconcile source cash independently (it must not move), reporting LKR, commission adjustment facts in the correction effective period, statement lines, and the separate accounting FX gain/loss record.

## Failure and recovery

- Policy-disabled/incomplete, stale/future rate, quote/base mismatch, missing original FX evidence, ambiguous/missing commission earning, cross-scope booking, over-limit source amount, missing/stale/non-leaf predecessor, changed affected source amount, unresolved prior commission review, or backdated successor all fail before an adjustment is written.
- A tier gap, overlap, or missing frozen version blocks preview and must be resolved through the governed plan-version exception process.
- Adjustment and recovery evidence are immutable. Do not edit database rows. A correction-of-correction appends exactly one successor to the current leaf, retains the original receipt and beneficiary evidence, freezes the prior corrected FX/commission values, and creates only incremental reporting and commission effects. Branches are prohibited by validation and unique constraints.
- A prior commission correction must be resolved before its successor is previewed. A waiver remains immutable; the successor compares formulas with the prior recalculated amount and receives its own explicit deduct/credit/waive/no-change decision.
- Migration rollback refuses while reporting-FX corrections/recovery cases or counter-adjustment successors exist. Export and reconcile complete lineages before a rollback decision.

## Deferred verification

Before enabling the feature, execute the canonical plan's migration preflight/apply/rollback rehearsal, actor denial matrix, duplicate/concurrency tests, FX reproduction and independent cash/LKR/accounting reconciliation, Angular accessibility/responsive checks, production build, and Finance sign-off. These gates are not satisfied by source inspection.
