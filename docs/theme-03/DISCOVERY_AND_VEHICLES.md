# Theme 03 Discovery and Vehicle Journey

Phase 4 uses the existing search, vehicle-card, vehicle-detail, CMS-card, location, distance and cart components. Theme 03 adds only presentation hooks and a Ceylon Modernist final CSS layer; routes, controller data, pricing decisions, quotation rules, field names, validation and JavaScript owners remain shared.

## Completed coverage

- Search summary, modify-search booking form, sorting, result count, no-results state and quotation modal.
- All shared vehicle-card states, including recommended, discount, quotation-only, unavailable, inquiry-only and bookable actions.
- Vehicle gallery, thumbnails, specifications, additional details, live price, booking form and direct actions.
- Existing CMS-card variants used by fleet/discovery routes.
- Predefined/custom location controls, every dynamic field type and the complete distance ledger.
- The cart dock and mobile actions, with safe-area offsets, bounded viewport height and non-sticky detail booking below the tablet breakpoint.

The presentation is square, warm-paper and ink-led, with catalogue shadows and ruled ledgers. Theme 01 and Theme 02 do not enter these selectors.

## Release status

Theme 03 remains gated off by default. Automated contracts cover the real DOM hooks and shared functional contracts; the complete cross-theme screenshot/state matrix is still required before release.
