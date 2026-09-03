# Phase 8 release readiness — 2026-09-03

## Completed engineering evidence

- Theme 03 and Theme 04 remain disabled in `.env.example` and `phpunit.xml`; the production selector API therefore omits both.
- The complete theme contract suite covers isolation, mandatory views/assets, routes, shared form/booking/cart/payment ownership, accessibility primitives, reduced motion, long-content wrapping, mobile target sizes, and one-setting rollback.
- Local rollback was exercised from Theme 03 to Theme 04 and back to Theme 03 through the existing `active_theme` setting and cache key. Each resolver result matched the stored selection and the public homepage returned HTTP 200.
- Fresh local evidence is stored in `docs/phase-08-evidence`: 21 Theme 03 captures across seven route families at 390x844, 768x1024, and 1440x900; and 10 Theme 04 captures across five route families at 390x844 and 1440x900.
- Theme 04 evidence was reviewed against the canonical `D:\projects\cassons\thetaxi\b5aef749-7fa6-43da-a0b8-2d5ba017eb72.png` reference. Theme 03 and Theme 04 are visibly separate systems.

## Release blockers that cannot be self-approved

- This is not the complete route/state/browser matrix. Populated checkout, add-ons, coupons, quotation, payment resume, callback failures, success branches, missing-media/large-price fixtures, and Safari/iOS require controlled fixtures or real sessions.
- The local Laravel debug toolbar is present in current screenshots and must be disabled for approval captures and trustworthy Lighthouse measurements.
- No authorized staging environment or allowed test-gateway booking was supplied in this session. No provider/payment call was made.
- Desktop/tablet/mobile captures have not been approved by a human design/business owner. Automated distinctness tests and assistant inspection are not approval.
- Theme 01 and Theme 02 still need the Phase 0 cross-browser visual baseline matrix.

Consequently the Phase 8 QA, screenshot approval, staging booking, business approval, and production-enable gates remain open. Enabling either production flag before these items are signed off would violate the plan.

