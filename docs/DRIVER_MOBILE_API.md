# TheTaxi Driver Mobile App API Documentation

## Overview

This document provides comprehensive API documentation for the TheTaxi Driver Mobile Application. The API enables drivers to authenticate, manage their online/offline status, track locations, and view session history.

**Base URL:** `https://api.thetaxi.lk` (Production) | `http://thetaxi.test` (Development)

**API Version:** 1.0

**Last Updated:** February 2026

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [Status Management](#2-status-management)
3. [Heartbeat](#3-heartbeat)
4. [Location Tracking](#4-location-tracking)
5. [Sessions](#5-sessions)
6. [Device Management](#6-device-management)
7. [Assignments](#7-assignments-placeholder)
8. [Public/Meter API](#8-publicmeter-api)
9. [Error Handling](#9-error-handling)
10. [Data Models](#10-data-models)
11. [Best Practices](#11-best-practices)

---

## Common Headers

All authenticated requests must include:

```
Authorization: Bearer {token}
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
        "token": "1|abc123xyz...",
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
        }
    }
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 401 | AUTH_INVALID_CREDENTIALS | Invalid email or password |
| 422 | VALIDATION_ERROR | Missing or invalid fields |
| 429 | RATE_LIMITED | Too many login attempts |

**Important Notes:**
- When logging in from a new device, all previous session tokens are automatically revoked (single-session enforcement)
- Store the token securely on the device (e.g., Keychain on iOS, EncryptedSharedPreferences on Android)
- The device_uuid should be generated once and stored persistently on the device

---

### 1.2 Logout

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

### 1.3 Get Profile

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
        }
    }
}
```

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
    }
}
```

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
```

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
        "is_online": true
    }
}
```

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

**Endpoint:** `PUT /api/driver/devices/{device_uuid}`

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

**Endpoint:** `POST /api/driver/devices/{device_uuid}/push-token`

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

Deactivate a specific device (admin portal only).

**Endpoint:** `POST /api/drivers/{driver_id}/devices/{device_uuid}/deactivate`

**Authentication:** Required (Admin)

**Permission:** `drivers.edit`

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

Remove a device from the driver's account (admin portal only).

**Endpoint:** `DELETE /api/drivers/{driver_id}/devices/{device_uuid}`

**Authentication:** Required (Admin)

**Permission:** `drivers.edit`

**Success Response (200):**

```json
{
    "status": "success",
    "message": "Device removed successfully"
}
```

---

## 7. Assignments (Placeholder)

These endpoints are placeholders for future assignment/dispatch functionality.

### 6.1 List Assignments

**Endpoint:** `GET /api/driver/assignments`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": [],
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 15,
        "total": 0
    },
    "message": "Assignment feature coming soon"
}
```

### 6.2 Get Current Assignment

**Endpoint:** `GET /api/driver/assignments/current`

**Authentication:** Required

**Success Response (200):**

```json
{
    "status": "success",
    "data": null,
    "message": "No active assignment"
}
```

---

## 8. Public/Meter API

These endpoints are for guest users (meter functionality) and require a Device UUID header instead of authentication.

**Required Header:**
```
X-Device-UUID: {device_uuid}
```

### 7.1 Start Meter

Start a new meter session for fare tracking.

**Endpoint:** `POST /api/public/meter/start`

**Authentication:** None (Device UUID required)

**Request Body:**

```json
{
    "latitude": 6.9271,
    "longitude": 79.8612
}
```

### 7.2 Stop Meter

Stop the active meter session.

**Endpoint:** `POST /api/public/meter/stop`

### 7.3 Update Meter Location

Send location update during meter session.

**Endpoint:** `POST /api/public/meter/location`

### 7.4 Get Fare Estimate

Get estimated fare based on distance.

**Endpoint:** `GET /api/public/meter/estimate`

---

## 9. Error Handling

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

| Error Code | Description |
|------------|-------------|
| AUTH_INVALID_CREDENTIALS | Invalid email or password |
| AUTH_TOKEN_EXPIRED | Token has expired |
| AUTH_TOKEN_REVOKED | Token has been revoked |
| AUTH_NOT_DRIVER | User is not registered as a driver |
| STATUS_ALREADY_ONLINE | Driver is already online |
| STATUS_NOT_ONLINE | Driver is not currently online |
| LOCATION_NO_SESSION | No active session for location update |
| SESSION_NOT_FOUND | Session not found |
| DEVICE_UUID_REQUIRED | Missing Device UUID header |

---

## 10. Data Models

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

## 11. Best Practices

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

```swift
// Pseudo-code for handling 401 responses
func handleAPIResponse(response: Response) {
    if response.statusCode == 401 {
        // Clear stored token
        clearAuthToken()
        // Navigate to login screen
        navigateToLogin()
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
