# Theme 04 Reference and Foundation

## Canonical reference

Theme 04 must always be developed against this exact source image:

`D:\projects\cassons\thetaxi\b5aef749-7fa6-43da-a0b8-2d5ba017eb72.png`

Verified source dimensions: **1086 × 1448 pixels**.

The reference controls Theme 04's visual direction: white and soft-neutral surfaces, strong red actions and rules, compact executive navigation, an integrated hero/booking composition, clean fleet cards, photographic service tiles, concise trust bands, testimonial cards, and a light multi-column footer. It is a presentation reference only. Its Casons-specific logo, phone numbers, fleet names, prices, claims, testimonials, partner names, and footer copy are not content sources for the application.

## Architecture and release state

- Identifier: `theme-04`
- Label: `Theme 04 - Executive Redline`
- Release gate: `WEBSITE_THEME_04_ENABLED=false` by default
- Stylesheet: `public/assets/css/themes/theme-04/theme-04.css`
- Late page/isolation stylesheet: `public/assets/css/themes/theme-04/pages.css`
- Checkout stylesheet: `public/assets/css/themes/theme-04/checkout.css`
- Shell script: `public/assets/js/themes/theme-04/shell.js`
- Required strict partials: header, hero, footer
- Portal selector: intentionally absent until the shared coverage ledger and release gates pass

Theme 04 uses separate Blade presenters and body-scoped `t4-*` styling while retaining the same controllers, collections, settings, routes, booking component, pricing/vehicle component, currency/cart hooks, CMS card normalization, SEO head, and released Theme 01/02 branches. Theme 03 remains independent and is not replaced.

The released `public/assets/css/style.css` remains loaded because shared booking, CMS, checkout, and service-builder behavior still depends on it. It is not a visual owner for either preview theme. Both Theme 03 and Theme 04 now have a route-aware `<main>` boundary plus a theme-specific page bundle loaded after page-local styles. Shared legacy classes such as `.home4-banner-section`, `.filter-wrapper`, `.section-title`, and `.container` are reset only below the active preview theme body class. Theme 01 and Theme 02 therefore retain their existing cascade, while Theme 03 and Theme 04 cannot silently share the same final presentation.

The cascade boundary also accounts for styles emitted by individual views. A whole-theme stylesheet replay at the end of `<body>` was tested and rejected on 2026-07-21 because it reapplied layout rules and visibly changed the Theme 04 homepage. The corrected ownership model is narrower: the generic legacy homepage `@push('styles')` block is not emitted for Theme 03/04, the route page bundle is not loaded on `home`, and inner-page bundles remain immediately after page-local head styles. Theme 01/02 still receive their legacy homepage CSS. Preview theme rules remain body-scoped, so shared behavioral CSS stays available without becoming the final visual owner.

## Homepage coverage aligned on 2026-07-21

The Theme 04 homepage now has distinct presenters for the shell, hero, booking desk, partner register, featured fleet, services, destinations, things to do/packages, offers, why-choose-us proof, testimonials, blog/editorial content, and FAQ. Every presenter remains behind the same existing section condition and uses the same managed inputs as the released branch.

The post-correction desktop evidence is `THEME04-HOME-CASCADE-FIX-V2-1440x1200-2026-07-21.png`. It confirms the page renders with the Theme 04 shell and booking desk without the generic homepage block, page bundle, or final stylesheet replay. The booking-to-services spacing is explicitly owned by Theme 04 for the empty/disabled adjacent-section state visible in local data.

`THEME04-HOME-COMPACT-BOOKING-V2-1440x1200-2026-07-21.png` is the subsequent scale correction against the canonical reference. The desktop hero was reduced from 610px to 520px; the journey card from 450px to 390px; its service rail from 112px to 90px; service rows from 62px to 50px; and principal controls from 46/44px to 36/34px. Header, grid, label, button, and notice spacing were tightened together so the existing dynamic booking fields remain intact without the released theme's oversized rhythm.

`THEME04-HOME-THEME-OWNED-BOOKING-1440x1200-2026-07-21.png` verifies the booking CSS ownership correction. Shared `booking-form.css` and `package-buttons.css` now load before the active theme presentation for every theme. Theme 04 therefore owns the final visual state of its fields, tabs, transfer selector, return toggles/details, package choices, alerts, notice, and submit action; the shared files continue to own only common structure and behavior. Theme 01, Theme 02, and Theme 03 retain their respective existing booking designs through the same ordering contract.

`THEME04-HOME-READABLE-BOOKING-1440x1200-2026-07-21.png` records the accessibility correction after the first compact pass made functional copy too small. The card footprint remains restrained, but service labels are now 11px, field labels and notices 10.5px, values 13px, tabs/actions 12px, controls 40/38px, and service rows 54px with increased line height and spacing. These values are deliberate lower bounds for the dense desktop component rather than decorative microtype.

`THEME04-MOBILE-SIDE-RAIL-V2-500x1200-2026-07-21.png` verifies that mobile retains the same Theme 04 booking composition instead of switching to a generic horizontal tab scroller. The service selector remains a vertical side rail at 90px and narrows to 78px below 480px. Labels wrap to two lines without ellipsis, the card header remains horizontal, and the form column retains the readable control and font scale.

Textarea fields use separate multiline geometry rather than inheriting the single-line control height. Their wrapper is at least 82px, the textarea is at least 80px (88px below 480px), content scrolls instead of being clipped by the shared ellipsis rule, and resizing is vertical only. The visible label is explicitly associated with the existing textarea ID; names, values, validation and submission remain unchanged.

`THEME04-HOME-MOBILE-SIDE-TABS-500x900-2026-07-21.png` verifies that responsive Theme 04 keeps the same vertical service rail instead of switching to the shared horizontal tab strip. Tablet/mobile use a 90px rail and the narrowest viewport uses 78px, while the form remains a flexible right column and individual fields stack within it. The local debug toolbar obscures the lower screenshot area but does not affect the rail/form geometry.

## Page distinctness system

Theme 04 no longer relies on the released themes' page appearance. `layouts/app.blade.php` assigns every request a route-derived `theme-page-*` class and, for Theme 04 only, wraps content in a `t4-site-main` page shell. The manifest loads `public/assets/css/themes/theme-04/pages.css` after page-local style stacks, giving Theme 04 the final presentation layer without changing controllers, forms, field names, pricing, cart, payment, CMS, or SEO behavior.

The dedicated page bundle owns these public families independently:

- about and company content;
- contact and inquiry;
- FAQ search/category/accordion;
- point-to-point, corporate transfer, and CMS service-builder sections;
- CMS index, detail, search, and featured content;
- vehicle search/results and vehicle detail;
- rate chart;
- checkout, payment resume/success, callback recovery, and booking status;
- the standalone WebXPay redirect state;
- shared mastheads, forms, cards, accordions, tables, alerts, and rich content.

CSS-only treatment is not automatic release approval. The visual QA gate must compare every reachable route/state against Theme 01, Theme 02, Theme 03, and the canonical Theme 04 reference. Any surface that remains materially similar must receive a stronger Theme 04 presenter before the selector can be released.

## Visual evidence, 2026-07-21

- `THEME04-LIVE-AUDIT-1440x1200-2026-07-21.png`: before-remediation live capture that exposed the oversized hero and inherited page-system risk.
- `THEME04-HOME-V2-1440x1200-2026-07-21.png`: corrected hero scale and visible reference-style booking header/rail.
- `THEME04-ABOUT-V2-1440x1200-2026-07-21.png`: distinct editorial company page.
- `THEME04-CONTACT-1440x1200-2026-07-21.png`: redline inquiry directory and form.
- `THEME04-CORPORATE-TRANSFERS-1440x1200-2026-07-21.png`: service-builder composition.
- `THEME04-RATE-CHART-1440x1200-2026-07-21.png`: rate matrix evidence before the final late-bundle ordering correction.
- `THEME04-RATE-CHART-V2-1440x1200-2026-07-21.png`: corrected reference-style light masthead and dark/red ruled rate ledger after the Theme 04 bundle moved behind page-local styles.
- `THEME04-BOOKING-STATUS-1440x1200-2026-07-21.png`: booking lookup and sparse configured-footer state.
- `THEME04-HOME-TABLET-2026-07-21.png` and `THEME04-HOME-MOBILE-2026-07-21.png`: live responsive hero/booking composition at 1024 and the 500px phone breakpoint.
- `THEME04-ABOUT-TABLET-2026-07-21.png` and `THEME04-ABOUT-MOBILE-2026-07-21.png`: responsive company folio.
- `THEME04-CORPORATE-TRANSFERS-MOBILE-2026-07-21.png`: stacked mobile service media, managed heading/subheading, and unchanged inquiry form.
- `THEME04-FAQ-1440x1200-2026-07-21.png`, `THEME04-FAQ-TABLET-2026-07-21.png`, and `THEME04-FAQ-MOBILE-2026-07-21.png`: restored CMS-owned FAQ route, search/filter state, and empty-record state.
- `THEME04-RATE-CHART-TABLET-2026-07-21.png` and `THEME04-RATE-CHART-MOBILE-2026-07-21.png`: responsive ledger/table and mobile rate cards.
- `THEME04-BOOKING-STATUS-MOBILE-2026-07-21.png`: responsive booking lookup sheet.

Live route checks returned 200 and loaded the Theme 04 route class plus `pages.css` for `/`, `/about`, `/contact`, `/inquiry`, `/point-to-point`, `/corporate-transfers`, `/rate-chart`, `/booking/status`, `/faq`, and `/news`. `/checkout` retained its existing empty-cart redirect to `/`.

The FAQ blocker is resolved locally. The existing targeted migration `2026_06_12_000002_create_faq_categories_and_link_faqs.php` was applied. The next request exposed a second existing owner defect: `faq.blade.php` referenced nonexistent `faq.index` and rendered two hardcoded travel/visa accordions instead of the controller's `$featuredFaqs` and `$faqs`. The public URI remains `/faq` with route name `faq`; the view now uses CMS FAQ/category records, registered FAQ display settings, search, category selection, pagination, managed rich answers, and a managed empty state. No theme-specific FAQ content was introduced.

The partial responsive matrix above is evidence, not full sign-off. Contact, CMS/news, corporate tablet, booking-status tablet, exact 390px/360px devices, populated FAQ/category/search, populated checkout/payment states, and Theme 01/02/03 side-by-side comparisons remain unchecked. The next shared checkpoint is that complete matrix, hidden/empty adjacency verification, and extraction of any remaining shared-looking surface into a stronger presenter.
