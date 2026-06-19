# Company Driver Mobile API

Postman assets for testing the Company driver mobile backend.

Interactive browser docs:
- `/docs/driver-mobile-api.html`
- OpenAPI spec: `/docs/driver-mobile-api.openapi.json`

The browser docs are generated from the full driver Postman collection, so the mobile developer can read and try all driver mobile requests there.

Files:
- `Driver-API.postman_collection.json`
- `Driver-API.postman_environment.json`

Base URLs:
- Driver API: `{{base_url}}/api/driver`
- Public compatibility API: `{{base_url}}/api/public`

Most driver endpoints require:

```http
Authorization: Bearer {{access_token}}
Accept: application/json
Content-Type: application/json
```

Public endpoints:
- `POST /api/driver/version-check`
- `POST /api/public/driver-mobile/version-check`
- `POST /api/driver/auth/login`

## Quick Start

1. Import both Postman JSON files.
2. Select **Company Driver API - Development** environment.
3. Set `base_url`, `driver_email`, `driver_password`, and device variables.
4. Run **App Settings > Version Check**.
5. Run **Authentication > Login**. The collection saves `access_token`, `refresh_token`, `driver_id`, `user_id`, `device_uuid`, and `assignment_id` where present.
6. Run authenticated requests.

## Environment Variables

Authentication:
- `access_token`
- `refresh_token`
- `driver_id`
- `user_id`

App and device:
- `app_version`
- `app_build`
- `platform`
- `os_version`
- `device_uuid`
- `device_fingerprint`
- `device_name`
- `device_model`
- `device_manufacturer`
- `push_token`
- `push_provider`
- `latest_driver_app_version`
- `driver_app_can_continue`

Session and location:
- `session_id`
- `session_status`
- `start_latitude`
- `start_longitude`
- `current_latitude`
- `current_longitude`
- `end_latitude`
- `end_longitude`
- `history_assignment_id`
- `history_session_id`
- `history_from`
- `history_to`
- `history_limit`

Assignments and trips:
- `assignment_id`
- `assignment_status`
- `stop_id`
- `booking_stop_id`
- `open_package_trip_mode`
- `open_package_id`
- `final_address`
- `ending_mileage`
- `trip_end_notes`
- `collected_amount`
- `payment_notes`
- `filter_date`
- `filter_from`
- `filter_to`

Notifications:
- `notification_id`
- `notification_per_page`
- `notification_unread_only`
- `notification_type`

## Endpoint Index

### App Settings

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| POST | `/api/driver/version-check` | No | Primary pre-login app version check |
| POST | `/api/public/driver-mobile/version-check` | No | Compatibility alias |

### Authentication

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| POST | `/api/driver/auth/login` | No | Login and register/update device |
| GET | `/api/driver/auth/profile` | Yes | Current driver profile and assignment stats |
| POST | `/api/driver/auth/refresh` | Yes | Refresh access token |
| POST | `/api/driver/auth/logout` | Yes | Revoke current token/session |

### Status, Session, and Location

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| POST | `/api/driver/status/online` | Yes | Start online session |
| POST | `/api/driver/status/offline` | Yes | End online session |
| GET | `/api/driver/status` | Yes | Current online/session status |
| POST | `/api/driver/heartbeat` | Yes | Keep driver active |
| POST | `/api/driver/location` | Yes | Update current GPS point |
| POST | `/api/driver/location/bulk` | Yes | Upload buffered GPS points |
| GET | `/api/driver/location/history` | Yes | Route history by session or assignment |
| GET | `/api/driver/sessions` | Yes | List driver sessions |
| GET | `/api/driver/sessions/{session_id}` | Yes | Session detail with route replay |

### Devices

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| GET | `/api/driver/devices` | Yes | List registered devices |
| GET | `/api/driver/devices/current` | Yes | Current device |
| PUT | `/api/driver/devices` | Yes | Update/register device info |
| POST | `/api/driver/devices/push-token` | Yes | Update device push token |
| POST | `/api/driver/devices/{device_uuid}/deactivate` | Yes | Deactivate device |
| DELETE | `/api/driver/devices/{device_uuid}` | Yes | Remove device |

### Notifications

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| GET | `/api/driver/notifications` | Yes | List driver notifications |
| GET | `/api/driver/notifications/unread-count` | Yes | Unread count |
| GET | `/api/driver/notifications/{notification_id}` | Yes | Notification detail |
| POST | `/api/driver/notifications/{notification_id}/mark-read` | Yes | Mark one as read |
| POST | `/api/driver/notifications/mark-all-read` | Yes | Mark all as read |
| DELETE | `/api/driver/notifications/{notification_id}` | Yes | Delete one notification |

### Assignments, Hires, and Earnings

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| GET | `/api/driver/assignments` | Yes | List assignments |
| GET | `/api/driver/assignments/current` | Yes | Current assignment |
| POST | `/api/driver/assignments/{assignment_id}/accept` | Yes | Accept assignment |
| POST | `/api/driver/assignments/{assignment_id}/decline` | Yes | Decline assignment |
| GET | `/api/driver/hires` | Yes | Completed hires |
| GET | `/api/driver/earnings/summary` | Yes | Today/week/month earnings |
| GET | `/api/driver/earnings/daily` | Yes | Earnings for one date |
| GET | `/api/driver/earnings/range` | Yes | Earnings for date range |

### Trip Tracking

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| GET | `/api/driver/assignments/{assignment_id}/status` | Yes | Assignment trip state |
| POST | `/api/driver/assignments/{assignment_id}/arrived` | Yes | Confirm pickup arrival |
| POST | `/api/driver/assignments/{assignment_id}/start` | Yes | Start hire |
| POST | `/api/driver/assignments/{assignment_id}/stops/{stop_id}/arrived` | Yes | Mark route stop arrived |
| POST | `/api/driver/assignments/{assignment_id}/stops/{stop_id}/picked-up` | Yes | Complete pickup stop |
| POST | `/api/driver/assignments/{assignment_id}/stops/{stop_id}/dropped-off` | Yes | Complete dropoff stop |
| POST | `/api/driver/assignments/{assignment_id}/stops/{stop_id}/skip` | Yes | Skip route stop |
| POST | `/api/driver/assignments/{assignment_id}/complete` | Yes | Complete hire and calculate final amount |
| POST | `/api/driver/assignments/{assignment_id}/collect-payment` | Yes | Record driver cash collection when required |

### Open Package Chauffeur Flow

Use this flow for day/month chauffeur packages where the booking has a pickup point and selected package, but no fixed final destination at booking time.

| Method | Endpoint | Auth | Purpose |
|---|---|---:|---|
| GET | `/api/driver/assignments/current` | Yes | Detect open package assignment |
| GET | `/api/driver/assignments/{assignment_id}/status` | Yes | Read current trip phase and package usage |
| POST | `/api/driver/assignments/{assignment_id}/arrived` | Yes | Confirm arrival at pickup |
| POST | `/api/driver/assignments/{assignment_id}/start` | Yes | Start billable package tracking |
| POST | `/api/driver/location` | Yes | Send live GPS route points |
| POST | `/api/driver/location/bulk` | Yes | Sync offline buffered route points |
| POST | `/api/driver/assignments/{assignment_id}/complete` | Yes | End package and calculate final charges |
| POST | `/api/driver/assignments/{assignment_id}/collect-payment` | Yes | Record cash collection when required |

Mobile app branch condition:

```text
data.trip_mode == "open_package" && data.destination_known == false
```

When this condition is true, the app must not require or draw a fixed destination route. Show pickup navigation before start, show package details, keep GPS tracking active during the service, and complete the trip using the final device coordinates/address.

## API Details

### Version Check

Use before login or app bootstrap.

```http
POST /api/driver/version-check
```

Request:

```json
{
  "version": "1.0.0",
  "platform": "android"
}
```

Response:

```json
{
  "status": "success",
  "data": {
    "current_version": "1.0.0",
    "latest_version": "1.1.0",
    "update_required": true,
    "mandatory_update": true,
    "can_continue": false,
    "message": "A new driver app version is available. Please update to continue."
  }
}
```

Mobile behavior:
- If `can_continue` is `true`, continue app flow.
- If `can_continue` is `false`, block usage and route to Play Store/App Store inside the app.
- The API does not return a store URL.

Admin settings live in the portal under **System Settings > Driver Mobile**.

### Login

```http
POST /api/driver/auth/login
```

Request:

```json
{
  "email": "{{driver_email}}",
  "password": "{{driver_password}}",
  "device_fingerprint": "{{device_fingerprint}}",
  "device_name": "{{device_name}}",
  "device_model": "{{device_model}}",
  "device_manufacturer": "{{device_manufacturer}}",
  "platform": "{{platform}}",
  "os_version": "{{os_version}}",
  "app_version": "{{app_version}}",
  "app_build": "{{app_build}}",
  "push_token": "{{push_token}}",
  "push_provider": "{{push_provider}}",
  "locale": "en_US",
  "timezone": "Asia/Colombo"
}
```

Response:

```json
{
  "status": "success",
  "message": "Login successful",
  "data": {
    "user": {
      "id": "user-uuid",
      "email": "driver@example.com"
    },
    "driver": {
      "id": "driver-uuid",
      "code": "DRV001",
      "is_online": false,
      "availability_status": "available"
    },
    "device": {
      "id": "device-record-uuid",
      "device_uuid": "device-uuid",
      "platform": "ios",
      "app_version": "1.0.0",
      "is_active": true
    },
    "token": {
      "access_token": "access-token",
      "token_type": "Bearer",
      "expires_at": "2026-05-22T08:00:00+05:30",
      "refresh_token": "refresh-token-id"
    },
    "current_assignment": null,
    "trip_phase": null
  }
}
```

Error:

```json
{
  "status": "error",
  "message": "Invalid credentials",
  "error_code": "AUTH_INVALID_CREDENTIALS",
  "errors": {}
}
```

Login is rate-limited to 5 failed attempts per minute per email/IP.

### Profile

```http
GET /api/driver/auth/profile
```

Response:

```json
{
  "status": "success",
  "data": {
    "user": {
      "id": "user-uuid",
      "email": "driver@example.com"
    },
    "driver": {
      "id": "driver-uuid",
      "code": "DRV001",
      "license_no": "B1234567",
      "is_online": true,
      "current_latitude": "6.9285",
      "current_longitude": "79.8625"
    },
    "assignment_statistics": {
      "total_assignments": 25,
      "active_assignments": 2,
      "completed_assignments": 20
    }
  }
}
```

### Refresh Token

```http
POST /api/driver/auth/refresh
```

Request:

```json
{
  "refresh_token": "{{refresh_token}}"
}
```

Response:

```json
{
  "status": "success",
  "message": "Token refreshed successfully",
  "data": {
    "access_token": "new-access-token",
    "token_type": "Bearer",
    "expires_at": "2026-05-22T08:00:00+05:30",
    "refresh_token": "refresh-token-id"
  }
}
```

### Logout

```http
POST /api/driver/auth/logout
```

Request:

```json
{
  "device_uuid": "{{device_uuid}}"
}
```

Response:

```json
{
  "status": "success",
  "message": "Logged out successfully"
}
```

### Go Online

```http
POST /api/driver/status/online
```

Request:

```json
{
  "device_uuid": "{{device_uuid}}",
  "latitude": 6.9271,
  "longitude": 79.8612,
  "metadata": {
    "app_version": "1.0.0",
    "os": "ios 17.0"
  }
}
```

Response:

```json
{
  "status": "success",
  "message": "Driver is now online",
  "data": {
    "session": {
      "id": "session-uuid",
      "driver_id": "driver-uuid",
      "device_uuid": "device-uuid",
      "status": "active",
      "start_time": "2026-05-21T08:00:00+05:30",
      "start_latitude": "6.9271",
      "start_longitude": "79.8612",
      "assignment_id": null
    },
    "pending_assignments": []
  }
}
```

Already online error:

```json
{
  "status": "error",
  "message": "Driver is already online",
  "error_code": "STATUS_ALREADY_ONLINE",
  "data": {
    "current_session": {}
  }
}
```

### Go Offline

```http
POST /api/driver/status/offline
```

Request:

```json
{
  "latitude": 6.935,
  "longitude": 79.85
}
```

Response:

```json
{
  "status": "success",
  "message": "Driver is now offline",
  "data": {
    "session": {
      "id": "session-uuid",
      "status": "completed",
      "end_time": "2026-05-21T10:00:00+05:30",
      "end_latitude": "6.935",
      "end_longitude": "79.85"
    }
  }
}
```

If a trip is still in progress, the response includes:

```json
{
  "trip_warning": "Trip is still in progress. The trip tracking session remains active.",
  "active_trip": {
    "assignment_id": "assignment-uuid",
    "trip_phase": "in_progress"
  }
}
```

### Current Status

```http
GET /api/driver/status
```

Response:

```json
{
  "status": "success",
  "data": {
    "is_online": true,
    "last_active_at": "2026-05-21T08:05:00+05:30",
    "current_latitude": "6.9285",
    "current_longitude": "79.8625",
    "current_device_uuid": "device-uuid",
    "current_session": {
      "id": "session-uuid",
      "status": "active"
    }
  }
}
```

### Heartbeat

```http
POST /api/driver/heartbeat
```

Request:

```json
{}
```

Response:

```json
{
  "status": "success",
  "message": "Heartbeat received",
  "data": {
    "last_active_at": "2026-05-21T08:05:00+05:30",
    "is_online": true,
    "trip_phase": "in_progress",
    "assignment_id": "assignment-uuid"
  }
}
```

### Location Update

```http
POST /api/driver/location
```

Request:

```json
{
  "latitude": 6.9285,
  "longitude": 79.8625,
  "altitude": 15.5,
  "speed": 45.2,
  "heading": 180.5,
  "accuracy": 10.0,
  "recorded_at": "2026-05-21T08:05:00+05:30"
}
```

Response:

```json
{
  "status": "success",
  "message": "Location updated successfully",
  "data": {
    "id": "route-point-uuid",
    "session_id": "session-uuid",
    "assignment_id": "assignment-uuid",
    "latitude": 6.9285,
    "longitude": 79.8625,
    "altitude": 15.5,
    "speed": 45.2,
    "heading": 180.5,
    "accuracy": 10.0,
    "recorded_at": "2026-05-21T08:05:00+05:30"
  }
}
```

Errors:
- `LOCATION_NO_SESSION`
- `LOCATION_RATE_LIMITED`

### Bulk Location Upload

```http
POST /api/driver/location/bulk
```

Request:

```json
{
  "locations": [
    {
      "latitude": 6.9271,
      "longitude": 79.8612,
      "altitude": 12.4,
      "speed": 0,
      "heading": 180,
      "accuracy": 8,
      "recorded_at": "2026-05-21T08:05:00+05:30",
      "assignment_id": "assignment-uuid"
    }
  ]
}
```

Rules:
- `locations` must contain 1 to 1000 points.
- Each point requires `latitude`, `longitude`, and `recorded_at`.
- Duplicate points are skipped.

Response:

```json
{
  "status": "success",
  "message": "Buffered locations processed successfully",
  "data": {
    "saved_count": 2,
    "skipped_count": 1,
    "duplicate_count": 1,
    "latest_saved_point": {
      "id": "route-point-uuid",
      "latitude": 6.9285,
      "longitude": 79.8625
    }
  }
}
```

### Location History

```http
GET /api/driver/location/history?assignment_id={{history_assignment_id}}&session_id={{history_session_id}}&from={{history_from}}&to={{history_to}}&limit={{history_limit}}
```

Response by assignment:

```json
{
  "status": "success",
  "data": {
    "scope": "assignment",
    "assignment_id": "assignment-uuid",
    "trip_phase": "in_progress",
    "session_id": "session-uuid",
    "route_points": [],
    "total_points": 0
  }
}
```

Response by session:

```json
{
  "status": "success",
  "data": {
    "scope": "session",
    "session_id": "session-uuid",
    "assignment_id": null,
    "session_status": "completed",
    "route_points": [],
    "total_points": 0
  }
}

```

### Sessions

```http
GET /api/driver/sessions?page=1&per_page=15&status={{session_status}}
GET /api/driver/sessions/{{session_id}}
```

List response:

```json
{
  "status": "success",
  "data": [
    {
      "id": "session-uuid",
      "driver_id": "driver-uuid",
      "device_uuid": "device-uuid",
      "status": "active",
      "start_time": "2026-05-21T08:00:00+05:30",
      "end_time": null
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

Detail response:

```json
{
  "status": "success",
  "data": {
    "session": {
      "id": "session-uuid",
      "status": "completed"
    },
    "route_points": [],
    "route_stats": {
      "total_distance_km": 12.5,
      "total_points": 75
    }
  }
}
```

### Devices

List:

```http
GET /api/driver/devices?active_only=false
```

Update device:

```http
PUT /api/driver/devices
```

Request:

```json
{
  "device_uuid": "{{device_uuid}}",
  "device_name": "{{device_name}}",
  "device_model": "{{device_model}}",
  "device_manufacturer": "{{device_manufacturer}}",
  "platform": "{{platform}}",
  "os_version": "{{os_version}}",
  "app_version": "{{app_version}}",
  "app_build": "{{app_build}}",
  "locale": "en_US",
  "timezone": "Asia/Colombo"
}
```

Push token request:

```json
{
  "device_uuid": "{{device_uuid}}",
  "push_token": "{{push_token}}",
  "push_provider": "{{push_provider}}"
}
```

Device response shape:

```json
{
  "status": "success",
  "data": {
    "id": "device-record-uuid",
    "driver_id": "driver-uuid",
    "device_uuid": "device-uuid",
    "device_name": "Test Device",
    "device_model": "iPhone 14 Pro",
    "device_manufacturer": "Apple",
    "platform": "ios",
    "platform_display": "iOS",
    "os_version": "17.0",
    "app_version": "1.0.0",
    "app_build": "100",
    "has_push_token": true,
    "push_provider": "fcm",
    "is_active": true,
    "last_active_at": "2026-05-21T08:05:00+05:30",
    "registered_at": "2026-05-20T10:00:00+05:30",
    "locale": "en_US",
    "timezone": "Asia/Colombo",
    "display_name": "Test Device (iPhone 14 Pro)"
  }
}
```

### Notifications

```http
GET /api/driver/notifications?page=1&per_page=20&unread_only=false&type=
GET /api/driver/notifications/unread-count
GET /api/driver/notifications/{{notification_id}}
POST /api/driver/notifications/{{notification_id}}/mark-read
POST /api/driver/notifications/mark-all-read
DELETE /api/driver/notifications/{{notification_id}}
```

List response:

```json
{
  "status": "success",
  "data": [
    {
      "id": "notification-uuid",
      "type": "App\\Notifications\\DriverAssignmentNotification",
      "notification_type": "driver_assignment",
      "title": "New assignment",
      "message": "You have a new booking assignment.",
      "data": {
        "assignment_id": "assignment-uuid"
      },
      "read": false,
      "read_at": null,
      "created_at": "2026-05-21T10:00:00+05:30"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1,
    "unread_count": 1
  }
}
```

### Assignments

```http
GET /api/driver/assignments?page=1&per_page=15&status={{assignment_status}}&date={{filter_date}}&from={{filter_from}}&to={{filter_to}}
GET /api/driver/assignments/current
POST /api/driver/assignments/{{assignment_id}}/accept
POST /api/driver/assignments/{{assignment_id}}/decline
```

Decline request:

```json
{
  "decline_reason": "Unable to take this trip"
}
```

Assignment response shape:

```json
{
  "status": "success",
  "data": {
    "id": "assignment-uuid",
    "driver_id": "driver-uuid",
    "booking_id": "booking-uuid",
    "booking_item_id": "item-uuid",
    "status": "active",
    "trip_phase": "accepted",
    "trip_mode": "fixed_route",
    "destination_known": true,
    "driver_message": null,
    "payment_type": "cash",
    "fare_amount": 12500,
    "total_amount": 12500,
    "currency": "LKR",
    "booking_number": "BK-2026-0001",
    "service_type_name": "Airport Transfer",
    "customer_name": "John Customer",
    "customer_phone": "+94771111111",
    "customer_email": "customer@example.com",
    "pickup_location_label": "Colombo Airport",
    "dropoff_location_label": "Hilton Colombo",
    "package": null,
    "is_multi_stop": true,
    "route_stops": [],
    "scheduled_from": "2026-05-21T08:00:00+05:30",
    "scheduled_to": "2026-05-21T10:00:00+05:30",
    "trip_completed_at": null
  }
}
```

Open package assignment response shape:

```json
{
  "status": "success",
  "data": {
    "id": "assignment-uuid",
    "trip_phase": "accepted",
    "trip_mode": "open_package",
    "destination_known": false,
    "driver_message": "Open package trip. Navigate to pickup only. Customer destination and route will be tracked after trip start.",
    "pickup_location_label": "Colombo Airport",
    "dropoff_location_label": null,
    "route_stops": [],
    "package": {
      "id": "package-uuid",
      "name": "Day Package - 8 Hours / 80 KM",
      "code": "DAY_8H_80KM",
      "included_km_per_day": 80,
      "included_km_per_package": 80,
      "included_hours": 8,
      "rate_type": "day"
    }
  }
}
```

List endpoints return:

```json
{
  "status": "success",
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 0
  }
}
```

### Hires

```http
GET /api/driver/hires?page=1&per_page=15&date={{filter_date}}&from={{filter_from}}&to={{filter_to}}
```

Returns completed assignment payloads using the same assignment response shape, with `trip_phase: "completed"`.

### Earnings

```http
GET /api/driver/earnings/summary
GET /api/driver/earnings/daily?date={{filter_date}}
GET /api/driver/earnings/range?from={{filter_from}}&to={{filter_to}}
```

Summary response:

```json
{
  "status": "success",
  "data": {
    "today": {
      "date": "2026-05-21",
      "total": 12500,
      "trip_count": 1
    },
    "this_week": {
      "from": "2026-05-18",
      "to": "2026-05-24",
      "total": 37500,
      "trip_count": 3
    },
    "this_month": {
      "from": "2026-05-01",
      "to": "2026-05-31",
      "total": 150000,
      "trip_count": 12
    },
    "currency": "LKR"
  }
}
```

Daily and range responses include `items`, which are completed assignment payloads.

### Trip Status

```http
GET /api/driver/assignments/{{assignment_id}}/status
```

Response:

```json
{
  "status": "success",
  "data": {
    "assignment_id": "assignment-uuid",
    "booking_id": "booking-uuid",
    "trip_phase": "in_progress",
    "is_multi_stop": true,
    "pickup_location": {
      "latitude": 7.1808,
      "longitude": 79.8841,
      "landmark": "Colombo Airport"
    },
    "pickup_arrival": {
      "arrived_at": "2026-05-21T08:10:00+05:30",
      "latitude": 7.1808,
      "longitude": 79.8841
    },
    "trip_started_at": "2026-05-21T08:15:00+05:30",
    "stops": [],
    "current_stop": null,
    "allowed_actions": [
      "stop_action"
    ],
    "estimated_distance_to_pickup_km": null,
    "near_pickup": false,
    "cumulative_distance_km": 12.5,
    "total_waiting_time_seconds": 300,
    "waiting_period_count": 1,
    "route_point_count": 75
  }
}
```

### Trip Lifecycle

Pickup arrival:

```http
POST /api/driver/assignments/{{assignment_id}}/arrived
```

```json
{
  "latitude": 7.181,
  "longitude": 79.8839
}
```

Start trip:

```http
POST /api/driver/assignments/{{assignment_id}}/start
```

```json
{}
```

Complete trip:

```http
POST /api/driver/assignments/{{assignment_id}}/complete
```

```json
{
  "latitude": 6.9344,
  "longitude": 79.8428,
  "ending_mileage": 125000,
  "notes": "Completed successfully"
}
```

The complete endpoint calculates and stores the final amount first. If the response returns `payment.collection_required: true`, show a cash collection step and call:

```http
POST /api/driver/assignments/{{assignment_id}}/collect-payment
```

```json
{
  "collected_amount": 12500,
  "payment_notes": "Cash collected by driver"
}
```

If `payment.collection_required` is false, do not collect cash. Follow `payment.collection_message`.

Complete response:

```json
{
  "status": "success",
  "message": "Trip completed",
  "data": {
    "assignment_id": "assignment-uuid",
    "booking_id": "booking-uuid",
    "booking_item_id": "item-uuid",
    "total_distance_km": 18.75,
    "total_duration_minutes": 95,
    "total_waiting_time_seconds": 300,
    "waiting_period_count": 1,
    "pickup_coordinates": {
      "latitude": 7.1808,
      "longitude": 79.8841
    },
    "dropoff_coordinates": {
      "latitude": 6.9344,
      "longitude": 79.8428
    },
    "route_point_count": 75,
    "hire_completed": true,
    "payment": {
      "payment_collection_method": "cash_to_driver",
      "payment_collection_status": "pending_collection",
      "payment_status": "pending",
      "final_amount": 12500,
      "amount_to_pay": 12500,
      "currency": "LKR",
      "collection_required": true,
      "collection_message": "Collect cash from customer",
      "amount_to_collect": 12500
    }
  }
}
```

### Open Package Trip Behavior

For open package trips, use the same lifecycle endpoints, but the mobile app must treat the trip as pickup-only until the customer ends the service.

Before trip start:
- Check `trip_mode`.
- If `trip_mode` is `open_package`, check `destination_known`.
- If `destination_known` is `false`, hide destination route/directions and navigate only to pickup.
- Show `package.name`, included KM/hours, and `driver_message`.

During the trip:
- Continue calling `POST /api/driver/location` while moving.
- Use `POST /api/driver/location/bulk` for offline buffered points.
- Do not call stop endpoints for open package trips because `route_stops` is empty.
- Use `GET /api/driver/assignments/{{assignment_id}}/status` to read `cumulative_distance_km`, `total_waiting_time_seconds`, `route_point_count`, and `allowed_actions`.

Complete request for open package:

```json
{
  "latitude": 6.9344,
  "longitude": 79.8428,
  "final_address": "Hilton Colombo, Colombo",
  "ending_mileage": 125000,
  "notes": "Customer ended the day package here"
}
```

For open package cash-to-driver hires, complete the trip first. Backend calculates `package_charges.final_total`, then returns `payment.collection_required`. If collection is required, call `collect-payment` with `payment.amount_to_collect` or `package_charges.final_total`.

Open package completion response includes `package_charges`:

```json
{
  "status": "success",
  "message": "Trip completed",
  "data": {
    "trip_mode": "open_package",
    "total_distance_km": 96.4,
    "total_duration_minutes": 545,
    "total_waiting_time_seconds": 1800,
    "dropoff_coordinates": {
      "latitude": 6.9344,
      "longitude": 79.8428
    },
    "package_charges": {
      "base_amount": 25000,
      "included_km": 80,
      "included_minutes": 480,
      "actual_distance_km": 96.4,
      "actual_duration_minutes": 545,
      "waiting_minutes": 30,
      "extra_km": 16.4,
      "extra_minutes": 65,
      "extra_distance_charge": 2460,
      "extra_duration_charge": 1300,
      "waiting_charge": 0,
      "extra_total": 3760,
      "final_total": 28760,
      "currency": "LKR"
    },
    "payment": {
      "payment_collection_method": "cash_to_driver",
      "payment_collection_status": "pending_collection",
      "payment_status": "pending",
      "final_amount": 28760,
      "amount_to_pay": 28760,
      "currency": "LKR",
      "collection_required": true,
      "collection_message": "Collect cash from customer",
      "amount_to_collect": 28760,
      "payment_collected_amount": null,
      "payment_notes": null
    }
  }
}
```

Cash collection response:

```json
{
  "status": "success",
  "message": "Payment collection recorded",
  "data": {
    "assignment_id": "assignment-uuid",
    "booking_id": "booking-uuid",
    "payment": {
      "payment_collection_method": "cash_to_driver",
      "payment_collection_status": "driver_collected",
      "payment_status": "paid",
      "final_amount": 28760,
      "amount_to_pay": 28760,
      "currency": "LKR",
      "collection_required": false,
      "collection_message": "Cash collected by driver",
      "amount_to_collect": null,
      "payment_collected_amount": 28760
    }
  }
}
```

### Multi-Stop Actions

Use `current_stop.id` from trip status as `{{stop_id}}`.

Mark arrived:

```http
POST /api/driver/assignments/{{assignment_id}}/stops/{{stop_id}}/arrived
```

```json
{
  "latitude": 6.9285,
  "longitude": 79.8625,
  "notes": "Arrived at route stop"
}
```

Complete pickup stop:

```http
POST /api/driver/assignments/{{assignment_id}}/stops/{{stop_id}}/picked-up
```

```json
{
  "latitude": 6.9285,
  "longitude": 79.8625,
  "notes": "Passenger picked up"
}
```

Complete dropoff stop:

```http
POST /api/driver/assignments/{{assignment_id}}/stops/{{stop_id}}/dropped-off
```

```json
{
  "latitude": 6.9285,
  "longitude": 79.8625,
  "notes": "Passenger dropped off"
}
```

Skip stop:

```http
POST /api/driver/assignments/{{assignment_id}}/stops/{{stop_id}}/skip
```

```json
{
  "latitude": 6.9285,
  "longitude": 79.8625,
  "reason": "Passenger did not arrive",
  "notes": "Waited and skipped stop"
}
```

Stop-action responses return full trip status plus `processed_stop`:

```json
{
  "status": "success",
  "message": "Stop arrival confirmed",
  "data": {
    "assignment_id": "assignment-uuid",
    "trip_phase": "in_progress",
    "current_stop": {},
    "allowed_actions": [
      "stop_action"
    ],
    "processed_stop": {
      "id": "stop-uuid",
      "booking_stop_id": "booking-item:item-uuid:pickup-client-stop-2",
      "type": "pickup",
      "type_sequence": 2,
      "route_order": 2,
      "status": "arrived",
      "label": "Pickup 2",
      "display_label": "Pickup 2",
      "address": "Passenger 2 pickup",
      "allowed_actions": [
        "picked_up",
        "skip"
      ]
    }
  }
}
```

Common trip stop errors:
- `TRIP_NOT_IN_PROGRESS`
- `STOP_ALREADY_COMPLETED`
- `STOP_INVALID_STATE`
- `STOP_OUT_OF_SEQUENCE`
- `STOP_TYPE_MISMATCH`
- `STOP_ARRIVAL_REQUIRED`
- `TRIP_STOPS_INCOMPLETE`

## Recommended Test Flows

### First App Launch

1. Version Check.
2. Login.
3. Get Profile.
4. Update Device.
5. Update Push Token.

### Online Session

1. Login.
2. Go Online.
3. Heartbeat.
4. Update Location.
5. Bulk Upload Buffered Locations if the device was offline.
6. Go Offline.

### Assignment and Hire

1. List Assignments.
2. Accept Assignment.
3. Get Trip Status.
4. Confirm Pickup Arrival.
5. Start Trip.
6. Process stops if `is_multi_stop` is true.
7. Complete Trip.
8. If `payment.collection_required` is true, collect cash and call Collect Cash Payment.
9. Check Hires.
10. Check Earnings.

### Open Package Hire

1. Get Current Assignment.
2. If `trip_mode` is `open_package` and `destination_known` is `false`, show pickup-only navigation.
3. Confirm Pickup Arrival.
4. Start Trip.
5. Send live location points every 10 seconds while moving.
6. Sync buffered location points after network recovery.
7. Complete Trip with final coordinates and optional `final_address`.
8. Read `package_charges` and `payment` from the completion response.
9. If `payment.collection_required` is true, collect cash and call Collect Cash Payment.

### Notifications

1. List Notifications.
2. Get Unread Count.
3. Show Notification.
4. Mark Notification Read.
5. Mark All Notifications Read.
6. Delete Notification when needed.

## Error Format

Most errors follow:

```json
{
  "status": "error",
  "message": "Human readable message",
  "error_code": "ERROR_CODE",
  "errors": {}
}
```

Validation errors return HTTP `422`.
Authentication failures return HTTP `401`.
Driver-context failures return HTTP `403`.
Missing records return HTTP `404`.

## Keeping Postman Live

For a browser-based API docs page, share:

```text
https://your-domain.com/docs/driver-mobile-api.html
```

The mobile developer can use **Authorize** to paste the driver bearer token and then run requests from the page.

Preferred option: create a Postman team workspace, import `Driver-API.postman_collection.json` and `Driver-API.postman_environment.json`, then invite the mobile developer. Changes sync automatically inside Postman and no zip is needed.

Repository option: keep these files committed under `public-thetaxi/docs/postman`. Share the Git repository or raw file URL with the mobile developer. They can import by URL in Postman and re-import when the file changes.

Hosted URL option: expose these JSON files from a protected internal URL, for example `/docs/postman/driver-api`. The mobile developer can import the URL directly into Postman. Protect it with authentication or IP restrictions if the API examples contain real environment values.

Recommended workflow for this project:
- Keep the source of truth in `public-thetaxi/docs/postman`.
- Also maintain a shared Postman workspace for day-to-day mobile testing.
- When backend endpoints change, update the repo JSON first, then import/sync the same JSON into the shared workspace.

## Version History

### v2.4 (2026-06-19)
- Changed driver payment flow: complete trip first, then collect cash only if `payment.collection_required` is true.
- Added `POST /api/driver/assignments/{assignment_id}/collect-payment`.
- Added final payment fields: `final_amount`, `amount_to_pay`, `currency`, `collection_required`, `collection_message`, and `amount_to_collect`.

### v2.3 (2026-06-19)
- Added open package chauffeur flow examples for mobile.
- Documented `trip_mode`, `destination_known`, package details, pickup-only navigation, live GPS tracking, final address, and `package_charges`.
- Added live Postman sharing guidance.

### v2.2 (2026-05-21)
- Rebuilt README with endpoint index, auth requirements, request examples, response examples, and workflow guidance.
- Updated docs for current request/response shapes across driver settings, auth, sessions, devices, notifications, assignments, trips, and earnings.

### v2.1 (2026-05-21)
- Added public driver mobile version-check endpoint.
- Added portal-managed latest version, mandatory update flag, and update message.
- Documented app-owned Play Store/App Store redirect behavior.

### v2.0 (2026-02-10)
- Added device fingerprint support.
- Backend generates device UUIDs.
- Updated login request/response format.
- Added current assignment in login response.

### v1.0 (2026-02-05)
- Initial driver mobile API collection.
