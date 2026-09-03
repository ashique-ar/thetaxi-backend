# Theme 04 Discovery and Vehicle Journey

Theme 04 Phase 4 is anchored to `D:\projects\cassons\thetaxi\b5aef749-7fa6-43da-a0b8-2d5ba017eb72.png`. The implementation follows its compact white showroom, thin grey dividers, restrained cards and red action rules without using any content from the reference.

## Completed coverage

- Search summary, modify-search booking form, sorting, result count, no-results state and quotation modal.
- All shared vehicle-card pricing, availability, recommendation, discount, inquiry and quotation states.
- Vehicle gallery, thumbnails, metadata/specification ledgers, live price, shared booking form and direct actions.
- Existing CMS-card variants used by fleet/discovery routes.
- Predefined/custom location controls, all dynamic field types and distance information.
- Cart dock and mobile actions with safe-area offsets, bounded viewport height and sticky-detail fallback below the tablet breakpoint.

Only theme-specific classes and body-scoped CSS were added. Routes, controllers, CMS/settings ownership, fields, validation, pricing, cart and scripts remain shared.

## Release status

`WEBSITE_THEME_04_ENABLED` remains false by default and the portal selector remains unchanged. Automated coverage is not design approval; the full route/state/viewport comparison against Themes 01-03 and the canonical reference remains release-blocking.
