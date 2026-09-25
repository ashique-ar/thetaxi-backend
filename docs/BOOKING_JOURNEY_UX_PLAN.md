# Booking journey review and mobile-first upgrade plan

Planning only — 24 September 2026. No application implementation changed.

This review follows the current routes, Blade views, JavaScript, theme styles, controllers, services and relevant tests. It is a static code review, not a live browser or gateway certification. Layout concerns below are code-derived; actual overflow, contrast, focus behavior and payment completion must be measured in the implementation phases. Existing screenshots and release documents are not proof of current end-to-end behavior.

## Conversion priority: make mobile booking the main product

The business reports no website bookings. The upgrade therefore targets completed bookings, not a cosmetic refresh. This report does not establish why bookings are absent: traffic volume, visitor intent, price, technical failures and checkout friction must be measured separately. Do not promise a conversion uplift before establishing the funnel.

The primary phone journey should be **Existing search → Choose vehicle → Checkout → Confirmation**. Search already asks for limited trip data, and results deliberately do not allow vehicle-details navigation. The cart is a widget, not a separate page. The two checkout stages below are sections within checkout.

First-release priorities across all four themes:

1. **Make the existing search easy to find and use on mobile.** Put the current form where visitors see it promptly; keep its service-specific fields and server validation. This is a placement and clarity change, not a request for fewer search fields. Avoid a promotional popup interrupting entry.
2. **Make choosing a vehicle easy.** Show one readable option per phone row, passenger/bag capacity, availability meaning, total trip price and key inclusions directly on the result card. Give each card one visually dominant action, Book now. Keep Add to cart available with secondary emphasis. Do not add a View details step or unsupported scarcity claims.
3. **Show the payable price before commitment.** Display applicable mandatory charges in the quoted total, with an expandable breakdown. Explain estimated or usage-dependent charges explicitly. Carry that same trip and price into checkout; a changed quote requires review.
4. **Make direct checkout genuinely direct.** Buy only the chosen trip, preserve any existing cart separately, and keep Edit trip within checkout. Customers using Add to cart review or edit trips in the widget, then go straight to checkout. Ask for contact details first; show other required fields only when needed by the service/business rules. Do not remove current server-required address fields until their requirement has been resolved.
5. **Keep the next action obvious.** Use a mobile bottom action bar where useful, showing the amount and the actual next action. Avoid multiple competing sticky widgets. Keep support available without making a phone call the only way to recover a booking.
6. **Recover instead of restarting.** Fix lost trip context, missing cart updates, duplicated submissions and same-booking payment retry before visual rollout. These are conversion blockers even when the page looks better.
7. **Close the journey clearly.** Show the true booking/payment state, reference, pickup details, next step and Manage booking. Retain a clear route to help for failed or uncertain payment.

The first release combines phase 1 correctness work with the essential shared mobile UI from phases 2–3 and its four theme skins. Do not defer the primary mobile experience until a later visual-polish release. Richer self-service changes, cancellation automation and optional receipt/calendar conveniences can follow once the basic journey is reliable; retain status and support access at launch.

Establish a baseline for eligible visits → search started → valid results → vehicle selected → checkout started → payment attempted → confirmed booking. Segment by theme, device and direct/cart path, and distinguish quotations, check-in bookings and paid bookings. Record technical failures separately from abandonment. If traffic is too low for a useful conversion comparison, use observed mobile task completion and error-free end-to-end checks first; agree numeric conversion targets after baseline data exists.

Release gate: a new mobile visitor can discover search immediately, choose a suitable vehicle, understand the total, complete either booking path without re-entering trip details, and recover a failed payment without creating another booking. Apply the full acceptance matrix below to every theme.

## 1. Scope and implementation map

All four existing themes are in scope, including gated themes. The [theme manifest](../config/website_themes.php) defines `default`, `theme-02`, `theme-03` and `theme-04`; `theme-01.css` is the Default theme's stylesheet, not a fifth theme. Theme 03 and Theme 04 are enabled through release flags; this review does not change those flags. [Theme helpers](../app/Helpers/theme_helpers.php) normalize unavailable themes to Default and require the newer themes' header, hero and footer partials.

| Journey stage | Current implementation and ownership |
|---|---|
| Homepage | `Website/HomeController::index` → `home.blade.php`; theme-selected hero, shared booking form, separate Theme 03/04 booking wrappers, CMS and vehicle sections. |
| Search | `components/booking-form` → `dynamic-booking-form` / `dynamic-form-field`, plus `public/assets/js/booking-form.js`; configured tabs and service-specific fields. GET/POST `/booking/search` → `BookingController::search` validates and saves session context. |
| Results | GET `/search/{id?}` → `showResults`; `BookingFlowService::getAvailableVehicleGroups` provides availability/pricing. Shared `search.blade.php`, `vehicle-card`, and `vehicle-card-scripts`. Sorting/filtering and quotation fallback are already present. |
| Vehicle details, other entry points | GET `/vehicle/{id}` → `VehicleController::show` exists for other entry points; shared gallery, attributes and booking form. Results render cards with `showViewDetails=false`, so details are not a step in the result-to-booking journey. |
| Cart widget / direct action | POST `/cart/add` → `CartController::add` calculates server pricing and checks availability. `CartService` persists a database cart. Both action labels use this endpoint; Book Now subsequently opens checkout. Floating cart supports review/removal. `/cart` already redirects to `/checkout`; there is no separate cart page to design. |
| Checkout | `CheckoutController::index/process`; shared `checkout.blade.php`, cart partials, add-on modal, extra-km controls, promotions and terms. Full, advance, check-in and quotation choices depend on settings. |
| Payment | WebXPay redirect, callback, notify and cancel endpoints in `CheckoutController`; gateway service, payment events and `PendingPaymentManager` support payment processing and resume links. Callback/notify success paths already contain locking. |
| Confirmation | `/checkout/success` renders `checkout/success`; distinct quotation, advance, full, check-in and pending-payment branches, payment summary, trip information and support. |
| Management | `/booking/status` uses reference + email/phone and `BookingLifecycleService`; shows status, progress, payment label and updated time. This is currently a lookup, not full self-service management. |

Canonical public route evidence: [routes/web.php](../routes/web.php). Legacy controller methods and unreachable views must not be treated as separate working journeys.

## 2. Current issues by theme

The shared functional issues in section 3 apply to **every theme**. Theme-specific presentation does not solve them.

| Theme | Current identity / implementation | Specific issues and upgrade direction |
|---|---|---|
| Default | Classic travel layout; shared Bootstrap structure, `theme-01.css`, `checkout-theme-01.css`; default hero/header/footer. | Search renders `col-6` cards at the smallest breakpoint, crowding specifications, pricing and two actions. Checkout places another full search form above the transaction and customer fields before the order/payment sidebar. Use one card per phone row, compact trip summary and item-specific editing; retain existing brand colors, photography and familiar card treatment. |
| Theme 02 | Contemporary image-led hero/navigation; `theme-02-tw.css`, Tailwind CDN in layout, shared booking wrapper and `checkout-theme-02.css`. | Hero CTA fallback targets `#booking`, while the homepage form is `#home-booking`. Shared phone result density and checkout order remain. Mixed Tailwind, Bootstrap and inline/shared styles increase presentation regression risk. Correct anchor ownership, retain rounded contemporary styling, and give shared booking primitives one predictable cascade. |
| Theme 03 — Ceylon Modernist | Editorial typography, warm paper, ink/accent colors, square forms and offset shadows; Journey desk wrapper; dedicated `pages.css`, `checkout.css`, shell script. | Already overrides phone results to full-width and constrains the floating cart to safe areas: retain these improvements. Full search remains above checkout. Success/resume still explicitly load the Default checkout stylesheet before later theme page overrides, creating unnecessary dependency on legacy styling. Use compact editorial trip summaries, consistent transactional tokens and restrained display type in forms. |
| Theme 04 — Executive Redline | Red/black/white transport identity; theme booking panel, dedicated shell and transactional styles; desktop search has a 360–420px form rail. | Search results retain shared `col-6` phone cards and `col-md-4` sizing beside the desktop rail; verify density around 992px. Checkout embeds a full search panel ahead of the order summary in the sidebar, which is not an edit operation on a cart item. Success/resume also load the Default checkout stylesheet. Preserve red action emphasis and compact executive panels; use a collapsible trip editor and responsive results based on available width. |

Evidence: [home view](../resources/views/home.blade.php), [Theme 02 hero](../resources/views/partials/themes/theme-02/hero.blade.php), [layout](../resources/views/layouts/app.blade.php), [results](../resources/views/search.blade.php), [checkout](../resources/views/checkout.blade.php), [Theme 03 pages](../public/assets/css/themes/theme-03/pages.css), [Theme 04 pages](../public/assets/css/themes/theme-04/pages.css), and each theme's checkout CSS. Success/resume stylesheet selections are at the bottom of their views. Newer theme `pages.css` loads after the style stack, so those pages are not wholly unthemed; the issue is layered ownership.

## 3. Shared findings, ranked

| Priority | Code-grounded finding | User impact / proposed correction |
|---|---|---|
| P1 | Results explicitly set `showViewDetails=false`; the separate `/vehicle/{id}` route is reachable from other entry points and can fall back to default trip values. | Keep results → checkout simple. Preserve the actual trip on every active path, including catalogue/rate-chart links that reach details; never silently substitute a new itinerary. |
| P0 | Both Book Now handlers call cart add and then checkout; they do not isolate the chosen trip from existing cart entries. | Direct booking can include older selections. Introduce a direct checkout draft using the same pricing/submission services; retain the user's saved cart separately. Clearly show which trip is being purchased. |
| P0 | `CartController::add` generates a new time/random key per request. Vehicle-details `postToCart` lacks the shared card handler's loading guard. Shared cards restore actions after a 15-second safety timeout. Checkout disables its button, but its creation path has no visible request idempotency key. | Double taps, response loss and retries can create repeated cart lines or bookings. Client guards plus persisted server idempotency are required; a transaction alone does not deduplicate requests. |
| P0 | Payment failure returns to checkout; cancel writes draft/cancelled using the session booking. Success callback/notify locking exists, but the failure/cancel writes do not share the same terminal-state guard. Resume submission accepts posted `amount` and forwards it after token/booking matching. | Recover the same booking/payment attempt; derive payable amount from authoritative booking/ledger state. Make payment transitions monotonic so a late cancel/failure cannot overwrite paid. Treat ambiguous outcomes as pending verification, not an immediate invitation to pay again. |
| P0 | `CheckoutController::success` loads by query-string booking reference and exposes customer/booking details without an ownership check in that method or an explicit route guard. | Before expanding confirmation/management, require an authorized booking session or scoped expiring token. Do not use reference knowledge alone as authorization. Verify global middleware as part of the security test, rather than assuming it supplies this protection. |
| P1 | `/cart/update/{itemKey}` routes to `CartController::update`, but that method is absent. Checkout supports removal, add-ons and extra km, but no complete item itinerary/vehicle editor. | Add a real item edit workflow with validation, availability and price preview. Updating the search form must not masquerade as updating a booked cart line. |
| P1 | `CartService` is database-backed; `CartController::sync/count/checkout` still use session `cart` / `booking_cart` paths. | Consolidate reads/writes behind the database cart service. Explicitly migrate or retire legacy endpoints after auditing callers. Prevent inconsistent badge, summary and checkout values. |
| P1 | Search uses one mutable session context; missing/expired search redirects home. Vehicle details has a separate payload/pricing controller path. | Multiple tabs and Back can mix itineraries. Use an opaque draft ID and revision, keep per-item trip snapshots, and recover editable expired drafts without discarding fields. |
| P1 | Availability is checked on add and checkout, with a configurable public availability policy. Checkout totals refresh aggregates stored line prices and promotions; this is not proof of fresh base fares or an atomic inventory hold. | Revalidate quote and policy immediately before commitment. If price changes, present the exact delta for acceptance. Never claim inventory is reserved unless a real hold exists. Respect availability-bypass installations with accurate copy. |
| P1 | Checkout requires names, phone, email, address, city and country. Search form, terms, optional fields, extras and payment live on a long page. | Two checkout stages with progressive disclosure; retain mandatory fields until business requirements explicitly permit relaxing server rules. Keep contact details when changing extras or recovering validation. |
| P1 | Empty results primary link returns home despite an existing edit form. Price/availability errors also use short-lived toasts. | Keep the itinerary visible and provide Edit trip, Clear filters, Retry or Request quotation as appropriate. Persistent inline failure messages must accompany failed actions. Never show unavailable pricing as a zero fare. |
| P1 | Status lookup collapses most payment states to Pending and falls back to Confirmed for unrecognized lifecycle states. It offers no trip summary or management actions. | Use explicit customer-safe lifecycle/payment mappings, separate booking acceptance from money collected, and add authenticated management capabilities progressively. Unknown state should say status is being checked. |
| P2 | Pricing display and interactions are repeated across vehicle cards, details, float, checkout and email-style confirmation. Theme tests inspected mainly assert source strings. | Reuse presenters/components, and add executed browser plus service integration coverage. A source-string assertion is not evidence that a full journey works. |

Evidence anchors: [BookingController](../app/Http/Controllers/BookingController.php) (`search`, `showResults`); [VehicleController](../app/Http/Controllers/VehicleController.php) (`show`, `updatePricing`); [card scripts](../resources/views/components/vehicle-card-scripts.blade.php) (`getSearchData`, Book Now); [vehicle details](../resources/views/vehicle-details.blade.php) (`postToCart`); [CartController](../app/Http/Controllers/CartController.php) (`add`, `sync`, `count`); [CartService](../app/Services/CartService.php) (`updateTotals`, `toArray`); [CheckoutController](../app/Http/Controllers/CheckoutController.php) (`process`, `success`, callback/notify/cancel, `processPaymentResume`); [status controller](../app/Http/Controllers/CustomerBookingStatusController.php).

## 4. Recommended journeys

**Search and selection:** Home → use the existing limited, service-specific search → results with persistent trip summary → select a result directly. Put enough capacity, price, inclusion and availability information on the result card to make this decision without vehicle details. Keep configured services, return-trip fields, packages, rental mode, locations/coordinates and conditional validation. Inquiry-only services lead to quotation. Catalogue/rate-chart links that reach details still require confirmation of trip inputs.

**Cart path:** Add to cart → open or update the existing cart widget → review/edit trips there → checkout Details → Review & payment → gateway where applicable → confirmation → Manage booking. The widget provides Continue searching and Checkout; it does not navigate to a cart page. Each line owns its itinerary. Editing one line must not alter another. Add-ons, extra km, remove and undo are available in the widget or checkout summary; vehicle/date changes trigger repricing before Save. A duplicate intentional trip uses an explicit Add another action, not repeated taps.

**Direct path:** Book now → a draft containing only that selected trip → checkout Details → Review & payment → gateway → confirmation → Manage booking. Reuse the checkout UI, validation, pricing and booking creator. Offer Edit trip and optional extras within checkout. Keep any existing cart intact and visibly separate. Abandoning direct checkout must not add an unintended cart line or erase saved trips.

**Payment variants:** Full payment shows total and pay-now amount; advance shows pay-now, remaining balance and when due; check-in shows amount due later and the actual confirmation state. Quotation has its own submission label and acknowledgement with expected follow-up, without payment-success language. Expose only enabled options and preserve accepted service terms.

**Recovery:** Declined payment → same booking, preserved details, explanation and retry/change option if supported. Cancel → same draft/booking with status checked first. Unknown gateway outcome → Checking payment, safe refresh/status check, reference and support; no second charge while the prior attempt is unresolved. Invalid/expired resume link → recover access through verified contact, not a dead-end home redirect. Paid resume links open the receipt/status view.

## 5. Mobile wireframes and desktop behavior

Low-fidelity wireframes specify hierarchy and behavior, not a replacement visual identity. Amounts are placeholders. All four themes skin the same semantic components.

```text
A. HOME / SEARCH                 B. RESULTS
┌──────────────────────────┐     ┌──────────────────────────┐
│ Logo       Cart (1)  Menu │     │ Back       Your vehicles │
│ Book your journey        │     │ Pickup → Destination     │
│ Service [Airport      v] │     │ Date · Time · People Edit│
│ Pickup [Choose location] │     │ N options  Filter / Sort │
│ Drop-off [Choose place ] │     │ ┌──────────────────────┐ │
│ Date [     ] Time [    ] │     │ │ Image   Vehicle group│ │
│ Passengers [ ]           │     │ │ Seats · Bags · A/C   │ │
│ + Return / service needs │     │ │ Availability state   │ │
│ [    Search vehicles   ] │     │ │ Trip total: CUR X    │ │
│ Inline errors / help     │     │ │ Included / exclusions│ │
│ Theme story / photography│     │ │ Price details [open] │ │
└──────────────────────────┘     │ │ [Book now] [Add cart]│ │
                               │ └──────────────────────┘ │
                               │ Cart (1) CUR X [View]    │
                               └──────────────────────────┘

C. OPTIONAL OTHER-ENTRY DETAILS  D. CART WIDGET (OVERLAY)
┌──────────────────────────┐     ┌──────────────────────────┐
│ Back to results   Cart(1) │     │ Your cart           [X]  │
│ Vehicle group · gallery  │     │ Trip 1 · vehicle group   │
│ Seats · Bags · features  │     │ Route · dates · people   │
│ Your trip         [Edit] │     │ [Edit trip] [Change car] │
│ Availability / checked at│     │ Extras / extra km [Edit] │
│ Trip total CUR X         │     │ Line total CUR X Remove  │
│ Fare / fees / taxes      │     │ [Continue searching]     │
│ Included km / overage    │     │ Promo code (optional)    │
│ Terms / cancellation [v] │     │ Fare · fees · tax        │
│ Optional extras      [v] │     │ Total CUR X              │
│ Inline price-change alert│     │ Price-change review      │
│ CUR X   [Book now]       │     │ [Checkout]               │
│         [Add to cart]    │     │ Removed item? [Undo]     │
└──────────────────────────┘     └──────────────────────────┘

E. CHECKOUT 1 / DETAILS           F. CHECKOUT 2 / REVIEW
┌──────────────────────────┐     ┌──────────────────────────┐
│ Back   Details → Payment │     │ Back   Details → Payment │
│ Trip(s) · total    [View]│     │ Trip(s) / contact [Edit]│
│ First name / Last name   │     │ Fare, extras, fees, tax  │
│ Email                    │     │ Discount · Total CUR X  │
│ Country code / Phone     │     │ Pay full / advance /    │
│ Required address fields  │     │ check-in (when enabled) │
│ Flight / notes (optional)│     │ Due now CUR Y           │
│ Errors beside fields     │     │ Due later CUR Z · when  │
│ [Continue to review]     │     │ Terms [view] [accept]   │
│ Your details are retained│     │ [Pay CUR Y securely]    │
└──────────────────────────┘     └──────────────────────────┘

G. PAYMENT / RECOVERY            H. CONFIRMATION
┌──────────────────────────┐     ┌──────────────────────────┐
│ Payment status           │     │ Confirmed / Pending /    │
│ Reference ABC            │     │ Quotation received       │
│ CUR Y · trip summary     │     │ Reference ABC [Copy]     │
│ Opening secure payment… │     │ Trip date / pickup       │
│ [Continue if needed]     │     │ Vehicle group / extras   │
│                          │     │ Paid Y · Due Z · due date│
│ On return: Checking…     │     │ What happens next        │
│ Failed: explanation      │     │ [Manage booking]         │
│ [Retry same booking]     │     │ Receipt / Save to calendar│
│ Unknown: [Check status]  │     │ Confirmation sent to …   │
│ [Contact support]        │     │ Help / support           │
└──────────────────────────┘     └──────────────────────────┘

I. MANAGEMENT / VERIFIED ACCESS
┌──────────────────────────┐
│ Manage booking ABC       │
│ Trip status · Payment    │
│ Route / schedule / extras│
│ Next event + time        │
│ Driver/vehicle if assigned│
│ Balance + receipt        │
│ [Pay balance if eligible]│
│ [Request change]         │
│ [Cancel / request cancel]│
│ Policy / fees / support  │
└──────────────────────────┘
```

Management entry without a valid link first shows reference + contact verification. Changes/cancellation are new capabilities: expose only actions supported by lifecycle policy and authorization. Start with a tracked request to the team when immediate modification is unsupported; show request status rather than pretending the booking changed. Receipt/calendar generation also needs implementation, not just links.

| Surface | Phone: 320–430px | Tablet / desktop |
|---|---|---|
| Search | Single column, visible labels, service selector; optional/service-specific fields revealed as needed. Location editor may use a full-height sheet. | At 768px use paired fields where they fit; at 1024px use a compact search panel. Theme 04 may retain its rail; other themes may retain a horizontal desk. |
| Results | One card per row, one sticky compact cart bar only when nonempty. Filters in an accessible sheet. | Two/three cards according to usable content width, not viewport alone; filters inline or sidebar. Preserve Theme 03 editorial treatment and Theme 04 rail without squeezing cards. |
| Details from other entry points | Gallery, essentials, trip summary, price and terms in reading order; confirm trip data before actions. This is not linked from booking results. | Gallery/content left and booking summary right; no duplicate actionable forms. |
| Cart widget | Existing float opens as a usable bottom/full-height sheet with stacked trips and item edit with price preview and Save/Cancel. It remains available from results and details. | Existing float may open as a side panel with trip list, edit controls, total and Checkout. No cart page. |
| Checkout | Two short stages; summary accessible at top, payment action at bottom; no full search form above contact details. | Same two logical stages, form left and persistent summary right. Browser Back restores the prior stage and inputs. |
| Payment / confirmation / manage | Status first, essential trip details next, primary next action, then expandable detail. | Comfortable constrained reading width; trip details and payment/activity panels can sit side by side. |

Common rules: minimum 44px action targets, readable 16px form text, visible keyboard focus, associated field errors and summary focus on failure, live announcements for updated totals/status, text alongside status colors, reduced-motion support, focus trapping/restoration for sheets, no horizontal page scroll at 320px or 200% zoom. Sticky controls must clear the keyboard, consent/popup UI and safe areas; retain normal-flow access to every action.

## 6. Shared versus theme-specific changes

| Shared implementation | Theme-specific presentation |
|---|---|
| Journey context, service configuration, normalization, validation and restore rules | Hero, navigation/footer, photography and story content |
| `TripSummary`, `TripEditor`, `VehicleOptionCard`, `PriceBreakdown`, `AvailabilityNotice` | Typography hierarchy, colors, borders, radii, shadows and icon styling |
| Cart store/API, line editing, selected checkout draft, duplicate-request protection | Search desk placement and desktop composition within shared responsive constraints |
| Checkout stages, terms, customer fields, payment options and recovery | Default classic cards; Theme 02 rounded contemporary surfaces; Theme 03 paper/ink editorial panels; Theme 04 executive red accents |
| Confirmation, receipt data, lifecycle/payment status, authorized management actions | Transactional skin applied through manifest assets, including resume/error pages |

Reuse Laravel/Blade and the existing service layer; this plan does not require a framework rewrite. Extract repeated inline scripts and display calculations into shared modules/presenters incrementally. Components render server-derived amounts; themes must never own fare arithmetic or payment logic. Preserve `BookingFlowService`, configured form semantics, `CartService`, currency conversion, terms, `BookingLifecycleService` and gateway adapters behind clearer contracts.

Proposed state contract: an opaque `journey_id` with revision, tenant/session owner, service code and ID, package, rental mode, pickup/drop-off labels plus coordinates/IDs, dates/times plus explicit timezone, passengers, return leg and configured fields. Each cart line stores a snapshot and quote revision; direct checkout references a separate selection/draft. Keep personal information out of URLs. Theme selection changes presentation only.

Quote response: state (`priced`, `quotation_required`, `unavailable`, `error`), currency, fare/adjustments/extras/taxes/fees/total, included distance/time, overage rules, amount due now/later, checked-at time and expiry where supported. Distinguish display currency from gateway charge currency if different. Never introduce a countdown or availability count without backend evidence.

State transitions: editing → checking → priced/needs-quotation/unavailable → selected → checkout → booking-created → payment-pending → paid/failed/cancelled/unknown. Keep booking lifecycle separate from payment state and from amount still outstanding after an advance. Reconcile asynchronous gateway events on the server; browser redirects cannot establish payment success.

## 7. Error and continuity contract

| Event | Required behavior |
|---|---|
| Invalid service fields, dates or locations | Preserve all entered values, show per-field errors and focus summary; require valid resolved location data where needed; never silently inject defaults. |
| Empty inventory vs filtered-out results | Keep original trip and distinguish the two; Edit dates/service versus Clear filters. Only show alternatives actually returned by the backend. |
| Pricing/distance provider failure | Preserve trip, display Retry and quotation option when allowed; suppress payable zero/fake availability. |
| Edit cart line | Preview revised quote; Save applies the whole validated line atomically; Cancel keeps original. Recheck add-on eligibility and promotions. |
| Availability or price changes | Identify affected line; preserve other lines and customer details; show alternatives/quotation, or old/new totals with explicit acceptance before payment. |
| Refresh / Back / two tabs | Restore the correct draft and revision, not the last global session search; detect stale writes and offer reload of the newer version. |
| Expired session/search | Recover authorized saved draft where possible; otherwise explain expiry and restore non-sensitive trip data for resubmission. Do not claim availability remains valid. |
| Add/checkout response lost | Reuse operation key and retrieve result before retrying; return the original cart line/booking for the same payload. Conflicting payload on same key is rejected. |
| Payment declined/cancelled | Keep the original booking and show current authoritative status, amount due and eligible retry action. |
| Gateway return absent, webhook delayed or out of order | Show pending verification and reconcile; repeated notifications produce one financial effect and one confirmation notification. Late failure/cancel cannot downgrade paid. |
| Invalid/expired management link | Generic error and verified recovery; no customer details disclosed. |
| Empty cart / final item removed | Show intentional empty-cart state with Continue searching and preserved trip context; do not surprise-redirect during editing. |

## 8. Phased implementation plan

| Phase | Deliverable / dependencies | Exit gate |
|---|---|---|
| 0 — Executable baseline | Isolated seeded environment, every theme force-selected through supported settings/flags, known priced/unpriced/unavailable groups, all configured public services and payment variants. Capture current phone/desktop journeys and route reachability. No production gateway charges. | Reproducible baseline plus recorded failures; confirm exact field requirements, availability policy and change/cancellation policy. |
| 1 — Booking correctness | Canonical journey context; details handoff; real cart item update; database cart consistency; separate direct draft; server idempotency. Protect confirmation access; derive resume amounts server-side; unify guarded payment transitions. | Integration tests demonstrate itinerary fidelity, direct/cart isolation, duplicate protection, authorized access and safe callback/cancel races. |
| 2 — Shared results / cart widget | Extract summary/editor/cards/price/availability components; one-column mobile results with enough detail to select without another page, edit cart widget, persistent error/recovery states. | Both paths work for all configured bookable services and quotation fallbacks with accurate totals. |
| 3 — Checkout / payment / aftercare | Two-stage checkout, preserved customer fields, conditional sections, same-booking retry, pending verification, concise confirmation and management entry. Add policy-backed actions or tracked requests. | Full, advance, check-in, quotation, failed and unknown payment flows pass; no loss of state or duplicate charge/booking. |
| 4 — All-theme polish | Apply shared contracts to all four identities, fix Theme 02 CTA, simplify stylesheet ownership and migrate Theme 04 rail/Theme 03 desk to new components. Visual work can start earlier after component contracts stabilize. | Screenshots and accessibility checks for every theme/stage/device; no functional theme forks. |
| 5 — Release / monitor | Update legacy source contracts that encode replaced layouts; execute full matrix, sandbox gateway checks and controlled staged rollout. Keep payment correctness fixes independent of cosmetic rollback. | Zero unresolved P0/P1 journey defects, observability and rollback verified, release flags changed only through normal release process. |

Measure search validation failures, zero-result rate, details-to-selection, cart-to-checkout, checkout-stage abandonment, payment failure/unknown/recovery, duplicate attempts suppressed and management completion. Include theme, viewport class, journey/path and non-sensitive correlation IDs; do not log contact details, payment tokens or full form payloads.

## 9. Acceptance criteria and test matrix

Every cell below must execute the complete applicable sequence, not just render a homepage. `C` = add to cart → review/edit in cart widget → checkout; `D` = Book now → checkout for the selected trip. Neither path visits a cart page or vehicle-details page from results. Both include home/search/results, payment, confirmation and management. Test catalogue/rate-chart entry to the separate details route as an additional path.

| Theme | Mobile C | Mobile D | Desktop C | Desktop D |
|---|---|---|---|---|
| Default | Required | Required | Required | Required |
| Theme 02 | Required | Required | Required | Required |
| Theme 03, enabled in test | Required | Required | Required | Required |
| Theme 04, enabled in test | Required | Required | Required | Required |

Run primary phone flows at 390px and desktop at 1440px; add boundary coverage at 320/360/430, 768 and 1024px. Exercise mobile Safari and Chrome and supported desktop browsers. Verify disabled Theme 03/04 resolve to Default without mixed assets.

1. **Trip fidelity:** Service, package, rental mode, coordinates/labels, passenger count, all dates/times and return leg remain identical across stages, refresh, Back and validation retry. Two simultaneous journeys never overwrite each other. Test every enabled configured public service, not a hard-coded subset.
2. **Search:** Required fields and conditional fields obey server configuration; invalid input prevents search; loading has a recoverable state; empty inventory, filter-empty, unavailable quote and provider failure have distinct helpful actions.
3. **Selection:** Each result card shows group, capacity, inclusions, availability semantics, currency and total basis, with an expandable price/inclusion explanation if needed. Selection works without a vehicle-details page. Unsupported/unpriced selections cannot silently proceed as free bookings. Group representation is not presented as a specific assigned car.
4. **Cart widget path:** Add two different trips, open the widget, edit one, change its vehicle/date, adjust eligible extras/km, remove/undo and apply/remove a promo. Only the selected line changes; widget totals reconcile with checkout; a stale quote or invalid promo requires review. Last-item removal renders an empty widget state. Checkout opens directly from the widget.
5. **Direct path:** Start with a populated cart; Book now purchases only the new selection. Existing cart survives success, cancel and abandonment. Direct editing and extras use the same validation/pricing rules as cart checkout.
6. **Checkout:** Two logical stages, only necessary required inputs, clear optional fields, accessible errors and terms, preserved customer/payment selection on return and repricing. Enabled full/advance/check-in/quotation modes show correct labels and amounts; disabled options are not accepted by the server.
7. **Pricing:** Fare, adjustments, extras, extra km, promo, taxes/fees and total reconcile at every stage under currency changes. Advance amount plus remaining balance equals total under the authoritative rounding rules. Payment-resume amount tampering cannot change the payable amount.
8. **Availability:** A vehicle becoming unavailable between search/add/checkout identifies the affected line without losing the draft. Two concurrent attempts at the last available capacity cannot both obtain an unsupported guarantee. Availability-bypass mode uses accurate request/confirmation language.
9. **Duplicates/concurrency:** Double taps, pressing Enter repeatedly, two tabs and lost-response retry produce one line/booking per operation. Replayed and simultaneous gateway events produce one payment effect; late cancellation/failure cannot reverse paid. Intentional duplicate trips remain possible through a new operation.
10. **Payment recovery:** Test success, decline, user cancel, network timeout, gateway initiation failure, webhook-before-return, return-before-webhook, no browser return, duplicate callbacks and expired resume link. Retry retains the booking reference; unknown status is reconciled before another attempt. Paid links show receipt/status.
11. **Confirmation:** State comes from the booking/payment record, not query-string labels. Full, advance, unpaid/check-in and quotation states are truthful. Show reference, each trip, paid/due amounts, next step, support and working management access; avoid promising an email that was not queued/sent.
12. **Management/security:** Authorized users see relevant trips and lifecycle/payment state; unknown states do not show Confirmed by default. Normalize phone verification consistently. Invalid contact/reference and expired tokens reveal no private data. Change/cancel/payment actions enforce ownership, lifecycle eligibility and policy server-side, including multi-item bookings.
13. **Responsive/accessibility:** No clipped prices/actions, horizontal overflow or obscured focus at specified widths/zoom. Keyboard-only completion, screen-reader labels/status/errors, reduced motion, appropriate contrast and usable sheets/modals pass in each theme. Theme identity remains recognizable across payment/resume/confirmation/status.
14. **Persistence/release:** Reloaded server totals match displayed totals; no client price controls payable amounts. Gated theme fallback, disabled guest booking, disabled payment methods and unavailable optional add-ons have deliberate supported states. Rollback preserves drafts and existing payment reconciliation.

Retain useful existing tests such as `PublicCheckoutStatePreservationContractTest`, `PublicCheckoutAvailabilityContractTest`, `PublicCheckoutCurrencyPersistenceContractTest`, `CheckoutPromotionRevalidationContractTest`, `PublicBookingPackageContextContractTest` and `ThemeParallelCheckoutPaymentContractTest`. Supplement source contracts with request/database integration tests and parameterized browser journeys; update assertions that currently require the old full-search checkout placement. No tests or live transactions were run for this planning-only review.
