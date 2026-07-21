# Theme 03 global shell

## Scope completed

Theme 03 now has its own header, desktop navigation, mobile navigation state machine, services submenu, currency selector, contact utility, cart indicators, footer, shared page/state primitives, 404 page, and maintenance state. The markup and presentation are independent of Theme 01 and Theme 02, while every link, setting, route, currency action, cart endpoint, and CMS service owner remains shared.

The visual direction uses a warm editorial paper/ink system, ruled navigation, numbered service links, square controls, and a typographic dark footer. It deliberately avoids reproducing the blue reference or either released theme's shell.

## Runtime contracts

- Header and footer resolve through `theme_partial()` and exist only below `partials/themes/theme-03`.
- Theme 03 shell CSS remains scoped to `body.theme-theme-03`.
- Theme 03 JavaScript loads only while Theme 03 is active, via its manifest `script` entry.
- The mobile menu synchronizes `aria-expanded` and `aria-hidden`, traps focus, closes on Escape/backdrop/link activation, restores focus, and locks body scrolling.
- Existing `.currency-option`, `cartBadge`, and `mobileCartBadge` hooks are unchanged so the shared currency and cart code retains ownership.
- Services remain sourced from `$headerServices`; no service routes or content sources were duplicated.
- Footer content continues to use the existing Website Settings keys, including optional newsletter, link groups, contact channels, social accounts, copyright, and payment-method blocks.
- Shared Theme 03 primitives cover mastheads/breadcrumbs, buttons, inputs/selects/textareas, validation, alerts, loading/empty states, modals, pagination, accordions, carousel controls, tables, and CMS rich text without changing their markup or JavaScript contracts.
- The 404 view retains the complete released-theme branch and adds a Theme 03-only branch. Maintenance middleware passes the normalized active theme to the existing maintenance view, whose prior rendering remains the default branch.
- Theme 03 remains release-gated by `WEBSITE_THEME_03_ENABLED=false`. Its missing mandatory hero intentionally prevents an incomplete home shell from being enabled during development.

## Booking/search coverage

The booking/search experience remains a required redesign surface. All service-type tabs and all existing form fields, conditional fields, validation, labels, and submission behavior are protected by the Phase 0 baseline contract and will be restyled—not replaced—when the Theme 03 home/booking section is implemented.

## Verification

Focused checks are recorded in the root implementation tracker after each session. Visual screenshot comparison is still blocked locally while PostgreSQL is unavailable; this is not a release-gate exemption.
