# TheTaxi Driver Mobile API - Postman Collection

## Overview

This Postman collection provides complete API testing for the TheTaxi Driver Mobile Application, including authentication, assignments, hires, earnings, device management, status tracking, and location services.

## Files

- **TheTaxi-Driver-API.postman_collection.json** - Complete API collection
- **TheTaxi-Driver-API.postman_environment.json** - Development environment variables

## Quick Start

### 1. Import Collection

1. Open Postman
2. Click **Import**
3. Select both JSON files
4. Collection and environment will be imported

### 2. Select Environment

1. Click the environment dropdown (top right)
2. Select **"TheTaxi Driver API - Development"**

### 3. Update Environment Variables

Click the eye icon next to the environment dropdown and update:

```
base_url: http://thetaxi.test (or your API URL)
driver_email: your-driver@example.com
driver_password: your-password
device_fingerprint: (auto-generated on login, or use test value)
```

### 4. Test Login

1. Open **Authentication → Login**
2. Click **Send**
3. Access token will be automatically saved to environment

## New: Backend-Generated UUID Approach

### What Changed

The API now uses a **device fingerprint** approach where:
- Mobile app sends a fingerprint (SHA-256 hash of device characteristics)
- Backend generates and returns a UUID
- Same fingerprint = same device recognized
- No need to store UUID on mobile app

### Device Fingerprint

The `device_fingerprint` is a SHA-256 hash of device characteristics:

```
Components:
- Device model (e.g., "iPhone 14 Pro")
- OS version (e.g., "17.2")
- Platform ID (IDFV for iOS, Android ID for Android)
- Screen dimensions
- Time zone

Example:
"iPhone 14 Pro|iOS|17.2|ABC-123-DEF|1170x2532|Asia/Colombo"
↓ SHA-256
"a3f5b2c1d4e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2"
```

### Login Request (New Format)

```json
{
  "email": "driver@example.com",
  "password": "password123",
  "device_fingerprint": "a3f5b2c1d4e6f7a8...",
  "platform": "ios",
  "device_model": "iPhone 14 Pro",
  "os_version": "17.2"
}
```

### Login Response

```json
{
  "status": "success",
  "data": {
    "device": {
      "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "device_fingerprint": "a3f5b2c1d4e6f7a8...",
      "platform": "ios"
    },
    "token": {
      "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
      "refresh_token": "token-id"
    }
  }
}
```

The `device_uuid` is automatically saved to the environment for subsequent requests.

## Environment Variables

### Authentication
- `access_token` - Bearer token (auto-set on login)
- `refresh_token` - Refresh token ID (auto-set on login)
- `driver_id` - Driver UUID (auto-set on login)
- `user_id` - User UUID (auto-set on login)

### Device Information
- `device_uuid` - Device UUID (returned by backend)
- `device_fingerprint` - Device fingerprint hash
- `device_name` - User-friendly device name
- `device_model` - Device model (e.g., "iPhone 14 Pro")
- `device_manufacturer` - Manufacturer (e.g., "Apple")
- `platform` - OS platform ("ios" or "android")
- `os_version` - OS version (e.g., "17.2")
- `app_version` - App version (e.g., "1.0.0")
- `app_build` - Build number (e.g., "100")
- `push_token` - FCM/APNs push token
- `push_provider` - Push provider ("fcm" or "apns")

### Location
- `start_latitude` - Session start latitude (Colombo: 6.9271)
- `start_longitude` - Session start longitude (Colombo: 79.8612)
- `current_latitude` - Current location latitude
- `current_longitude` - Current location longitude
- `end_latitude` - Session end latitude
- `end_longitude` - Session end longitude
- `history_assignment_id` - Optional assignment ID for trip-specific replay
- `history_session_id` - Optional session ID for session-specific replay
- `history_from` - Optional ISO datetime lower bound for history window
- `history_to` - Optional ISO datetime upper bound for history window
- `history_limit` - Optional max route points to return (default `5000`)

### Session
- `session_id` - Active session UUID (auto-set on go online)

## API Endpoints

### Authentication
- **POST** `/api/driver/auth/login` - Login with credentials
- **GET** `/api/driver/auth/profile` - Get driver profile
- **POST** `/api/driver/auth/refresh` - Refresh access token
- **POST** `/api/driver/auth/logout` - Logout and revoke token

### Status Management
- **POST** `/api/driver/status/online` - Go online (start session)
- **POST** `/api/driver/status/offline` - Go offline (end session)
- **GET** `/api/driver/status` - Get current status

### Heartbeat
- **POST** `/api/driver/heartbeat` - Send heartbeat (every 60s)

### Location Tracking
- **POST** `/api/driver/location` - Update location (every 10s)
- **GET** `/api/driver/location/history` - Get location history (active/latest session by default, optional `assignment_id`, `session_id`, `from`, `to`, `limit`)

### Sessions
- **GET** `/api/driver/sessions` - List session history
- **GET** `/api/driver/sessions/{id}` - Get session details

### Device Management
- **GET** `/api/driver/devices` - List all devices
- **GET** `/api/driver/devices/current` - Get current device
- **PUT** `/api/driver/devices` - Update device info
- **POST** `/api/driver/devices/push-token` - Update push token
- **POST** `/api/driver/devices/{uuid}/deactivate` - Deactivate device
- **DELETE** `/api/driver/devices/{uuid}` - Remove device

### Assignments
- **GET** `/api/driver/assignments` - List assignments (supports `status`, `date`, `from`, `to`, `page`, `per_page`)
- **GET** `/api/driver/assignments/current` - Get current assignment
- **POST** `/api/driver/assignments/{id}/accept` - Accept assignment
- **POST** `/api/driver/assignments/{id}/decline` - Decline assignment

### Hires
- **GET** `/api/driver/hires` - Completed hire history (`date`, `from`, `to`, pagination supported)

### Earnings
- **GET** `/api/driver/earnings/summary` - Today/week/month summary
- **GET** `/api/driver/earnings/daily?date=YYYY-MM-DD` - Daily breakdown
- **GET** `/api/driver/earnings/range?from=YYYY-MM-DD&to=YYYY-MM-DD` - Date-range breakdown

### Trip Tracking
- **GET** `/api/driver/assignments/{id}/status` - Trip status for an assignment
- **POST** `/api/driver/assignments/{id}/arrived` - Mark pickup arrived
- **POST** `/api/driver/assignments/{id}/start` - Start trip
- **POST** `/api/driver/assignments/{id}/complete` - Complete trip (supports optional `ending_mileage`, `notes`)

## Testing Workflow

### 1. Authentication Flow

```
1. Login → Saves access_token, device_uuid
2. Get Profile → Verify authentication
3. Refresh Token → Get new access token
4. Logout → Revoke token
```

### 2. Session Flow

```
1. Login
2. Go Online → Saves session_id
3. Send Heartbeat (every 60s)
4. Update Location (every 10s)
5. Go Offline → Ends session
```

### 3. Device Management Flow

```
1. Login → Device registered/updated
2. List Devices → See all devices
3. Get Current Device → Current device info
4. Update Device → Update app version, etc.
5. Update Push Token → Register for notifications
```

## Testing Different Scenarios

### Test New Device

```json
{
  "email": "driver@test.com",
  "password": "password",
  "device_fingerprint": "new-fingerprint-abc123...",
  "platform": "ios"
}
```

**Expected:** Backend generates new UUID, returns it in response.

### Test Returning Device

```json
{
  "email": "driver@test.com",
  "password": "password",
  "device_fingerprint": "new-fingerprint-abc123...",
  "platform": "ios"
}
```

**Expected:** Backend recognizes fingerprint, returns same UUID as before.

### Test App Reinstall

```json
{
  "email": "driver@test.com",
  "password": "password",
  "device_fingerprint": "new-fingerprint-abc123...",
  "platform": "ios"
}
```

**Expected:** Same fingerprint = same device recognized, same UUID returned.

### Test Different Device

```json
{
  "email": "driver@test.com",
  "password": "password",
  "device_fingerprint": "different-fingerprint-xyz789...",
  "platform": "android"
}
```

**Expected:** Different fingerprint = new device, new UUID generated.

## Automated Tests

The collection includes automated tests that:

1. **Save tokens on login** - Access and refresh tokens saved to environment
2. **Save device UUID** - Device UUID returned by backend saved to environment
3. **Save session ID** - Session ID saved when going online
4. **Verify responses** - Check status codes and response structure

## Rate Limiting

The login endpoint has rate limiting:
- **5 attempts per minute** per email/IP combination
- After 5 failed attempts, wait 1 minute before retrying

## Error Responses

### 401 Unauthorized
```json
{
  "status": "error",
  "message": "Invalid credentials",
  "error_code": "AUTH_INVALID_CREDENTIALS"
}
```

### 429 Too Many Requests
```json
{
  "status": "error",
  "message": "Too many login attempts. Please try again in 0:45 minutes."
}
```

### 500 Server Error
```json
{
  "status": "error",
  "message": "Login failed",
  "error_code": "AUTH_FAILED",
  "error": "Detailed error message"
}
```

## Tips

1. **Use environment variables** - Don't hardcode values in requests
2. **Check auto-saved values** - Tokens and IDs are saved automatically
3. **Test error cases** - Try invalid credentials, expired tokens, etc.
4. **Monitor rate limits** - Wait between failed login attempts
5. **Update device info** - Keep device information current

## Support

For issues or questions:
- Check the API documentation: `public-thetaxi/docs/DRIVER_MOBILE_API.md`
- Review implementation guides:
  - `MOBILE_DEVICE_FINGERPRINT_GUIDE.md` - Fingerprint generation
  - `BACKEND_UUID_SOLUTION_SUMMARY.md` - Backend UUID approach
- Check application logs for detailed error messages

## Version History

### v2.0 (2026-02-10)
- Added device fingerprint support
- Backend now generates device UUIDs
- Updated login request/response format
- Added example responses
- Improved documentation

### v1.0 (2026-02-05)
- Initial release
- Basic authentication and device management
- Status and location tracking
- Session management
