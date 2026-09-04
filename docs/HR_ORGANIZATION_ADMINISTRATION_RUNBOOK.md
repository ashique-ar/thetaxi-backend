# HR Organization Administration Runbook

## Scope and ownership

This runbook covers canonical internal-Staff organization units, job families, grades, designations, positions, finite acting appointments, People reporting lines, custom-field definitions, and encrypted Staff custom-field values. It does not change `Staff` ownership, create Sales Profiles, or replace the separate Sales reporting hierarchy. Work calendars remain owned by the attendance configuration path.

## Preflight

1. Keep `HR_PEOPLE_CORE_ENABLED=false` while rehearsing schema and data checks.
2. Confirm `2026_08_12_147000_create_hr_people_core.php` precedes `2026_08_14_107000_govern_hr_organization_administration.php`, followed by `2026_08_14_108000_govern_hr_job_and_position_administration.php`, `2026_08_14_109000_govern_hr_reporting_lines.php`, `2026_08_14_110000_govern_hr_acting_appointments.php`, and `2026_08_14_111000_govern_hr_staff_custom_field_values.php`.
3. Inventory organization units by legal entity, code, parent, type, effective interval and status. Reject duplicate company/code values, missing/cross-company parents, self-parenting, cycles and cross-company manager/HR-partner references.
4. Inventory custom-field definitions by company, owner type and key. Reject duplicate keys, unsupported types/classifications, select fields without options and non-select fields with options.
5. Inventory job families, grades, designations and positions by legal entity. Reject cross-entity references, duplicate company-qualified codes/numbers, invalid date intervals, grade ranges where maximum is below minimum, and headcount below current effective assignment occupancy. Do not invent effective dates for legacy rows; route incomplete rows through the approved mapping/rejection process.
6. Record counts and hashes for organization units, job catalogues, positions and definitions without exporting personal or encrypted custom-field values.
7. Inventory reporting lines and assignment manager snapshots by legal entity, type and interval. Reject self/cross-entity links, overlaps, effective cycles, employment gaps, future lines for leavers, and unexplained disagreement between assignment snapshots and the canonical reporting-line history. Do not derive or copy Sales Profile hierarchy.
8. Confirm the explicit `hr.organization.*`, `hr.reporting-lines.*`, and `hr.custom-fields.*` role bundle with HR/Security. Navigation is not authorization evidence.
9. Inventory proposed and historical acting appointments by Staff, employment spell, source assignment, target position, start/end, requester and approver. Reject open-ended dates, overlaps, missing restorations, cross-entity references, unapproved capacity exceptions, inferred pay/allowance changes, and entries that silently changed Sales Profile reporting.
10. Inventory existing Staff custom-field values without decrypting or exporting them. Values without a governed checksum, definition version, effective date, reviewed classification, and authoritative source remain `legacy_unverified` and must not be revealed. Produce reviewed rewrite/rejection counts instead of inventing plaintext or dates.

## Migration and activation

1. Rehearse the two People migrations in order against a production-like copy and capture apply/rollback timings.
2. Confirm existing rows receive version `1`, the change-event table is empty, global position-number uniqueness becomes legal-entity-qualified, and legacy job catalogue effective dates remain null pending approved mapping rather than receiving guessed history.
3. Run the focused API, permission, idempotency, stale-version, hierarchy-cycle, cross-entity, privacy and Angular accessibility matrices required by the canonical plan.
4. Enable People Core only after the historical organization import/backfill has a reviewed mapping, rejection report and reconciliation totals.
5. Create or edit one isolated non-production unit, job record, position and definition; prove one event per accepted version and exact before/after reconstruction.
6. Reconcile each position's current effective Staff assignments against `occupied_count`, `vacancy_count`, and availability. Prove capacity cannot be reduced below occupancy and occupied positions cannot be deactivated.
7. Prove primary/dotted People scope changes exactly at interval boundaries; HR-partner and approval lines must not grant team access. Exercise two employees, a current/former manager, a cycle attempt, retry, stale version, manager exit, future cancellation, and assignment-manager projection.
8. Exercise an acting request and independent approval. Prove the source assignment closes at the acting start, the acting assignment is finite, the exact primary assignment is restored at the end, reporting scope follows both boundaries, the requester cannot approve, stale source/capacity/version evidence fails, and retry does not duplicate assignments or events.
9. Confirm compensation, payroll, delegated approval authority, and Sales hierarchy remain unchanged. Any such impact requires its separately configured and approved owner workflow.
10. Prove every supported Staff field type rejects malformed values, required fields reject null/empty values, select values remain inside the frozen options, decimals remain strings, datetimes require offsets, stale definition/value versions fail, and unauthorized backdating fails.
11. Exercise self/current-manager/former-manager/HR/Legal actors. Confirm current People scope applies before field classification, `hr_private` and `legal` values require their separate permissions, unauthorized definitions are omitted, and each returned decrypted value creates one access event without plaintext.

## Reconciliation

- Every command event matches one company, aggregate and version.
- Aggregate versions are monotonic with no duplicate `(type, id, version)`.
- A replay with the same key and payload returns the original snapshot; changed facts, command type or legal entity are rejected.
- Every active unit has an effective legal-entity parent chain with no cycle.
- Every designation references only its legal entity's family/grade, every position references only its legal entity's unit/designation, and position numbers are unique within that entity.
- Position availability is derived from current effective `hr_employment_assignments`; it is not a separately editable fact.
- Every member has at most one overlapping line of each People relationship type. Primary/dotted lines alone drive manager People scope; HR-partner/approval relationships remain workflow metadata.
- Assignment manager columns are frozen assignment snapshots. New approved assignment changes project idempotently into canonical reporting-line history; existing discrepancies require reviewed reconciliation, not silent overwrite.
- Every approved acting appointment has one acting assignment and one restoration assignment, both tied to the same employment spell. Pending requests have neither. Acting intervals do not overlap for one Staff member and cannot consume unapproved position capacity.
- Every Staff/position/spell custom value references an active definition of the matching owner type and passes its frozen definition version before value operations are enabled.
- Every governed Staff value has a checksum, effective date, monotonically increasing value version, exact definition version, encrypted before/after events, and reasoned actor/idempotency evidence. A later definition version marks the current value stale until reviewed and rewritten; it does not silently revalidate ciphertext.

## Rollback and incident response

- Before any organization command has written an event, schema rollback may remove the unused follow-on tables/columns after rehearsal. The job/position rollback additionally refuses while cross-company duplicate position numbers cannot fit the former global constraint.
- Once retained events exist, the migration deliberately refuses destructive rollback. Disable `HR_PEOPLE_CORE_ENABLED`, preserve the schema/history, correct through a new version, and restore service after reconciliation.
- Once any custom-field value or access event exists, its follow-on migration refuses rollback. Feature-disable People writes, preserve ciphertext and access history, and correct through a new authorized value version.
- Never delete or rewrite an event to repair a hierarchy. Create a reasoned corrective version.
- If a cycle, cross-company reference or version gap is detected, disable People Core writes, retain reads where privacy-safe, capture affected IDs/counts, reconcile from events, and require HR/Security approval before reactivation.
- If manager termination leaves members without a primary line, retain the closed line and expose the resulting unassigned hierarchy for HR correction; never guess a replacement manager.
