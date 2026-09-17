# Driver Mobile Authentication Handoff

Do not add a separate registration form before mobile verification.

## Entry screen

Show two options:

1. **Mobile + OTP** — selected by default; supports both sign-in and sign-up.
2. **Email + Password** — existing drivers only; calls `POST /api/driver/auth/login`.

## Default mobile flow

1. Collect the mobile in international format, for example `+94771234567`.
2. Call `POST /api/driver/auth/request-otp` with `{ "mobile": "+94771234567" }`.
3. Collect the six-digit OTP and call `POST /api/driver/auth/verify-otp`. Include the normal device fields used by email login.
4. Branch only on `data.flow`:
   - `login`: save `data.token.access_token`, refresh token, driver, and device; continue to the normal authenticated app.
   - `registration`: save `data.onboarding_token` in a separate onboarding credential; open the registration stepper and use that token for onboarding endpoints.

Never decide locally whether the number is registered. The server makes that decision after OTP verification.

## Registration rule

Registration is mobile + OTP only. Do not request or submit a password during registration. After admin approval, the same mobile + OTP flow returns normal driver tokens. Email + password remains an optional sign-in method for an existing driver account.

Do not ask for or display date of birth. Submit the old or new Sri Lankan NIC in onboarding step 1; the backend derives and stores DOB internally and does not return it in onboarding responses.

## Important responses

- Invalid/expired OTP: HTTP 422; remain on the OTP screen and show the server validation message.
- Deactivated/locked driver: HTTP 422; do not fall back to registration.
- `flow=registration`: the onboarding token is not an API access token.
- Submitted application: show the review-pending screen until approved or changes are requested.
