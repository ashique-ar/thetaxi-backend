# Single Installation Company Cleanup

## Target model

Each customer buys and hosts an independent installation with its own database. The installation is the software customer's boundary; it is not represented by a row in `companies`.

`companies` remains a location directory used by booking search and booking dynamic-form location search. It is not a tenant, legal entity, operating-location selector, or ownership record. Do not add `company_id` fields to downstream tables. Corporate customers remain separate records in `corporates` and are not locations or software tenants.

Staff, HR, bookings, customers, reports, settings, and integrations are shared across locations within one installation. Roles and permissions still control actions. Location references must not be used to hide records or restrict staff access. Website content and service selection may still use location-specific configuration where that is an actual product feature.

## Cleanup rules

- Remove company equality and company query filters that partition one installation's data.
- Remove tenant-only `company_id` fields from all non-location tables and API contracts. Keep company records only for booking location search.
- Remove staff and HR legal-entity assignment and access rules; retain organization, team, role, and permission rules that are not based on `companies`.
- Store fleet and provider integration settings once per installation. Never synthesize or require a default company ID.
- Preserve the `corporates` customer model and its booking/payment relationships.
- Preserve historical values during rollout. Add forward migrations; do not edit already-released migrations or delete ambiguous configuration/data without a deterministic migration path.
- Rename UI labels from legal entity/business tenant to location where the control is actually selecting a `companies` record.

## Work sequence

1. Inventory each `company_id` field and caller as access partition, location, configuration, or historical/audit data.
2. Remove shared access partitioning and company equality rules; retain location lookup and location-specific behavior.
3. Make staff/HR operations common to the installation and remove company assignment from staff flows.
4. Convert Wialon, Hikvision, CRM/webhook and other integration configuration to installation-wide ownership, preserving old rows until any conflicts are resolved.
5. Update API contracts, portal selectors, background commands, reports, and customer-facing labels.
6. Add forward-only schema migrations for fields and constraints that no longer have meaning. Keep location references only where the inventory confirms operational use.
7. Update architecture and implementation plans, then verify representative booking, staff, reporting, location, and integration flows.

## Field inventory

This is a semantic inventory, not an instruction to drop every matching column:

| Area | Current meaning of `company_id` | Disposition |
|---|---|---|
| `bookings` | Tenant key added by the company-scoping pass | Remove. Booking addresses and coordinates remain in booking item/location fields. |
| `vehicles` and `vehicle_companies` | Fleet operating location assignment | Keep where the fleet workflows use it as the vehicle's real location; integration credentials must be stored separately. |
| `corporates` | Tenant/access ownership added by the single-company pass | Removed from the model and schema; corporate booking relationships remain. |
| `booking_generated_reports` | Report visibility/ownership only | Removed from model and schema; a report's saved location filter remains in its JSON filters. |
| `staff`, HR employment, organization, payroll, attendance and HR audit tables | Legal-entity partition inherited from the tenant design. HR already has separate `location_code`, organization-unit, site-code, and work-location fields for actual placement. | Remove the company assignment, scope checks, default-company requirement, and tenant-only columns. Preserve employment history, audit facts, and actual HR location fields. |
| Wialon settings | Per-company integration ownership | Moved to one installation settings row. Vehicle location remains independent. |
| Hikvision/attendance connector, device, event and result tables | Per-company configuration and event partition | Convert connector/device configuration to installation-wide; retain device site/timezone and staff/organization mapping as operational facts. Remove company fields from event data only after all consumers are converted. |
| Sales, inquiry, customer and phone-call tables | Tenant key added by company-scoping pass | Remove from tables and payloads; keep source/channel and actual address/location details. |
| Company `is_default` and default-company resolver | Implicit tenant selection | Remove after all callers stop requiring a default. Never backfill a replacement default ID. |

The broad code scan currently finds company-ID use in 312 application files. The remaining HR fields are confirmed tenant/legal-entity fields; the distinct HR `location_code`, `site_code`, and organization-unit data must be kept. CRM and Sales rows remain a write-path review item because their stored value may be a useful location attribution even though its access checks are invalid.

### HR schema migration guardrails

The HR People Core schema has company-scoped unique keys such as organization-unit, job-family, designation, and employment-type codes. Removing `company_id` changes those from per-company uniqueness to installation-wide uniqueness. Before the forward migration drops those keys, it must detect duplicate codes and stop with the affected table/code pairs for review; it must not silently pick a row or discard employment, assignment, reporting-line, or rehire history. The service layer must stop writing company IDs in the same release that drops those columns. HR's actual work location stays in assignment `location_code` and device `site_code` fields.

## Progress

- The target model and cleanup rules above reflect the product boundary confirmed on 30 October 2026.
- The shared `CompanyIdScope` helpers no longer filter records by location/company ID. `CompanyCustomerScope` no longer requires a customer to have a company-linked relationship before it is visible.
- Staff create/update requests and the staff editor no longer assign a location; company-based staff access checks have been removed from the changed staff access paths.
- The default-location resolver now returns no location, company option APIs no longer advertise or configure a default location, and inquiry creation no longer adds a default company ID.
- Corporate customer records no longer use company-based filtering or assignment. A forward migration removes their tenant-only `company_id` field.
- Company settings access and corporate middleware no longer compare staff or corporate records against a location. Booking responsibility assignment now sees eligible staff across locations.
- Staff factories and seeders no longer fabricate or assign company IDs; new staff records are created for the installation as a whole.
- Booking lists, owner assignment, analytics, and quotation/customer selection no longer require a default company context. Booking approval recipients and assignment targets are installation-wide; explicit booking location IDs remain available as operational data.
- Default-company pricing waivers now recognize any configured company location by coordinates; booking location selectors no longer return an `is_default` flag.
- The company/location model no longer accepts `is_default`, and the portal location editor no longer sends or displays a default-company toggle. Compatibility callers still receive no default location while remaining flows are converted.
- Sales profile access now follows staff/profile permissions and team reporting relationships across the installation, without requiring a selected company or filtering profiles and reporting lines by company ID.
- Wialon reads and writes one installation-wide record with no company/location ID. A new forward migration copies the oldest existing integration deterministically and preserves all legacy rows. Fleet sync runs once per installation.
- Wialon service methods and their API, command, vehicle handover/lease, and booking mileage callers no longer accept or pass placeholder company IDs. Vehicle location IDs remain separate from fleet integration configuration.
- Staff context creation no longer requires or injects a default location. HR employee-number aliases are now company-free, and sequence allocation uses one installation-keyed counter. A forward migration consolidates compatible counters, preserves alias rows and sequence IDs, and refuses to run if existing sequence formats conflict.
- Staff termination evidence no longer stores a company ID; its forward migration drops that tenant key and index while retaining the termination evidence. Staff identity termination no longer requires a company row, and assignment history uses the associated booking, inquiry, or call location where available.
- HR People access no longer requires an active Staff company ID or scopes self/team queries by company. The existing role and reporting-line checks remain; the focused feature test covers a staff identity without a company ID. Other HR controllers still call `actorCompanyId()` and require coordinated cleanup.
- Booking payment receipt processing and WebXPay callback handling no longer synthesize default-company Sales attributions or reject payments because company attribution is missing or differs. Existing location references remain on bookings and their operational records.
- Generated booking reports no longer require a selected location to list, generate, download, or delete reports. Their tenant-only `company_id` column is removed by a forward migration; saved `filters.company_id` remains available as an operational location filter when a location was selected.
- PHP syntax checks passed for the changed PHP files and `git diff --check` passed. The full `StaffInstallationContextTest` passed (3 tests, 6 assertions), including the no-company HR access case and installation-wide employee numbering. `RefreshDatabase` applied the migrations against SQLite; production database migrations have not been run. An earlier run exposed SQLite-incompatible foreign-key drops in HR migrations; these now use Laravel's constrained-column API.

## Remaining work

The cleanup is not complete. A full table-by-table schema and caller inventory is still in progress. HR still has company-based workflows and schema fields beyond employee numbering and termination evidence; Sales, CRM/inquiry history, several integration settings, direct filters, APIs, and portal flows also need cleanup. Company `is_default` remains queried by older sales/HR and integration code, so its database column cannot yet be dropped safely. The Wialon migration intentionally leaves non-selected legacy integration rows and the old company reference in place; those are preserved data, not active installation settings. The location model retains its historical marker column until those callers are removed.
