# White-Label Company Settings Map

This platform uses one source of truth for customer-facing company identity:

- `companies`: client/company master record, routing/domain identity, and operational company details.
- `website_settings`: configurable white-label settings. Records may be global (`company_id = null`) or company-specific (`company_id = companies.id`).

Company-specific settings are resolved in this order:

1. Request `X-Company-Id` header or `company_id` query parameter.
2. Request host matched to `companies.domain` or `companies.website`.
3. Global fallback settings where `website_settings.company_id` is null.

## Company Master Data

Update in Angular portal:

- `Admin > Companies > Create/Edit Company`

Stored in backend:

- `companies.name`
- `companies.brand_name`
- `companies.email`
- `companies.phone`
- `companies.website`
- `companies.domain`
- `companies.logo`
- `companies.description`
- `companies.address`
- `companies.region_id`
- `companies.country_id`
- `companies.state_id`
- `companies.city`
- `companies.latitude`
- `companies.longitude`
- `companies.is_active`
- `companies.is_default`

Use this for:

- Domain-to-company resolution.
- Company identity defaults.
- Fleet/vehicle ownership and default garage/company location.

## Branding And Website Settings

Update in Angular portal:

- `Admin > System > Website Settings > General`
- `Admin > System > Website Settings > Branding`
- `Admin > System > Website Settings > Appearance`
- `Admin > System > Website Settings > Header`
- `Admin > System > Website Settings > Footer`
- `Admin > System > Website Settings > SEO`
- `Admin > System > Website Settings > Email`
- `Admin > System > Website Settings > Social Media`
- `Admin > System > Website Settings > Contact`

Canonical setting keys:

- `brand_name`
- `brand_short_name`
- `brand_tagline`
- `company_name`
- `company_phone`
- `company_whatsapp`
- `company_email`
- `company_address`
- `company_website`
- `brand_logo_primary`
- `brand_logo_secondary`
- `brand_logo_icon`
- `brand_favicon`
- `portal_title`
- `portal_logo`
- `portal_theme`
- `logo_header`
- `logo_footer`
- `logo_mobile`
- `favicon`
- `footer_company_name`
- `footer_company_tagline`
- `footer_copyright_text`
- `mail_from_name`
- `mail_from_address`
- `email_footer_text`
- `social_facebook`
- `social_twitter`
- `social_instagram`
- `social_linkedin`
- `social_youtube`
- `social_tiktok`

Avoid creating alternate names for the same value. If a legacy key still exists, map it to the canonical key in `WebsiteSettingsService::withCanonicalBranding()`.

## Consumption Points

Public website:

- `resources/views/layouts/app.blade.php`
- `resources/views/partials/header.blade.php`
- `resources/views/partials/footer.blade.php`
- `resources/views/partials/themes/theme-02/header.blade.php`
- `resources/views/partials/themes/theme-02/footer.blade.php`
- Page views such as `home.blade.php`, `about.blade.php`, CMS, checkout, contact, corporate transfers.

Email templates:

- `resources/views/emails/layouts/master.blade.php`
- `resources/views/emails/checkout-confirmation.blade.php`
- `resources/views/emails/quotation-request.blade.php`
- `resources/views/emails/inquiry-confirmation.blade.php`
- `resources/views/emails/general.blade.php`
- `resources/views/emails/sitemap_ping_failed.blade.php`

Portal branding:

- `portal-thetaxi/src/app/core/branding/branding.service.ts`
- `portal-thetaxi/src/app/layout/layouts/classy/classy.component.html`
- `portal-thetaxi/src/app/modules/auth/sign-in/sign-in.component.html`
- `portal-thetaxi/src/app/modules/admin/system/settings/website-settings-enhanced.component.*`
- `portal-thetaxi/src/app/modules/admin/system/branding-settings/branding-settings.component.html`

Backend/API:

- `app/Services/WebsiteSettingsService.php`
- `app/Http/ViewComposers/SettingsViewComposer.php`
- `app/Http/Controllers/Api/Website/WebsiteSettingController.php`
- `app/Models/Website/WebsiteSetting.php`
- `app/Http/Resources/Website/WebsiteSettingResource.php`

Company management:

- `app/Models/Company.php`
- `app/Http/Controllers/Api/CompanyController.php`
- `app/Http/Requests/Company/CreateCompanyRequest.php`
- `app/Http/Requests/Company/UpdateCompanyRequest.php`
- `app/Http/Resources/Company/CompanyResource.php`
- `portal-thetaxi/src/app/modules/admin/companies/form/company-form.component.*`
- `portal-thetaxi/src/app/core/types/company.types.ts`

Invoices, booking PDFs, and notifications:

- Must resolve branding through `WebsiteSettingsService`, never directly from `.env` or hardcoded values.
- For booking-specific documents, pass the booking/company context into the request or set `X-Company-Id` before rendering.
- If a booking belongs to a vehicle/company, use that company id when resolving document branding.

## Per-Company Rollout Checklist

For every client company:

1. Create/update the `companies` record with `name`, `brand_name`, `domain`, `website`, `logo`, address, email, and phone.
2. Add company-scoped `website_settings` rows with `company_id` for branding, contact, footer, email, SEO, social, and portal values.
3. Verify `api/public/branding` from that company domain returns only that company’s values.
4. Verify public website header/footer/logo/contact data on that domain.
5. Verify portal title/logo/footer after login.
6. Send test booking, quotation, inquiry, and payment emails and confirm branding.
7. Generate invoice/booking PDF outputs and confirm company-specific name/logo/address/contact.
8. Send SMS/push/email notification tests and confirm no unrelated company branding appears.
9. Run a repository search for old client names/emails/domains before release.

## Known Boundaries

The codebase still contains legacy structural names in old database table names, theme ids, or archived scripts. Do not rename those unless a dedicated schema/theme migration is planned. Customer-facing content and configurable defaults must use company-scoped settings.
