# Driver Assignment Notification Handoff

The driver mobile client is maintained outside this repository. This document is the implementation contract for the remaining assignment-inbox UI.

## Inbox and push

Use `GET /api/driver/assignments` as the operational inbox and `GET /api/driver/assignments/current` for the active assignment. Each assignment payload includes:

- `booking_number`, `scheduled_datetime`, `customer_name`;
- `pickup_location_label`, `dropoff_location_label`;
- `notification_id` and `notification.acknowledgement_required`;
- the canonical assignment and booking-item identifiers.

Push payloads use the same identifiers and display fields. Opening a push must navigate to the matching assignment rather than creating a separate local assignment.

## Acknowledge on open

When the assignment detail is opened, call:

`POST /api/driver/assignments/{assignment_id}/acknowledge`

The endpoint is authenticated, assignment-owner scoped, and idempotent. A repeated request returns the existing acknowledgement timestamp. The client may safely retry it after reconnecting. Acceptance also acknowledges server-side, so a delayed open acknowledgement cannot schedule another fallback.

## Canonical lifecycle actions

Do not mutate lifecycle state locally. Continue using the existing driver endpoints for:

- assignment acceptance and decline;
- dispatch/trip status;
- pickup arrival;
- trip start;
- stop arrival/completion for multi-stop work;
- trip completion.

Refresh the assignment/status response after every successful mutation and use its `allowed_actions` to render the next action.

## Offline and duplicate taps

- Disable an action while its request is in flight.
- Persist only the acknowledgement request for retry after reconnecting.
- Do not blindly replay lifecycle mutations; refresh status first and suppress an action that is no longer allowed.
- Treat an already-applied transition response as success and refresh the canonical assignment.
- Show an offline banner and retain the last read-only assignment payload until refresh succeeds.
