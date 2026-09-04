# Sales target import runbook

## Purpose and ownership

The target import creates versioned monthly draft targets for explicitly enrolled `SalesProfile` records. `Staff` remains the canonical internal employee record and the imported Sales code only resolves the Sales capability attached to that Staff member. The import never enrols Staff, changes Sales eligibility, approves targets, invents amounts, or carries a prior month automatically.

## CSV contract

Use UTF-8 CSV with exactly these headers in this order:

`sales_code,new_sales_target_lkr,eligible_collections_target_lkr`

- One legal entity and one destination calendar month apply to the whole file.
- Each Sales code may appear once and must resolve inside the actor's current self/team/all scope.
- Amounts are non-negative LKR values with at most four decimal places. Blank means `not_configured`; `0` means explicit zero. At least one amount is required.
- A file is limited to 1000 data rows and 2 MiB. Split a larger approved input into separately reviewed imports.

## Preview and commit

1. Select the legal entity, destination month, and CSV, then preview.
2. Preview parses the file in memory and performs no file storage or target write. Review accepted and rejected rows and the latest destination version shown for each accepted Profile.
3. Correct duplicate, missing, out-of-scope, ineffective, malformed, or amount-less rows in the source file. Partial acceptance is permitted only when the operator deliberately commits the visible accepted subset.
4. Commit the unchanged file with the preview checksum, an explicit reason, and a new idempotency key.
5. The command rechecks scope and destination evidence under row locks, stores the original file on `sales_private`, records immutable row errors, and creates drafts for accepted rows only.
6. A different authorised user must approve every draft using the existing target maker-checker action. Previously approved destination evidence remains authoritative until then.

## Reconciliation and recovery

- Reconcile the private file checksum, input/accepted/rejected counts, row checksums/errors, imported draft source/job/row lineage, actor, reason, outbox event, and destination target versions.
- A matching retry returns the original transfer job. Reuse of an idempotency key with different file, scope, preview, reason, or actor must conflict.
- Do not edit or delete transfer-job evidence, silently fix a rejected row, convert blank to zero, or bulk-approve drafts. Correct the CSV and create a new preview/import.
- No automatic retention or purge is enabled because an approved target-import retention period is not yet recorded. Keep the private file restricted pending that decision.
- Schema rollback refuses after import evidence exists. Export and reconcile immutable evidence under an approved disposition before any separately authorised rollback.

## Deferred executable gates

Before release, run migration preflight/apply/rollback rehearsal; malformed/quoted/BOM/zero/blank/boundary CSV cases; direct-ID, former-manager, peer and cross-company denials; stale-preview, retry and concurrent-import attacks; private-storage and row-count reconciliation; and keyboard, screen-reader, 320 px, error and partial-acceptance browser checks. These gates are not satisfied by source inspection.
