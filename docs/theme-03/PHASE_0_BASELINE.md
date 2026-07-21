# Theme 03 Phase 0 Baseline

Last updated: 2026-07-21

This baseline records the public Laravel presentation owners before Theme 03 release. The original static inventory remains independent of database-backed commands; runtime is now available for the outstanding visual matrix.

## Runtime status

- Local URL checked: `http://thetaxi.test/` on 2026-07-21.
- Result: HTTP 200; the ignored local development gate and stored setting currently render Theme 03 for preview.
- Database result: PostgreSQL accepts connections on `127.0.0.1:5432`.
- Windows service: `postgresql-x64-17` is running.
- Screenshot capture is unblocked but still pending for Theme 01 and Theme 02 across every route/state/viewport below. Do not mark the baseline complete from the single Theme 03 homepage preview image.

## Public route and view ownership

### Direct public pages

| Route | Route/controller owner | Primary Blade owner | Required baseline states |
|---|---|---|---|
| `/` | `HomeController@index` | `home` | Every enabled/disabled homepage section combination; popup on/off |
| `/search/{id?}` | `BookingController@showResults` | `search` | Results, quotation-only, unavailable, empty, error, modify search |
| `/vehicles` | legacy alias | Redirect to `cms.index` with `ride_now` | Redirect and canonical fleet catalogue |
| `/vehicle/{id}` | `VehicleController@show` | `vehicle-details` | Gallery, specs, pricing, unavailable/quotation, booking form |
| `/about` | closure | `about` | All conditional story, journey, partner, media, review, FAQ and counter sections |
| `/contact`, `/inquiry` | `ContactController@index` | `contact` | Initial, validation failure, success, map and missing optional content |
| `/faq`, `/faq/category/{category}` | `FAQController` | `faq` | All, category, query, featured, empty |
| `/checkout` | `CheckoutController@index` | `checkout` | Populated, empty, validation, quotation/payment choices |
| `/checkout/success` | `CheckoutController@success` | `checkout.success` | Quotation, advance paid, paid, confirmed, received and fallback |
| `/checkout/payment-resume/{token}` | `CheckoutController@resumePayment` | `checkout.payment-resume` | Valid, expired/invalid redirect, processing and errors |
| `/checkout/webxpay/redirect` | `CheckoutController@webxpayRedirect` | `checkout.webxpay-redirect` | Valid session and expired-session redirect |
| `/checkout/webxpay/callback` | `CheckoutController@webxpayCallback` | `checkout.callback-error` on terminal error | Success/failure/cancel recovery |
| `/booking/status` | `CustomerBookingStatusController` | `booking.status` | Empty lookup, validation, not found, found |
| `/point-to-point` | `BookingController@pointToPoint` | `point-to-point` | Hero/form, features, details, FAQ |
| `/corporate-transfers` | `InquiryServicePageController@show` with fixed slug | `inquiry.service-page` | Every configured section type and inquiry-form states |
| `/rate-chart` | `RateChartController@index` | `rate-chart` | Rates, selectors, empty/error, info and CTA |
| unknown one-segment CMS URL | `CmsController@index` | `cms.index` | Each active content type, filters, pagination and empty |
| unknown two-segment CMS URL | `CmsController@show` | `cms.show` | Rich body, media/no media, booking form, vehicles and related content |
| framework 404 | Laravel error rendering | `errors.404` | Branded not-found state |
| maintenance mode | Laravel maintenance rendering | `errors.maintenance` | Branded maintenance state |

### Route aliases and non-visual endpoints

- `/cart` redirects to `/checkout`; `cart.blade.php` is a legacy retained view, not the active GET owner.
- `/booking/search` is the shared GET/POST form action; successful searches continue to the search result owner.
- `/checkout/quotation-convert/{token}` and `/checkout/quotation-payment/{token}` redirect into checkout/payment flows rather than owning stable standalone presentation.
- WebXPay callback/notify/cancel routes are transition endpoints. Only rendered terminal/recovery states belong in visual coverage.
- Currency, cart mutation, dynamic service configuration, robots, sitemap, short URLs and uploaded resources do not own themed HTML pages.

## Reachability and legacy evidence

### Canonical fleet alias

`/vehicles` previously returned a missing `vehicles` view. It now preserves the named URL while redirecting to the existing CMS-backed `ride_now` catalogue, already used by the homepage “View All Rental Vehicles” action. No second fleet query or page was introduced.

### Mock gateway

`CheckoutController::mockGateway()` references the missing `checkout.mock-gateway` view, but no web/API route registers `mockGateway`. It is classified as an unregistered legacy method and excluded from public visual coverage unless a route is deliberately restored later.

### Other missing literal views

- `admin.analytics.short-urls` is referenced by an admin controller with no registered route in the inspected route files; it is outside the public theme.
- `layout.system-app` belongs to a stale public-repository `/system/{any?}` fallback. The actual administration portal is the separate Angular application. It is outside the Laravel public theme.

### CMS catch-all order

The generic `/{contentType}/{content}` route is registered before `/services/{slug}` and `/system/{any?}`.

- `/services/{slug}` currently resolves to `CmsController@show`, which matches both public headers' generated service links. Moving the later inquiry route ahead of the CMS route could change working CMS service pages and is not part of Theme 03.
- `/system/settings` resolves to the CMS catch-all; deeper `/system/.../...` paths can reach the stale missing `layout.system-app` closure. The Angular portal remains the real admin owner.
- `/sitemap.xml` is not captured by the one-segment CMS constraint because the dot is outside `[a-zA-Z0-9-_]+`.

The static contract test reproduces Laravel's first-match route behavior without booting the database-backed application.

## Search tab, service types and form-field contract

The Theme 03 search panel must retain the entire database-driven tab and field system.

### Tab source and fallback service codes

- Live tab order/labels/icons: `BookingFormTab::getOrderedTabs()`.
- Fallback/recognized codes: `airport_transfers`, `ride_now`, `day_rental`, `corporate`, `wedding_hire`, `self_drive`, `with_driver`.
- Custom tab codes remain supported through `service_type_code` mapping and stored `ServiceType::form_config`.
- Each tab retains `data-service` and `data-form-service`; each form retains its `data-service` and hidden `service_type` input.
- Non-inquiry types submit to `booking.search`; inquiry types submit to `booking.enquiry`.

### Supported dynamic fields

- Types: location, date, time, select, radio, checkbox, textarea, number, hidden, package select, plus the default text input.
- Location modes: autocomplete/default, predefined-or-custom, airport, and conditional variants.
- Coordinates: the submitted address fields and their `_lat`/`_lng` companions remain a single contract.
- Special flow: Ride Now can expose the legacy-compatible return toggle/date/time block and pricing notice IDs.
- Field definitions come from stored service configuration merged with `DefaultFormConfigService`; Theme 03 must not replace this with hard-coded theme fields.

### Critical DOM hooks

Do not rename/remove these families without updating shared behavior and contract tests:

- `.filter-item-list`, `.single-item`, `.filter-input`, `.filter-input.show`
- `data-service`, `data-form-service`, hidden `name="service_type"`
- `.booking-field`, `.single-search-box`, `.location-input`, `.location-lat`, `.location-lng`
- conditional-field attributes: `data-condition-field`, `data-field-name`, `data-conditions`, `data-condition-value`
- package hooks: `.package-button`, `data-package-id`
- Ride Now return IDs: `ride_now-return-toggle`, `ride_now-return-details`, `ride_now-return-date`, `ride_now-return-time`, `ride_now-return-pricing-info`
- result/card hooks: `.vehicle-card`, its `data-service`, cart buttons/data, result list container, and floating cart summary hooks
- header hooks: `currencyDropdown`, `.currency-option`, `cartBadge`, `mobileCartBadge`, mobile menu open/close controls

## Representative visual fixtures

Use real seeded/database records when runtime returns. Where data is missing, add test-only fixtures rather than hard-coded production content.

| Family | Minimum fixtures |
|---|---|
| Homepage | All sections populated; each section independently absent; one-item and many-item sliders; missing optional image |
| Booking form | Every visible tab; each supported field type; each location mode; required error; inactive/disabled conditional fields; Ride Now return |
| Search | Available fixed price; discounted; quotation-only; unavailable; recommended; missing image; long vehicle name; zero results |
| Vehicle detail | Multiple/one/no optional gallery image; full/sparse specs; booking available/unavailable |
| CMS | Every active content type; image/no image; long title; long rich HTML; table; embed; no related items |
| Inquiry | Every service-builder section; form validation/success/error; missing media |
| Cart/checkout | Empty; one/multiple items; add-ons; extra km; coupon; tax/fees; quotation; full/advance/offline payment |
| Success/payment | Quotation, advance paid, paid, confirmed, received, expired token, callback error, redirect processing |
| Global | Long nav/service labels; currency variants; popup on/off; 404; maintenance; reduced motion |

## Existing baseline tests

- `tests/Unit/BookingFormLabelStructureTest.php` protects current shared booking-field label/control structure across Theme 01 and Theme 02.
- `tests/Unit/PublicWebsiteThemeBaselineContractTest.php` protects the canonical `/vehicles` alias, tab/service/field contracts, current catch-all reachability, and the explicit missing-view allowlist.
- Database-backed public route rendering is now available; the complete Theme 01/02 screenshot matrix remains pending.

## Screenshot output convention

When runtime is restored, store generated evidence outside `public/` under:

```text
artifacts/theme-03/baseline/
  theme-01/<route-key>/<viewport>.png
  theme-02/<route-key>/<viewport>.png
```

Use at least the plan's phone, tablet and desktop reference sizes. Never change the production theme setting to collect screenshots; use a local/test-only override and clear it after capture.
