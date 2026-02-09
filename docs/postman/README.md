# TheTaxi Driver Mobile API - Postman Collection

This directory contains Postman collection and environment files for testing the TheTaxi Driver Mobile API.

## Files

- **TheTaxi-Driver-API.postman_collection.json** - Complete API collection with all endpoints
- **TheTaxi-Driver-API.postman_environment.json** - Development environment variables
- **TheTaxi-Driver-API-Production.postman_environment.json** - Production environment variables

## Quick Start

### 1. Import Collection

1. Open Postman
2. Click **Import** button
3. Select `TheTaxi-Driver-API.postman_collection.json`
4. The collection will appear in your Collections sidebar

### 2. Import Environment

**For Development:**
1. Click **Import** button
2. Select `TheTaxi-Driver-API.postman_environment.json`
3. Select the environment from the dropdown in the top-right corner

**For Production:**
1. Click **Import** button
2. Select `TheTaxi-Driver-API-Production.postman_environment.json`
3. Update the empty variables with your production credentials
4. Select the environment from the dropdown

### 3. Configure Environment Variables

Before testing, update these environment variables:

#### Required Variables (Development)
- `driver_email` - Driver's email address (default: driver@example.com)
- `driver_password` - Driver's password (default: password)
- `device_uuid` - Unique device identifier (default provided, or generate your own UUID)

#### Required Variables (Production)
- `driver_email` - Your production driver email
- `driver_password` - Your production driver password
- `device_uuid` - Generate a unique UUID for your test device

#### Optional Variables
- `device_name` - Friendly device name (e.g., "Test iPhone")
- `device_model` - Device model (e.g., "iPhone 14 Pro")
- `device_manufacturer` - Manufacturer (e.g., "Apple")
- `platform` - Platform: `ios` or `android`
- `os_version` - OS version (e.g., "17.0")
- `app_version` - App version (e.g., "1.0.0")
- `app_build` - Build number (e.g., "100")

#### Auto-Populated Variables
These are automatically set by the collection scripts:
- `access_token` - Set after successful login
- `refresh_token` - Set after successful login
- `driver_id` - Set after successful login
- `user_id` - Set after successful login
- `session_id` - Set after going online

## Testing Workflow

### Basic Authentication Flow

1. **Login**
   - Navigate to: `Authentication > Login`
   - Click **Send**
   - Tokens are automatically saved to environment variables
   - Response includes user, driver, device, and token information

2. **Get Profile**
   - Navigate to: `Authentication > Get Profile`
   - Click **Send**
   - Returns complete driver profile

3. **Refresh Token**
   - Navigate to: `Authentication > Refresh Token`
   - Click **Send**
   - New tokens are automatically saved

4. **Logout**
   - Navigate to: `Authentication > Logout`
   - Click **Send**
   - Current token is revoked

### Status Management Flow

1. **Go Online**
   - Navigate to: `Status Management > Go Online`
   - Update latitude/longitude if needed
   - Click **Send**
   - Session ID is automatically saved

2. **Get Current Status**
   - Navigate to: `Status Management > Get Current Status`
   - Click **Send**

3. **Send Heartbeat**
   - Navigate to: `Heartbeat > Send Heartbeat`
   - Click **Send**
   - Should be sent every 60 seconds while online

4. **Update Location**
   - Navigate to: `Location Tracking > Update Location`
   - Update coordinates if needed
   - Click **Send**
   - Should be sent every 10 seconds while moving

5. **Go Offline**
   - Navigate to: `Status Management > Go Offline`
   - Click **Send**

### Session History

1. **List Sessions**
   - Navigate to: `Sessions > List Sessions`
   - Modify query parameters if needed
   - Click **Send**

2. **Get Session Details**
   - Navigate to: `Sessions > Get Session Details`
   - Ensure `session_id` is set in environment
   - Click **Send**

### Device Management

1. **List Devices**
   - Navigate to: `Device Management > List Devices`
   - Click **Send**

2. **Get Current Device**
   - Navigate to: `Device Management > Get Current Device`
   - Click **Send**

3. **Update Device**
   - Navigate to: `Device Management > Update Device`
   - Modify request body as needed
   - Click **Send**

4. **Update Push Token**
   - Navigate to: `Device Management > Update Push Token`
   - Set `push_token` in environment or request body
   - Click **Send**

## Features

### Automatic Token Management

The collection includes pre-request and test scripts that automatically:
- Save access and refresh tokens after login
- Save driver and user IDs
- Save session IDs when going online
- Use saved tokens for authenticated requests

### Environment Variables

All requests use environment variables for:
- Base URL (easily switch between dev/prod)
- Authentication tokens
- Driver and session IDs
- Device information
- GPS coordinates

### Pre-configured Requests

All requests include:
- Proper headers (Content-Type, Accept, Authorization)
- Sample request bodies with environment variables
- Query parameters where applicable
- Bearer token authentication

## Tips

### Generate UUID for Device

Use an online UUID generator or:

**macOS/Linux:**
```bash
uuidgen | tr '[:upper:]' '[:lower:]'
```

**Python:**
```python
import uuid
print(str(uuid.uuid4()))
```

**JavaScript (Browser Console):**
```javascript
crypto.randomUUID()
```

### Test Location Updates

For realistic testing, update the location variables in sequence:
1. Set `start_latitude` and `start_longitude` (starting point)
2. Set `current_latitude` and `current_longitude` (moving points)
3. Set `end_latitude` and `end_longitude` (ending point)

Example coordinates (Colombo, Sri Lanka):
- Start: 6.9271, 79.8612
- Current: 6.9285, 79.8625
- End: 6.9350, 79.8500

### Rate Limiting

The login endpoint has rate limiting (5 attempts per minute). If you hit the limit:
- Wait 60 seconds
- Or use a different email/IP

### Token Expiration

Access tokens expire after 1 hour. If you get a 401 error:
1. Use the **Refresh Token** request
2. Or login again

## Troubleshooting

### 401 Unauthorized
- Check if `access_token` is set in environment
- Try refreshing the token
- Login again if refresh fails

### 403 Forbidden (AUTH_NOT_DRIVER)
- The user account is not registered as a driver
- Contact admin to create a driver account

### 400 Bad Request (STATUS_ALREADY_ONLINE)
- Driver is already online
- Use **Go Offline** first, then **Go Online**

### 400 Bad Request (LOCATION_NO_SESSION)
- No active session exists
- Use **Go Online** before sending location updates

### Empty Response
- Check if the correct environment is selected
- Verify `base_url` is correct
- Check server is running

## API Documentation

For complete API documentation, see:
- `public-thetaxi/docs/DRIVER_MOBILE_API.md`

## Support

For issues or questions:
- Check the main API documentation
- Review error codes in the response
- Contact the development team

---

**Last Updated:** February 9, 2026
