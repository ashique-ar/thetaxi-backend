# TheTaxi Driver Mobile App API Documentation

## Overview

This document provides comprehensive API documentation for the TheTaxi Driver Mobile Application. The API enables drivers to authenticate, manage their online/offline status, track locations, view session history, and manage devices.

**Base URL:** `https://api.thetaxi.lk` (Production) | `http://localhost:8000` (Development)

**API Version:** 1.0

**Authentication:** Laravel Sanctum (Token-based)

**Last Updated:** February 25, 2026

## Postman Collection

Import the Postman collection and environment files for easy testing:

- **Collection:** `public-thetaxi/docs/postman/TheTaxi-Driver-API.postman_collection.json`
- **Development Environment:** `public-thetaxi/docs/postman/TheTaxi-Driver-API.postman_environment.json`
- **Production Environment:** `public-thetaxi/docs/postman/TheTaxi-Driver-API-Production.postman_environment.json`

The collection includes automatic token management and pre-configured requests for all endpoints.

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [Status Management](#2-status-management)
3. [Heartbeat](#3-heartbeat)
4. [Location Tracking](#4-location-tracking)
5. [Sessions](#5-sessions)
6. [Device Management](#6-device-management)
7. [Booking Assignments](#7-booking-assignments)
8. [Trip Tracking](#8-trip-tracking)
9. [Public/Meter API](#9-publicmeter-api)
10. [Error Handling](#10-error-handling)
11. [Data Models](#11-data-models)
12. [Best Practices](#12-best-practices)

---

## Authentication Architecture

The Driver Mobile API uses **Laravel Sanctum** for token-based authentication with the following features:

- **Token Rotation:** Each login and refresh generates new access and refresh tokens
- **Single Session Enforcement:** Only one active session per driver (new login revokes previous tokens)
- **Token Expiration:** Access tokens expire after 1 hour (configurable)
- **Automatic Refresh:** Use refresh tokens to obtain new access tokens before expiration
- **Driver Context Validation:** Middleware ensures authenticated users have an active driver context

### Middleware Stack

All protected driver endpoints use the following middleware:

1. `auth:api` - Validates Sanctum bearer token
2. `ensure.driver` - Verifies user has an active driver context

## Common Headers

All authenticated requests must include:

```
Authorization: Bearer {access_token}
Content-Type: application/json
Accept: application/json
```

---

## 1. Authentication

### 1.1 Login

Authenticate a driver and receive a Sanctum token.

**Endpoint:** `POST /api/driver/auth/login`

**Authentication:** None required

**Rate Limiting:** 5 attempts per minute per email/IP combination

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | Yes | Driver's email address |
| password | string | Yes | Driver's password |
| device_uuid | string | Yes | Unique device identifier (UUID v4 recommended) |
| device_name | string | No | User-friendly device name |
| device_model | string | No | Device model (e.g., "iPhone 14 Pro") |
| device_manufacturer | string | No | Device manufacturer (e.g., "Apple") |
| platform | string | No | OS platform: `ios` or `android` |
| os_version | string | No | OS version (e.g., "17.0") |
| app_version | string | No | App version (e.g., "1.0.0") |
| app_build | string | No | App build number |

**Example Request:**

```json
{
    "email": "driver@example.com",
    "password": "your_password",
    "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "device_name": "John's iPhone",
    "device_model": "iPhone 14 Pro",
    "device_manufacturer": "Apple",
    "platform": "ios",
    "os_version": "17.0",
    "app_version": "1.0.0",
    "app_build": "100"
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Login successful",
    "data": {
        "token": {
            "access_token": "1|abc123xyz...",
            "refresh_token": "2|def456uvw...",
            "token_type": "Bearer",
            "expires_in": 3600
        },
        "user": {
            "id": "uuid-here",
            "email": "driver@example.com",
            "first_name": "John",
            "last_name": "Doe",
            "phone": "+94771234567",
            "avatar_url": "https://cdn.thetaxi.lk/avatars/..."
        },
        "driver": {
            "id": "driver-uuid-here",
            "code": "DRV001",
            "license_no": "B1234567",
            "license_expiry": "2027-12-31",
            "is_online": false,
            "last_active_at": null,
            "rating": 4.8,
            "total_trips": 150
        },
        "device": {
            "id": "device-uuid-here",
            "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "device_name": "John's iPhone",
            "device_model": "iPhone 14 Pro",
            "platform": "ios",
            "is_active": true,
            "registered_at": "2026-02-04T10:00:00Z"
        },
        "current_assignment": {
            "id": "assignment-uuid",
            "driver_id": "driver-uuid",
            "booking_id": "booking-uuid",
            "status": "active",
            "trip_phase": "accepted",
            "booking": {
                "id": "booking-uuid",
                "booking_number": "BK-2026-0001"
            },
            "booking_item": {
                "id": "item-uuid",
                "pickup_location": "Colombo Airport",
                "dropoff_location": "Hilton Colombo"
            }
        },
        "trip_phase": "accepted"
    }
}
```

> **Note:** `current_assignment` is the driver's active DriverAssignment (in accepted/pickup_arrived/in_progress trip phase) with booking and booking_item details. Returns `null` if no active assignment exists. `trip_phase` is the current trip lifecycle phase string, or `null`.

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 401 | AUTH_INVALID_CREDENTIALS | Invalid email or password |
| 422 | VALIDATION_ERROR | Missing or invalid fields |
| 429 | RATE_LIMITED | Too many login attempts |

**Important Notes:**
- Uses Laravel Sanctum for token-based authentication (not Passport OAuth2)
- Returns both access_token and refresh_token for token rotation
- Access tokens expire after 1 hour (configurable)
- When logging in from a new device, all previous session tokens are automatically revoked (single-session enforcement)
- Store both tokens securely on the device (e.g., Keychain on iOS, EncryptedSharedPreferences on Android)
- The device_uuid should be generated once and stored persistently on the device
- Use the refresh token endpoint to get a new access token before expiration

---

### 1.2 Refresh Token

Refresh the access token using the refresh token before it expires.

**Endpoint:** `POST /api/driver/auth/refresh`

**Authentication:** Required (Bearer token)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| refresh_token | string | Yes | The refresh token received during login |

**Example Request:**

```json
{
    "refresh_token": "2|def456uvw..."
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Token refreshed successfully",
    "data": {
        "access_token": "3|ghi789rst...",
        "refresh_token": "4|jkl012mno...",
        "token_type": "Bearer",
        "expires_in": 3600
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 401 | AUTH_REFRESH_FAILED | Invalid or expired refresh token |
| 403 | AUTH_NOT_DRIVER | User is not registered as a driver |

**Important Notes:**
- Refresh tokens should be used before the access token expires
- Each refresh generates a new access token AND a new refresh token
- Old refresh tokens are invalidated after use (token rotation)
- Implement automatic token refresh in your app when receiving 401 errors

---

### 1.3 Logout

Revoke the current authentication token.

**Endpoint:** `POST /api/driver/auth/logout`

**Authentication:** Required

**Request Body:** None

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Logged out successfully"
}
```

---

### 1.4 Get Profile

Retrieve the authenticated driver's profile information.

**Endpoint:** `GET /api/driver/auth/profile`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": {
        "user": {
            "id": "uuid-here",
            "email": "driver@example.com",
            "first_name": "John",
            "last_name": "Doe",
            "phone": "+94771234567",
            "avatar_url": "https://cdn.thetaxi.lk/avatars/..."
        },
        "driver": {
            "id": "driver-uuid-here",
            "code": "DRV001",
            "nic": "123456789V",
            "license_no": "B1234567",
            "license_expiry": "2027-12-31",
            "license_type": "Heavy Vehicle",
            "dob": "1990-05-15",
            "address": "123 Main Street, Colombo",
            "city": "Colombo",
            "postal_code": "00100",
            "is_online": true,
            "last_active_at": "2026-02-04T10:30:00Z",
            "current_latitude": 6.9271,
            "current_longitude": 79.8612,
            "rating": 4.8,
            "total_trips": 150,
            "total_distance": 5420.5
        },
        "assignment_statistics": {
            "total_assignments": 25,
            "active_assignments": 2,
            "completed_assignments": 20
        }
    }
}
```

> **Note:** `assignment_statistics` provides counts of the driver's total, active, and completed booking assignments.

---

## 2. Status Management

### 2.1 Go Online

Set the driver's status to online and start a new session.

**Endpoint:** `POST /api/driver/status/online`

**Authentication:** Required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| device_uuid | string | Yes | Current device identifier |
| latitude | number | No | Starting latitude (-90 to 90) |
| longitude | number | No | Starting longitude (-180 to 180) |
| metadata | object | No | Additional session metadata |

**Example Request:**

```json
{
    "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "latitude": 6.9271,
    "longitude": 79.8612,
    "metadata": {
        "app_version": "1.0.0",
        "os": "iOS 17.0"
    }
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Driver is now online",
    "data": {
        "session": {
            "id": "session-uuid-here",
            "driver_id": "driver-uuid-here",
            "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "status": "active",
            "start_time": "2026-02-04T10:30:00Z",
            "start_latitude": 6.9271,
            "start_longitude": 79.8612,
            "end_time": null,
            "end_latitude": null,
            "end_longitude": null,
            "total_distance_km": null
        },
        "pending_assignments": [
            {
                "id": "assignment-uuid",
                "status": "active",
                "trip_phase": "active",
                "booking": {
                    "id": "booking-uuid",
                    "booking_number": "BK-2026-0001"
                },
                "booking_item": {
                    "id": "item-uuid",
                    "pickup_location": "Colombo Airport",
                    "dropoff_location": "Hilton Colombo"
                }
            }
        ]
    }
}
```

> **Note:** `pending_assignments` is an array of DriverAssignments in active/pending_approval status. Empty array if no pending assignments exist.

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | STATUS_ALREADY_ONLINE | Driver is already online |
| 403 | STATUS_NOT_DRIVER | User is not registered as a driver |

---

### 2.2 Go Offline

Set the driver's status to offline and close the active session.

**Endpoint:** `POST /api/driver/status/offline`

**Authentication:** Required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| latitude | number | No | Ending latitude (-90 to 90) |
| longitude | number | No | Ending longitude (-180 to 180) |

**Example Request:**

```json
{
    "latitude": 6.9350,
    "longitude": 79.8500
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Driver is now offline",
    "data": {
        "session": {
            "id": "session-uuid-here",
            "driver_id": "driver-uuid-here",
            "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "status": "completed",
            "start_time": "2026-02-04T10:30:00Z",
            "start_latitude": 6.9271,
            "start_longitude": 79.8612,
            "end_time": "2026-02-04T14:45:00Z",
            "end_latitude": 6.9350,
            "end_longitude": 79.8500,
            "total_distance_km": 45.67
        }
    }
}
```

> **Note:** If a trip tracking session is active when going offline, the trip remains active and the response includes additional fields:
> - `trip_warning`: Warning message that the trip is still in progress
> - `active_trip`: Object with `assignment_id` and `trip_phase` of the active trip
> - `message` will include the warning text

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | STATUS_NOT_ONLINE | Driver is not currently online |

---

### 2.3 Get Current Status

Retrieve the driver's current online/offline status.

**Endpoint:** `GET /api/driver/status`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": {
        "is_online": true,
        "last_active_at": "2026-02-04T10:35:00Z",
        "current_latitude": 6.9280,
        "current_longitude": 79.8620,
        "current_device_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "current_session": {
            "id": "session-uuid-here",
            "status": "active",
            "start_time": "2026-02-04T10:30:00Z",
            "start_latitude": 6.9271,
            "start_longitude": 79.8612
        }
    }
}
```

---

## 3. Heartbeat

### 3.1 Send Heartbeat

Send a heartbeat signal to indicate the driver is still active. This prevents automatic offline marking.

**Endpoint:** `POST /api/driver/heartbeat`

**Authentication:** Required

**Request Body:** None (empty body or `{}`)

**Recommended Interval:** Every 60 seconds while online

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Heartbeat received",
    "data": {
        "last_active_at": "2026-02-04T10:35:00Z",
        "is_online": true,
        "trip_phase": "in_progress",
        "assignment_id": "assignment-uuid"
    }
}
```

> **Note:** `trip_phase` and `assignment_id` are only included when a trip tracking session is active (assignment in accepted/pickup_arrived/in_progress phase). When no trip is active, these fields are absent from the response.

**Important Notes:**
- If no heartbeat is received for 10 minutes (configurable), the driver will be automatically marked offline
- The heartbeat should be sent even when the app is in the background
- Consider using background location updates to trigger heartbeats

---

## 4. Location Tracking

### 4.1 Update Location

Send the driver's current GPS location. Creates a route point for the active session.

**Endpoint:** `POST /api/driver/location`

**Authentication:** Required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| latitude | number | Yes | Current latitude (-90 to 90) |
| longitude | number | Yes | Current longitude (-180 to 180) |
| altitude | number | No | Altitude in meters |
| speed | number | No | Speed in km/h (≥0) |
| heading | number | No | Heading in degrees (0-360) |
| accuracy | number | No | GPS accuracy in meters (≥0) |
| recorded_at | string | No | ISO 8601 timestamp of the reading |

**Recommended Interval:** Every 10 seconds while online and moving

**Example Request:**

```json
{
    "latitude": 6.9285,
    "longitude": 79.8625,
    "altitude": 15.5,
    "speed": 45.2,
    "heading": 180.5,
    "accuracy": 10.0,
    "recorded_at": "2026-02-04T10:35:30Z"
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Location updated successfully",
    "data": {
        "id": "route-point-uuid",
        "session_id": "session-uuid-here",
        "latitude": 6.9285,
        "longitude": 79.8625,
        "altitude": 15.5,
        "speed": 45.2,
        "heading": 180.5,
        "accuracy": 10.0,
        "recorded_at": "2026-02-04T10:35:30Z"
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | LOCATION_NO_SESSION | No active session - driver must go online first |
| 422 | VALIDATION_ERROR | Invalid coordinates |

---

### 4.2 Get Location History

Retrieve route points for the current active session.

**Endpoint:** `GET /api/driver/location/history`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": {
        "session_id": "session-uuid-here",
        "route_points": [
            {
                "id": "point-1-uuid",
                "latitude": 6.9271,
                "longitude": 79.8612,
                "speed": 0,
                "recorded_at": "2026-02-04T10:30:00Z"
            },
            {
                "id": "point-2-uuid",
                "latitude": 6.9280,
                "longitude": 79.8620,
                "speed": 35.5,
                "recorded_at": "2026-02-04T10:30:10Z"
            }
        ],
        "total_points": 2
    }
}
```

---

## 5. Sessions

### 5.1 List Sessions

Retrieve paginated list of driver's session history.

**Endpoint:** `GET /api/driver/sessions`

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | integer | 1 | Page number |
| per_page | integer | 15 | Items per page (max 100) |
| status | string | null | Filter by status: `active`, `completed`, `auto_closed` |

**Example Request:**

```
GET /api/driver/sessions?page=1&per_page=10&status=completed
```

**Success Response (200):**

```json
{
    "status": "success",
    "data": [
        {
            "id": "session-1-uuid",
            "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "status": "completed",
            "start_time": "2026-02-04T08:00:00Z",
            "end_time": "2026-02-04T12:30:00Z",
            "start_latitude": 6.9271,
            "start_longitude": 79.8612,
            "end_latitude": 6.9350,
            "end_longitude": 79.8500,
            "total_distance_km": 45.67
        },
        {
            "id": "session-2-uuid",
            "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "status": "completed",
            "start_time": "2026-02-03T09:00:00Z",
            "end_time": "2026-02-03T17:00:00Z",
            "start_latitude": 6.9100,
            "start_longitude": 79.8700,
            "end_latitude": 6.9200,
            "end_longitude": 79.8600,
            "total_distance_km": 78.23
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 5,
        "per_page": 10,
        "total": 48
    }
}
```

---

### 5.2 Get Session Details

Retrieve detailed information about a specific session including route points.

**Endpoint:** `GET /api/driver/sessions/{session_id}`

**Authentication:** Required

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| session_id | string (UUID) | The session's unique identifier |

**Success Response (200):**

```json
{
    "status": "success",
    "data": {
        "session": {
            "id": "session-uuid-here",
            "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "status": "completed",
            "start_time": "2026-02-04T08:00:00Z",
            "end_time": "2026-02-04T12:30:00Z",
            "start_latitude": 6.9271,
            "start_longitude": 79.8612,
            "end_latitude": 6.9350,
            "end_longitude": 79.8500,
            "total_distance_km": 45.67,
            "metadata": {
                "app_version": "1.0.0"
            }
        },
        "route_points": [
            {
                "id": "point-1-uuid",
                "latitude": 6.9271,
                "longitude": 79.8612,
                "altitude": 10.0,
                "speed": 0,
                "heading": 0,
                "accuracy": 5.0,
                "recorded_at": "2026-02-04T08:00:00Z"
            }
        ],
        "route_stats": {
            "total_points": 1620,
            "total_distance_km": 45.67,
            "average_speed_kmh": 32.5,
            "max_speed_kmh": 85.0,
            "duration_minutes": 270
        }
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 404 | SESSION_NOT_FOUND | Session not found or doesn't belong to driver |

---

## 6. Device Management

The device management endpoints allow drivers to view and manage their registered mobile devices.

### 6.1 List Devices

Retrieve all devices registered for the authenticated driver.

**Endpoint:** `GET /api/driver/devices`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": [
        {
            "id": "device-uuid-here",
            "driver_id": "driver-uuid-here",
            "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "device_name": "John's iPhone",
            "device_model": "iPhone 14 Pro",
            "device_manufacturer": "Apple",
            "platform": "ios",
            "platform_display": "iOS 17.0",
            "os_version": "17.0",
            "app_version": "1.0.0",
            "app_build": "100",
            "has_push_token": true,
            "push_provider": "apns",
            "is_active": true,
            "last_active_at": "2026-02-04T10:35:00Z",
            "registered_at": "2026-01-15T08:00:00Z",
            "locale": "en_US",
            "timezone": "Asia/Colombo",
            "display_name": "iPhone 14 Pro",
            "created_at": "2026-01-15T08:00:00Z",
            "updated_at": "2026-02-04T10:35:00Z"
        }
    ],
    "meta": {
        "total": 1,
        "active": 1,
        "inactive": 0
    }
}
```

---

### 6.2 Get Current Device

Retrieve information about the current device.

**Endpoint:** `GET /api/driver/devices/current`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": {
        "id": "device-uuid-here",
        "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "device_name": "John's iPhone",
        "device_model": "iPhone 14 Pro",
        "platform": "ios",
        "os_version": "17.0",
        "app_version": "1.0.0",
        "is_active": true,
        "last_active_at": "2026-02-04T10:35:00Z"
    }
}
```

---

### 6.3 Update Device

Update device information (app version, push token, etc.).

**Endpoint:** `PUT /api/driver/devices`

**Authentication:** Required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| device_name | string | No | User-friendly device name |
| app_version | string | No | Current app version |
| app_build | string | No | Current app build number |
| os_version | string | No | Current OS version |
| locale | string | No | Device locale (e.g., "en_US") |
| timezone | string | No | Device timezone |

**Example Request:**

```json
{
    "app_version": "1.1.0",
    "app_build": "110",
    "os_version": "17.1"
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Device updated successfully",
    "data": {
        "id": "device-uuid-here",
        "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "app_version": "1.1.0",
        "app_build": "110",
        "os_version": "17.1",
        "updated_at": "2026-02-04T11:00:00Z"
    }
}
```

---

### 6.4 Update Push Token

Update the push notification token for the current device.

**Endpoint:** `POST /api/driver/devices/push-token`

**Authentication:** Required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| push_token | string | Yes | Push notification token |
| push_provider | string | No | Provider: `fcm` (Android) or `apns` (iOS) |

**Example Request:**

```json
{
    "push_token": "fcm_token_abc123...",
    "push_provider": "fcm"
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Push token updated successfully",
    "data": {
        "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "has_push_token": true,
        "push_provider": "fcm"
    }
}
```

---

### 6.5 Deactivate Device

Deactivate a specific device.

**Endpoint:** `POST /api/driver/devices/{device_uuid}/deactivate`

**Authentication:** Required

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| device_uuid | string (UUID) | The device's unique identifier |

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Device deactivated successfully",
    "data": {
        "id": "device-uuid-here",
        "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "is_active": false
    }
}
```

---

### 6.6 Remove Device

Remove a device from the driver's account.

**Endpoint:** `DELETE /api/driver/devices/{device_uuid}`

**Authentication:** Required

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| device_uuid | string (UUID) | The device's unique identifier |

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Device removed successfully"
}
```

**Important Notes:**
- Removing a device will revoke all tokens associated with it
- The driver will need to log in again from that device
- This is useful for lost or stolen devices

---

## 7. Booking Assignments

### 7.1 List Assignments

Retrieve a paginated list of booking assignments for the authenticated driver.

**Endpoint:** `GET /api/driver/assignments`

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | integer | 1 | Page number |
| per_page | integer | 15 | Items per page (max 100) |
| status | string | null | Filter by status: `active`, `completed`, `pending_approval`, `cancelled` |

**Success Response (200):**

```json
{
    "status": "success",
    "data": [
        {
            "id": "assignment-uuid",
            "driver_id": "driver-uuid",
            "booking_id": "booking-uuid",
            "status": "active",
            "trip_phase": "accepted",
            "assigned_from": "2026-03-01T08:00:00Z",
            "assigned_to": "2026-03-01T18:00:00Z",
            "booking": {
                "id": "booking-uuid",
                "booking_number": "BK-2026-0001",
                "customer_name": "Customer Name"
            },
            "booking_item": {
                "id": "item-uuid",
                "service_type_id": "service-uuid",
                "pickup_location": "Colombo Airport",
                "dropoff_location": "Hilton Colombo",
                "pickup_latitude": 7.1808,
                "pickup_longitude": 79.8841,
                "dropoff_latitude": 6.9344,
                "dropoff_longitude": 79.8428,
                "from_date": "2026-03-01",
                "from_time": "08:00",
                "to_date": "2026-03-01",
                "to_time": "18:00"
            },
            "vehicle": {
                "id": "vehicle-uuid",
                "license_plate": "CAB-1234",
                "make": "Toyota",
                "model": "Prius",
                "vehicle_group": "Sedan",
                "fuel_type": "Hybrid",
                "transmission": "Automatic",
                "owner_type": "company",
                "owner_name": "TheTaxi Fleet"
            },
            "dispatch": {
                "dispatch_status": "dispatched",
                "dispatched_at": "2026-03-01T07:30:00Z",
                "fuel_level_out": 85.0,
                "mileage_out": 45230
            }
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

**Notes:**
- Each assignment includes the associated Booking, BookingItem, Vehicle (with owner info), and Dispatch details
- Vehicle `owner_type` is either `"driver"` (individual) or `"company"`, with the corresponding `owner_name`
- If no Vehicle is assigned, the `vehicle` field is `null`
- If no Dispatch record exists, the `dispatch` field is `null`

---

### 7.2 Get Current Assignment

Get the current active assignment for the authenticated driver.

**Endpoint:** `GET /api/driver/assignments/current`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": {
        "id": "assignment-uuid",
        "driver_id": "driver-uuid",
        "booking_id": "booking-uuid",
        "status": "active",
        "trip_phase": "active",
        "assigned_from": "2026-03-01T08:00:00Z",
        "assigned_to": "2026-03-01T18:00:00Z",
        "booking": {
            "id": "booking-uuid",
            "booking_number": "BK-2026-0001"
        },
        "booking_item": {
            "id": "item-uuid",
            "pickup_location": "Colombo Airport",
            "dropoff_location": "Hilton Colombo"
        }
    }
}
```

**No Active Assignment Response (200):**

```json
{
    "status": "success",
    "data": null
}
```

---

### 7.3 Accept Assignment

Accept a booking assignment and initiate trip tracking.

**Endpoint:** `POST /api/driver/assignments/{id}/accept`

**Authentication:** Required

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string (UUID) | The assignment's unique identifier |

**Request Body:** Empty (`{}`)

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Assignment accepted",
    "data": {
        "id": "assignment-uuid",
        "driver_id": "driver-uuid",
        "booking_id": "booking-uuid",
        "status": "confirmed",
        "trip_phase": "accepted",
        "confirmed_at": "2026-03-01T08:05:00Z",
        "confirmed_by": "user-uuid"
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | ASSIGNMENT_INVALID_STATE | Assignment is not in active/pending_approval status |
| 400 | ASSIGNMENT_ALREADY_CONFIRMED | Assignment has already been confirmed |
| 404 | ASSIGNMENT_NOT_FOUND | Assignment not found or doesn't belong to driver |

**Important Notes:**
- Accepting sets the assignment status to "confirmed" and trip_phase to "accepted"
- A Trip Tracking Session is initiated, linking the active DriverSession to the assignment
- All subsequent location updates (RoutePoints) will be linked to this assignment

---

### 7.4 Decline Assignment

Decline a booking assignment with a reason.

**Endpoint:** `POST /api/driver/assignments/{id}/decline`

**Authentication:** Required

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string (UUID) | The assignment's unique identifier |

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| decline_reason | string | Yes | Reason for declining (max 1000 characters) |

**Example Request:**

```json
{
    "decline_reason": "Vehicle not available at the scheduled time"
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Assignment declined",
    "data": {
        "id": "assignment-uuid",
        "driver_id": "driver-uuid",
        "booking_id": "booking-uuid",
        "status": "declined",
        "trip_phase": "declined",
        "decline_reason": "Vehicle not available at the scheduled time"
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | ASSIGNMENT_INVALID_STATE | Assignment is not in active/pending_approval status |
| 404 | ASSIGNMENT_NOT_FOUND | Assignment not found or doesn't belong to driver |
| 422 | VALIDATION_ERROR | Missing or invalid decline_reason |

---

## 8. Trip Tracking

### 8.1 Get Trip Status

Get the current trip status including phase, pickup location, distance, waiting time, and route point count.

**Endpoint:** `GET /api/driver/trip/status`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": {
        "assignment_id": "assignment-uuid",
        "booking_id": "booking-uuid",
        "trip_phase": "in_progress",
        "pickup_location": {
            "latitude": 7.1808,
            "longitude": 79.8841,
            "landmark": "Colombo Airport"
        },
        "pickup_arrival": {
            "arrived_at": "2026-03-01T08:15:00Z",
            "latitude": 7.1810,
            "longitude": 79.8839
        },
        "trip_started_at": "2026-03-01T08:20:00Z",
        "estimated_distance_to_pickup_km": null,
        "near_pickup": false,
        "cumulative_distance_km": 12.5,
        "total_waiting_time_seconds": 300,
        "waiting_period_count": 1,
        "route_point_count": 75
    }
}
```

**No Active Trip Response (200):**

```json
{
    "status": "success",
    "data": null
}
```

**Notes:**
- Active trip = assignment in accepted, pickup_arrived, or in_progress phase
- `estimated_distance_to_pickup_km` is calculated during "accepted" phase, null otherwise
- `near_pickup` is true when driver is within 200 meters of the pickup location
- `pickup_arrival` is null until the driver confirms arrival
- `trip_started_at` is null until the trip starts
- Waiting time is automatically detected during "in_progress" phase (no manual action needed)

---

### 8.2 Confirm Pickup Arrival

Confirm arrival at the pickup location.

**Endpoint:** `POST /api/driver/trip/pickup-arrived`

**Authentication:** Required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| latitude | number | Yes | Arrival latitude (-90 to 90) |
| longitude | number | Yes | Arrival longitude (-180 to 180) |

**Example Request:**

```json
{
    "latitude": 7.1810,
    "longitude": 79.8839
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Pickup arrival confirmed",
    "data": {
        "assignment_id": "assignment-uuid",
        "booking_id": "booking-uuid",
        "trip_phase": "pickup_arrived",
        "pickup_location": {
            "latitude": 7.1808,
            "longitude": 79.8841,
            "landmark": "Colombo Airport"
        },
        "pickup_arrival": {
            "arrived_at": "2026-03-01T08:15:00Z",
            "latitude": 7.1810,
            "longitude": 79.8839
        },
        "trip_started_at": null,
        "estimated_distance_to_pickup_km": null,
        "near_pickup": true,
        "cumulative_distance_km": 0,
        "total_waiting_time_seconds": 0,
        "waiting_period_count": 0,
        "route_point_count": 15
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | TRIP_NO_ACTIVE_SESSION | No active trip found |
| 400 | ASSIGNMENT_INVALID_STATE | Trip is not in "accepted" phase |
| 422 | VALIDATION_ERROR | Invalid coordinates |

**Important Notes:**
- Transitions trip phase from "accepted" to "pickup_arrived"
- The arrival coordinates are stored separately from the BookingItem's planned pickup location
- Records the actual arrival timestamp

---

### 8.3 Start Trip

Start the trip after confirming pickup arrival.

**Endpoint:** `POST /api/driver/trip/start`

**Authentication:** Required

**Request Body:** Empty (`{}`)

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Trip started",
    "data": {
        "assignment_id": "assignment-uuid",
        "booking_id": "booking-uuid",
        "trip_phase": "in_progress",
        "pickup_location": {
            "latitude": 7.1808,
            "longitude": 79.8841,
            "landmark": "Colombo Airport"
        },
        "pickup_arrival": {
            "arrived_at": "2026-03-01T08:15:00Z",
            "latitude": 7.1810,
            "longitude": 79.8839
        },
        "trip_started_at": "2026-03-01T08:20:00Z",
        "estimated_distance_to_pickup_km": null,
        "near_pickup": false,
        "cumulative_distance_km": 0,
        "total_waiting_time_seconds": 0,
        "waiting_period_count": 0,
        "route_point_count": 18
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | TRIP_NO_ACTIVE_SESSION | No active trip found |
| 400 | TRIP_PICKUP_NOT_CONFIRMED | Pickup arrival must be confirmed first |

**Important Notes:**
- Transitions trip phase from "pickup_arrived" to "in_progress"
- Records the trip start timestamp
- Waiting time detection begins automatically during "in_progress" phase

---

### 8.4 End Trip

End the trip and receive a trip summary.

**Endpoint:** `POST /api/driver/trip/end`

**Authentication:** Required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| latitude | number | Yes | Final dropoff latitude (-90 to 90) |
| longitude | number | Yes | Final dropoff longitude (-180 to 180) |

**Example Request:**

```json
{
    "latitude": 6.9344,
    "longitude": 79.8428
}
```

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Trip completed",
    "data": {
        "assignment_id": "assignment-uuid",
        "total_distance_km": 45.2,
        "total_duration_minutes": 95,
        "total_waiting_time_seconds": 600,
        "waiting_period_count": 2,
        "pickup_coordinates": {
            "latitude": 7.1810,
            "longitude": 79.8839
        },
        "dropoff_coordinates": {
            "latitude": 6.9344,
            "longitude": 79.8428
        },
        "route_point_count": 570
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | TRIP_NO_ACTIVE_SESSION | No active trip found |
| 400 | TRIP_NOT_IN_PROGRESS | Trip is not in "in_progress" phase |
| 422 | VALIDATION_ERROR | Invalid coordinates |

**Important Notes:**
- Transitions trip phase to "completed"
- Records final location, timestamp, and total trip distance
- Closes all open waiting time records and calculates final totals
- Disassociates the Trip Tracking Session, allowing the driver to accept new assignments
- Returns a comprehensive trip summary

---

## 9. Public/Meter API (Future Implementation)

These endpoints are planned for guest users (meter functionality) and will require a Device UUID header instead of authentication.

**Required Header:**
```
X-Device-UUID: {device_uuid}
```

**Planned Endpoints:**
- `POST /api/public/meter/start` - Start a new meter session
- `POST /api/public/meter/stop` - Stop the active meter session
- `POST /api/public/meter/location` - Update location during meter session
- `GET /api/public/meter/estimate` - Get fare estimate based on distance

**Note:** These endpoints are not yet implemented.

---

## 10. Error Handling

### Standard Error Response Format

All error responses follow this format:

```json
{
    "status": "error",
    "message": "Human-readable error message",
    "error_code": "MACHINE_READABLE_CODE",
    "errors": {
        "field_name": ["Validation error message"]
    }
}
```

### HTTP Status Codes

| Status | Description |
|--------|-------------|
| 200 | Success |
| 400 | Bad Request - Invalid input or business logic error |
| 401 | Unauthorized - Invalid or missing token |
| 403 | Forbidden - User lacks permission |
| 404 | Not Found - Resource doesn't exist |
| 422 | Unprocessable Entity - Validation errors |
| 429 | Too Many Requests - Rate limited |
| 500 | Internal Server Error |

### Common Error Codes

| Error Code | HTTP Status | Description |
|------------|-------------|-------------|
| AUTH_INVALID_CREDENTIALS | 401 | Invalid email or password |
| AUTH_TOKEN_EXPIRED | 401 | Token has expired - use refresh token |
| AUTH_TOKEN_REVOKED | 401 | Token has been revoked - login required |
| AUTH_REFRESH_FAILED | 401 | Refresh token is invalid or expired |
| AUTH_NOT_DRIVER | 403 | User is not registered as a driver |
| STATUS_ALREADY_ONLINE | 400 | Driver is already online |
| STATUS_NOT_ONLINE | 400 | Driver is not currently online |
| LOCATION_NO_SESSION | 400 | No active session for location update |
| SESSION_NOT_FOUND | 404 | Session not found |
| DEVICE_UUID_REQUIRED | 400 | Missing Device UUID header |
| VALIDATION_ERROR | 422 | Request validation failed |
| ASSIGNMENT_INVALID_STATE | 400 | Assignment is not in a valid state for the requested operation (accept/decline requires active or pending_approval status) |
| ASSIGNMENT_ALREADY_CONFIRMED | 400 | Assignment has already been confirmed by the driver |
| ASSIGNMENT_NOT_FOUND | 404 | Assignment not found or doesn't belong to the authenticated driver |
| TRIP_NO_ACTIVE_SESSION | 400 | No active trip tracking session found for the driver |
| TRIP_PICKUP_NOT_CONFIRMED | 400 | Cannot start trip — pickup arrival must be confirmed first (trip phase must be pickup_arrived) |
| TRIP_NOT_IN_PROGRESS | 400 | Cannot end trip — trip is not in the in_progress phase |
| LOCATION_RATE_LIMITED | 429 | Location update sent faster than the 10-second minimum interval |

---

## 11. Data Models

### User Object

```typescript
interface User {
    id: string;           // UUID
    email: string;
    first_name: string;
    last_name: string;
    phone?: string;
    avatar_url?: string;
}
```

### Driver Object

```typescript
interface Driver {
    id: string;                    // UUID
    code: string;                  // Driver code (e.g., "DRV001")
    nic?: string;                  // National ID
    license_no?: string;
    license_expiry?: string;       // ISO date
    license_type?: string;
    dob?: string;                  // ISO date
    address?: string;
    city?: string;
    postal_code?: string;
    is_online: boolean;
    last_active_at?: string;       // ISO 8601 datetime
    current_latitude?: number;
    current_longitude?: number;
    current_device_uuid?: string;
    rating?: number;               // 0-5
    total_trips?: number;
    total_distance?: number;       // km
}
```

### DriverSession Object

```typescript
interface DriverSession {
    id: string;                    // UUID
    driver_id: string;             // UUID
    device_uuid: string;
    status: 'active' | 'completed' | 'auto_closed';
    start_time: string;            // ISO 8601 datetime
    end_time?: string;             // ISO 8601 datetime
    start_latitude?: number;
    start_longitude?: number;
    end_latitude?: number;
    end_longitude?: number;
    total_distance_km?: number;
    assignment_id?: string;        // UUID (future use)
    metadata?: Record<string, any>;
}
```

### RoutePoint Object

```typescript
interface RoutePoint {
    id: string;                    // UUID
    session_id: string;            // UUID
    latitude: number;
    longitude: number;
    altitude?: number;             // meters
    speed?: number;                // km/h
    heading?: number;              // degrees (0-360)
    accuracy?: number;             // meters
    recorded_at: string;           // ISO 8601 datetime
}
```

### DriverDevice Object

```typescript
interface DriverDevice {
    id: string;                    // UUID
    driver_id: string;             // UUID
    device_uuid: string;           // Unique device identifier
    device_name?: string;          // User-friendly name
    device_model?: string;         // e.g., "iPhone 14 Pro"
    device_manufacturer?: string;  // e.g., "Apple"
    platform: 'ios' | 'android';
    platform_display?: string;     // e.g., "iOS 17.0"
    os_version?: string;
    app_version?: string;
    app_build?: string;
    has_push_token: boolean;
    push_provider?: 'fcm' | 'apns';
    is_active: boolean;
    last_active_at?: string;       // ISO 8601 datetime
    registered_at: string;         // ISO 8601 datetime
    locale?: string;               // e.g., "en_US"
    timezone?: string;             // e.g., "Asia/Colombo"
    display_name: string;          // Computed display name
    created_at: string;            // ISO 8601 datetime
    updated_at: string;            // ISO 8601 datetime
}
```

---

## 12. Best Practices

### Device UUID Generation

Generate a UUID v4 on first app launch and store it persistently:

```swift
// iOS (Swift)
let deviceUUID = UUID().uuidString
UserDefaults.standard.set(deviceUUID, forKey: "device_uuid")
```

```kotlin
// Android (Kotlin)
val deviceUUID = UUID.randomUUID().toString()
sharedPreferences.edit().putString("device_uuid", deviceUUID).apply()
```

### Token Storage

Store the authentication token securely:

- **iOS:** Use Keychain Services
- **Android:** Use EncryptedSharedPreferences

### Background Location Updates

For continuous location tracking while online:

1. Request "Always" location permission
2. Use significant location change monitoring
3. Send heartbeat with each location update
4. Handle app termination gracefully (go offline)

### Recommended Update Intervals

| Action | Interval | Notes |
|--------|----------|-------|
| Heartbeat | 60 seconds | Prevents auto-offline |
| Location Update | 10 seconds | While moving |
| Location Update | 30 seconds | While stationary |

### Handling Token Expiration

Implement automatic token refresh when receiving 401 errors:

```swift
// iOS Swift example
func handleAPIResponse(response: Response) {
    if response.statusCode == 401 {
        let errorCode = response.json["error_code"] as? String
        
        if errorCode == "AUTH_TOKEN_EXPIRED" {
            // Attempt to refresh token
            refreshAccessToken { success in
                if success {
                    // Retry the original request
                    retryRequest(response.request)
                } else {
                    // Refresh failed, navigate to login
                    navigateToLogin()
                }
            }
        } else {
            // Other auth errors, navigate to login
            clearAuthTokens()
            navigateToLogin()
        }
    }
}

func refreshAccessToken(completion: @escaping (Bool) -> Void) {
    guard let refreshToken = getStoredRefreshToken() else {
        completion(false)
        return
    }
    
    apiClient.post("/api/driver/auth/refresh", body: ["refresh_token": refreshToken]) { result in
        switch result {
        case .success(let data):
            saveTokens(accessToken: data.access_token, refreshToken: data.refresh_token)
            completion(true)
        case .failure:
            completion(false)
        }
    }
}
```

```kotlin
// Android Kotlin example
suspend fun handleApiResponse(response: Response): Result<Any> {
    return when (response.code) {
        401 -> {
            val errorCode = response.body?.errorCode
            
            if (errorCode == "AUTH_TOKEN_EXPIRED") {
                // Attempt to refresh token
                val refreshResult = refreshAccessToken()
                if (refreshResult.isSuccess) {
                    // Retry the original request
                    retryRequest(response.request)
                } else {
                    // Refresh failed, navigate to login
                    navigateToLogin()
                    Result.failure(Exception("Authentication failed"))
                }
            } else {
                // Other auth errors
                clearAuthTokens()
                navigateToLogin()
                Result.failure(Exception("Authentication failed"))
            }
        }
        else -> Result.success(response.body)
    }
}

suspend fun refreshAccessToken(): Result<TokenResponse> {
    val refreshToken = getStoredRefreshToken() ?: return Result.failure(Exception("No refresh token"))
    
    return try {
        val response = apiClient.post("/api/driver/auth/refresh") {
            body = RefreshRequest(refreshToken)
        }
        saveTokens(response.accessToken, response.refreshToken)
        Result.success(response)
    } catch (e: Exception) {
        Result.failure(e)
    }
}
```

### Offline Handling

1. Queue location updates when offline
2. Sync queued updates when connection restored
3. Show offline indicator to user
4. Automatically attempt reconnection

---

## Support

For API support or to report issues:

- **Email:** api-support@thetaxi.lk
- **Documentation:** https://docs.thetaxi.lk/driver-api

---

*This documentation is subject to change. Always refer to the latest version.*
