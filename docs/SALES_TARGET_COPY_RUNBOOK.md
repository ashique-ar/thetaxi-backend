# Sales target copy runbook

## Purpose and boundary

This operation copies explicitly selected, approved monthly Sales Profile targets into a different calendar month. It does not carry targets automatically, infer amounts, enrol Staff into Sales, or approve the resulting drafts. `Staff` remains the internal employee record; a selected `SalesProfile` is only the Sales capability attached to that Staff member.

## Prepare and preview

1. Confirm the legal entity, approved source month, destination month, and authorised Sales Profiles.
2. Run the preview. It is write-free and returns a checksum over the exact approved source and latest destination evidence.
3. Review every row. `source_not_configured`, `source_approved_ambiguous`, and `profile_not_effective` rows are blocked and are not silently defaulted.
4. Confirm that `null` is displayed as not configured and that an explicit zero is displayed and copied as zero.

## Commit and approve

1. Supply a reason of at least ten characters and a new idempotency key with the unchanged preview checksum.
2. If source or destination evidence changed, refresh and review the preview; do not bypass the checksum conflict.
3. The command creates one immutable batch and a new draft version only for each copyable row. Blocked rows remain in the batch evidence but receive no target.
4. A different authorised user must approve each copied draft through the existing maker-checker action. Until then, the currently approved destination target remains authoritative.

## Reconcile and recover

- Reconcile the batch selection snapshot, created target rows, copied-from IDs, source values, destination versions, actor, reason, event, and checksums.
- Reusing an idempotency key with different evidence must return a conflict. A matching retry returns the original batch.
- The schema rollback refuses once copy evidence exists. Export and reconcile the immutable batch and target lineage under the approved retention process before any separately authorised schema disposition.
- Do not edit or delete batch evidence, manufacture a missing source, convert missing to zero, or bulk-approve copied drafts.

## Deferred executable gates

Before release, execute migration preflight/apply/rollback rehearsal on an approved disposable environment; API authorization, actor-denial, idempotency, stale-preview and concurrency tests; UI keyboard, 320 px, screen-reader and error-state checks; and source-to-draft reconciliation. These gates are intentionally not satisfied by source inspection.
