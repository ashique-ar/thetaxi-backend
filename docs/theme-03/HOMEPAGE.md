# Theme 03 homepage implementation

## Completed sections

### Hero

The Theme 03 hero is a dedicated mandatory presenter at `partials/themes/theme-03/hero.blade.php`. It creates the Ceylon Modernist asymmetric cover using only the existing global `banner_heading`, `banner_subheading`, `banner_image`, brand name, and site-tagline settings.

- No Theme 02-specific slider media is reused.
- No new CTA, form field, route, marketing claim, or runtime slider was introduced.
- The managed hero image is server-rendered, eagerly loaded, high priority, and dimensioned to control layout shift.
- The booking component remains a separate section immediately after the hero.

### Booking/search journey desk

The Theme 03 presenter at `partials/themes/theme-03/booking-form.blade.php` wraps the existing `components.booking-form` functional owner. Theme 01 and Theme 02 retain the original homepage branch.

The presenter and namespaced styles cover:

- every database-owned `BookingFormTab`, including custom codes and service-code mappings;
- search and inquiry submission modes and their existing labels/routes;
- autocomplete, airport, conditional, predefined and doorstep location modes;
- date, time, select, radio, checkbox, text, textarea, number and hidden fields;
- package selectors, one-package hidden selection and empty package states;
- Ride Now return-trip controls and self-drive/with-driver drop-off synchronization;
- validation summaries and field errors, loading/disabled submit state, booking notice and responsive layouts.

The Theme 03 shell script adds `tablist`/`tab`/`tabpanel` relationships plus Arrow, Home and End keyboard navigation. It invokes the existing tab click behavior and does not submit, transform, or duplicate booking data.

### Partner register

The Theme 03 presenter at `partials/themes/theme-03/partner-register.blade.php` turns the existing partner collection into a ruled, horizontally scrollable trust register. It retains the current section-title setting, partner links, managed logo paths, fallback logo setting, alt text, lazy loading, and marquee class hooks.

- Logos use a restrained monochrome treatment with a colour/focus response instead of the existing floating logo-card presentation.
- Numbering is presentational only; no partner fields, content source, route, claim, or interaction was added.
- Empty-section behavior is still owned by the unchanged condition in `home.blade.php`.

### Featured vehicle catalogue

The Theme 03 presenter at `partials/themes/theme-03/featured-vehicles.blade.php` renders the existing featured data as a dark editorial catalogue with numbered vehicle plates. It keeps the four-vehicle slide grouping, CMS fleet route, section settings, responsive grid, and existing Swiper selectors.

Every vehicle still renders through `components.vehicle-card`. Pricing and enhanced-pricing inputs, service features, available/total counts, recommendations, search identity, public-price policy, booking eligibility, quotation-only behavior, unavailable state, Book Now and Add to Cart remain shared and unchanged. Theme 03 only supplies a namespaced visual treatment for those states.

The homepage's existing JavaScript continues to initialize the carousel and bind the cart/book actions through the same selectors; no Theme 03 business script was added for this section.

### Services/inspirations

The Theme 03 presenter at `partials/themes/theme-03/services.blade.php` replaces the released themes' repeated card grid with staggered, alternating editorial service rows. The section remains conditional on the existing `$inspirations` collection and retains the current title/description settings, `services` CMS type, six-item view limit, existing "View All Services" action, and CMS index/detail/category routes.

The shared `components.cms-card` remains the normalization owner for object/array records, managed or placeholder media, title, excerpt, location, category, special-offer discount, price/currency/duration, pickup location and minimum duration. A declared `theme-03-editorial-service` presentation template consumes those normalized values; the existing default card branch is unchanged for Theme 01, Theme 02 and every other CMS-section consumer.

- Service media remains lazy-loaded and gains explicit intrinsic dimensions.
- Mobile layouts stack media above copy while desktop rows alternate alignment.
- The section adds no content source, service type, filter, form, route or runtime behavior.

### Destinations

The Theme 03 presenter at `partials/themes/theme-03/destinations.blade.php` renders the existing destination collection as a numbered panoramic index. The section remains conditional on `$destinations`, retains the `destinations_section_title` and `destinations_section_description` settings, consumes up to six records from the controller-owned `taxi` CMS collection, and keeps the existing "View All Destinations" route.

The `theme-03-destination-index` template in shared `components.cms-card` consumes the same normalized image/fallback, title, excerpt, location, category, special-offer discount, rating, review count, pickup and minimum-duration fields plus the existing CMS detail/category links. Desktop uses wide cinematic bands with an overlaid content ledger; mobile separates the image and ink content areas so long copy and metadata remain readable.

- Rating remains a five-position visual scale with a textual accessible label and the existing review count.
- Managed images remain lazy-loaded and receive intrinsic dimensions.
- No destination source, rating logic, CMS route, filter, carousel or runtime behavior was added.

### Things to do/packages

The Theme 03 presenter at `partials/themes/theme-03/packages.blade.php` renders the existing package collection as a ruled itinerary ledger. Each entry uses a sequence marker, compact image, CMS copy/facts and a separate price/action column, making it distinct from both the alternating service rows and panoramic destination bands.

The section keeps the existing `$packages` condition, title/description settings, controller-owned `things-to-do` CMS collection, six-item ceiling and "View All Activities" route. Its `theme-03-itinerary` shared-card template retains managed/fallback media, detail/category links, title, excerpt, location, category, special-offer discount, price/currency, duration, five-position rating, review count, pickup and minimum-duration states.

- Price and duration remain conditional and disappear together when the existing public-price value is absent.
- Rating includes the existing review count and an accessible textual label.
- The timeline becomes a narrow two-column mobile ledger without hiding actions or metadata.
- No activity type, CMS field, booking action, route or script was introduced.

### Offer slider

The Theme 03 presenter at `partials/themes/theme-03/offer-slider.blade.php` retains the existing two-slide campaign contract inside a framed, offset canvas. It is enabled by the unchanged `offer_slider_img_1` condition and preserves both managed/fallback image expressions, both link expressions with their `booking.search` fallback, and the `.home4-offer-slider`, `.swiper-wrapper`, `.swiper-slide`, `.swiper-pagination2`, `.paginations` and `.two` hooks used by the shared Swiper initializer.

- Images use an uncropped `object-fit: contain` canvas with intrinsic dimensions and lazy loading so text baked into campaign artwork remains visible.
- The numbered/action rail and pagination sit outside the artwork area; no offer heading, description, CTA label or marketing claim was invented.
- Existing autoplay timing, transition speed, clickable pagination and interaction behavior remain in `public/assets/js/custom.js`.
- Audit note: `offer_slider_link_1` and `offer_slider_link_2` are referenced by the released homepage view but are not registered by the current Website Settings service/composer or portal. Theme 03 preserves those expressions and their effective booking-search fallback; the pre-existing ownership gap was not expanded during visual work.

### Why choose us/media/contact proof

The Theme 03 presenter at `partials/themes/theme-03/why-choose-us.blade.php` combines the existing heading, TripAdvisor evidence, media/video, four features and phone assistance into one split evidence composition. It remains jointly conditional on the existing `why_video_image` value, so disabling that image preserves the released section's complete hide behavior.

The left media field keeps the managed/fallback image, fixed YouTube URL and `data-fancybox="video-player"` hook initialized by shared `custom.js`. The right ledger keeps all four `why_feature_*` labels and `why_feature_icon_*` assets, while the header retains the fixed TripAdvisor URL/4.5 score, managed logo/stars and Reviews label. The contact block retains the help copy expression, shared header-help label, displayed company phone and the same sanitized `tel:` value.

- Theme 03 replaces the perpetual decorative video waves with a focused square play control; Fancybox behavior is unchanged.
- The managed media is lazy-loaded with intrinsic dimensions.
- Audit note: the released section displays `offer_section_description` even though `why_section_description` is registered, and `why_help_text` is referenced only by the view rather than the current setting owners. Theme 03 preserves both effective contracts instead of changing shared content ownership during visual work.
- No proof item, score, review, contact channel, video, setting, route or script was introduced.

### Testimonials

The Theme 03 presenter at `partials/themes/theme-03/testimonials.blade.php` turns the retained homepage testimonial contract into one dominant editorial quotation with a compact author-image navigator. It remains behind the exact existing non-empty `$testimonials` condition; Theme 01 and Theme 02 retain the complete released `home4-testimonial-section` branch.

The presenter preserves every testimonial field currently displayed by the homepage: five-position rating, position with its `Customer Review` fallback, content, name, and company with location fallback. It also preserves the existing `testimonials_section_title` and `testimonials_section_description` settings, five fixed `testimonial_author_img_*` settings, decorative `testimonial_vector`, paired `.home4-testimonial-slider`/`.home4-testimonial-img-slider` selectors, previous/next hooks, fade/autoplay timing and Swiper thumbs relationship from shared `custom.js`.

- Previous/next controls are semantic buttons with accessible labels; ratings have a textual label while retaining the existing five-icon logic.
- Author and vector media remain setting-owned, lazy-loaded and dimensioned. The author images remain the existing fixed five-image slider set rather than introducing a testimonial-image mapping or another content source.
- No route, testimonial record, setting, field, query, form, script or marketing claim was added.
- Runtime audit note: `HomeController` currently assigns `collect()` to `$testimonials` after a commented legacy query, so the section is dormant on the normal live homepage even when testimonial records exist. Theme 03 preserves that effective behavior; reactivating the query is a separate content/behavior decision and is not part of presentation work.

### Blog/editorial content

The Theme 03 presenter at `partials/themes/theme-03/blog-editorial.blade.php` turns the retained homepage blog contract into a magazine lead story with a two-story index. It remains behind the exact existing non-empty `$blogs` condition and consumes no more than the released section's three items. Theme 01 and Theme 02 retain the complete shared `x-cms-section` branch with its `blog-card2` template.

The `theme-03-editorial-story` branch in shared `components.cms-card` runs after the existing object/array normalization and retains the managed/fallback thumbnail, CMS detail and category links, location, category, title, excerpt and special-offer discount. The Theme 03 section keeps `blog_section_title`, `blog_section_description`, `cms_content_placeholder_image`, the `blogs` content type, `travel-blog-section` anchor and existing "View All Stories" action.

- Images remain lazy-loaded and gain intrinsic dimensions; missing media continues to use the shared CMS placeholder setting.
- Price, duration and rating remain disabled exactly as in the released homepage invocation.
- No record source, content type, route, metadata field, filter, form, script or claim was introduced.
- Runtime audit note: the current database has no active `blogs` content type, so `HomeController` resolves `$blogs` to an empty collection, the homepage section is absent and `/blogs` returns 404. An active `news` content type exists and `/news` returns 200, but Theme 03 does not substitute it because that would change the existing controller/CMS ownership contract. Resolving the content-type ownership mismatch is separate from presentation work.

### FAQ

The Theme 03 presenter at `partials/themes/theme-03/faq.blade.php` uses a numbered editorial accordion with a generous reading width and the existing optional vector as a quiet supporting field. It remains behind the exact non-empty `$faqs` condition and retains the current `faq_section_title`, fixed released description, `faq_section_vector` fallback, FAQ questions, raw managed answer HTML, Bootstrap collapse target/parent IDs, first-item-open state, ARIA relationships, and keyboard behavior. No FAQ record, setting, script, route, answer transformation, or behavior was added.

## Release state and verification

The repository default and production selector remain gated by `WEBSITE_THEME_03_ENABLED=false`. On 2026-07-21 the local workspace was explicitly switched to a development preview with the ignored `.env` gate enabled and the existing global `active_theme` setting changed from `theme-02` to `theme-03`. A live request to `http://thetaxi.test/` returned HTTP 200 with the `theme-theme-03` body class, Theme 03 stylesheet and journey-desk presenter. `THEME03-HOMEPAGE-1440x900-2026-07-21.png` records the available desktop homepage viewport. This does not constitute release approval: later coverage phases remain incomplete, the portal selector is unchanged, and the screenshot cannot prove the dormant testimonial presenter because the controller supplies an empty collection.

Focused contract tests verify content ownership, form/route/field hooks, CMS-card normalization, destination/package metadata, offer-slider contracts, proof/rating/video/contact hooks, testimonial fields/settings/paired-slider hooks, blog settings/routes/three-item contract, FAQ data/collapse contracts, dormant-query/content-type preservation, vehicle state delegation, carousel/cart hooks, presentation isolation, keyboard behavior and Blade compilation inside Laravel's view container. Fixture-based testimonial and blog visual/interaction review and the full baseline/QA matrix remain required before release.

### Hidden and empty section adjacency

The ten optional homepage sections remain in their existing controller/settings-owned order and retain their original independent visibility conditions. `Theme03HomepageAdjacencyContractTest` exhaustively evaluates all 1,024 visible/hidden combinations, proves every possible surviving neighbor pair, and guards against coupling a Theme 03 section to a sibling selector. Each optional presenter root owns its vertical padding, so removing any intervening empty section leaves the next rendered section with a complete spacing boundary rather than a collapsed or doubled legacy margin.

This verification does not make dormant CMS data visible, alter any owner condition, or activate Theme 03. Fixture-based visual review at the release matrix viewports remains required before the production gate can change.
