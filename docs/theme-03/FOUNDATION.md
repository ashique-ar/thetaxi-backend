# Theme 03 Foundation

Last updated: 2026-07-20

## Release behavior

- Theme 03 is registered in `config/website_themes.php` but is unreleased by default.
- `WEBSITE_THEME_03_ENABLED=false` is the surfaced environment default.
- Unreleased and unknown stored identifiers normalize to the current `default` theme.
- The Angular Website Settings selector has not been changed, so production users cannot select Theme 03.
- Enabling the environment gate alone is for local/staging implementation. It is not the final production release approval.

## Manifest contract

Each theme declares its label, release state, preview reference, general stylesheet, checkout stylesheet and mandatory themed partials.

- Theme 01/default and Theme 02 retain their existing asset files and fallback behavior.
- Theme 03 owns `assets/css/themes/theme-03/theme-03.css` and `assets/css/themes/theme-03/checkout.css`.
- Theme 03 requires dedicated `header`, `hero` and `footer` partials. Until Phase 2 creates them, resolving one throws a clear `LogicException` instead of falling back to Theme 01.
- Theme 03's preview remains `null` until the real design has been rendered and approved. The portal option remains absent.

## CSS isolation

- All foundation selectors are under `body.theme-theme-03`.
- The foundation defines semantic surface, text, state, accent, focus, layout, spacing, radius and typography tokens.
- Reduced-motion behavior and visible focus are present before page-specific presentation is added.
- The checkout has an explicit Theme 03 entry point so it cannot inherit `checkout-theme-01.css` through an `else` branch.
- Theme 03 does not load Tailwind or another runtime framework CDN.

## Font and licence decision

No new font files or licences were added in the foundation phase.

- Display fallback: Georgia / Times New Roman / serif.
- UI fallback: the already-loaded Poppins stack, then Segoe UI / Arial / sans-serif.
- Body fallback: the already-loaded Roboto stack, then Segoe UI / Arial / sans-serif.

If a self-hosted display face is approved later, record its source and licence here before adding its files. Theme 03 must continue to render correctly with the documented system fallbacks.

## Verification

`tests/Unit/WebsiteThemeManifestTest.php` proves:

- Theme 03 is excluded from allowed themes by default.
- Invalid/unreleased values normalize to `default`.
- Enabling the config gate admits Theme 03.
- Missing mandatory Theme 03 partials fail clearly.
- Optional fallback and current Theme 02 partial resolution remain available.
- General and checkout asset entry points exist and the Theme 03 CSS is namespaced without a Tailwind CDN.
