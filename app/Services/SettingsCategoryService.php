<?php

namespace App\Services;

use App\Services\WebsiteSettingsService;

class SettingsCategoryService
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Get all setting categories with their configurations
     */
    public function getAllSettingCategories(): array
    {
        return [
            'general' => $this->getGeneralSettingsConfig(),
            'seo' => $this->getSeoSettingsConfig(),
            'social_media' => $this->getSocialMediaSettingsConfig(),
            'contact' => $this->getContactSettingsConfig(),
            'payment' => $this->getPaymentSettingsConfig(),
            'security' => $this->getSecuritySettingsConfig(),
            'email' => $this->getEmailSettingsConfig(),
            'homepage' => $this->getHomepageSettingsConfig(),
            'booking' => $this->getBookingSettingsConfig(),
            'branding' => $this->getBrandingSettingsConfig(),
            'appearance' => $this->getAppearanceSettingsConfig(),
        ];
    }

    /**
     * Get general site settings configuration
     */
    public function getGeneralSettingsConfig(): array
    {
        return [
            'title' => 'General Settings',
            'description' => 'Basic website information and configuration',
            'icon' => 'settings',
            'settings' => [
                'site_name' => [
                    'label' => 'Site Name',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => 'Company - Transport Service',
                    'description' => 'The main name of your website'
                ],
                'site_tagline' => [
                    'label' => 'Site Tagline',
                    'type' => 'text',
                    'placeholder' => 'Your Reliable Transportation Partner',
                    'description' => 'A short description of your service'
                ],
                'company_name' => [
                    'label' => 'Company Name',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => 'Company',
                    'description' => 'Official company name'
                ],
                'company_phone' => [
                    'label' => 'Company Phone',
                    'type' => 'tel',
                    'required' => true,
                    'placeholder' => 'Primary company phone',
                    'description' => 'Main contact phone number'
                ],
                'company_whatsapp' => [
                    'label' => 'Company WhatsApp',
                    'type' => 'tel',
                    'placeholder' => 'WhatsApp number if different',
                    'description' => 'WhatsApp/mobile number. Leave blank to use the main company phone.'
                ],
                'company_email' => [
                    'label' => 'Company Email',
                    'type' => 'email',
                    'required' => true,
                    'placeholder' => 'info@example.com',
                    'description' => 'Main contact email address'
                ],
                'company_address' => [
                    'label' => 'Company Address',
                    'type' => 'textarea',
                    'placeholder' => '123 Main Street, Colombo 01, Sri Lanka',
                    'description' => 'Full company address'
                ],
                'company_website' => [
                    'label' => 'Company Website',
                    'type' => 'url',
                    'placeholder' => 'https://www.example.com',
                    'description' => 'Company website URL'
                ],
                'site_timezone' => [
                    'label' => 'Site Timezone',
                    'type' => 'select',
                    'options' => [
                        'Asia/Colombo' => 'Sri Lanka Time (Asia/Colombo)',
                        'UTC' => 'UTC',
                        'Asia/Dubai' => 'UAE Time (Asia/Dubai)',
                        'Europe/London' => 'London Time (Europe/London)',
                        'America/New_York' => 'New York Time (America/New_York)',
                    ],
                    'description' => 'Default timezone for the website'
                ],
                'default_currency' => [
                    'label' => 'Default Currency',
                    'type' => 'select',
                    'options' => [
                        'LKR' => 'Sri Lankan Rupee (LKR)',
                        'USD' => 'US Dollar (USD)',
                        'EUR' => 'Euro (EUR)',
                        'GBP' => 'British Pound (GBP)',
                        'AED' => 'UAE Dirham (AED)',
                    ],
                    'description' => 'Default currency for pricing'
                ]
            ]
        ];
    }

    /**
     * Get SEO settings configuration
     */
    public function getSeoSettingsConfig(): array
    {
        return [
            'title' => 'SEO Settings',
            'description' => 'Search engine optimization configuration',
            'icon' => 'search',
            'settings' => [
                'seo_title_template' => [
                    'label' => 'Title Template',
                    'type' => 'text',
                    'placeholder' => '{page_title} | Company - Transport Service',
                    'description' => 'Template for page titles (use {page_title} placeholder)'
                ],
                'seo_meta_description' => [
                    'label' => 'Default Meta Description',
                    'type' => 'textarea',
                    'placeholder' => 'Book reliable taxi and car rental services in Sri Lanka...',
                    'description' => 'Default meta description for pages without specific descriptions'
                ],
                'seo_keywords' => [
                    'label' => 'Site Keywords',
                    'type' => 'textarea',
                    'placeholder' => 'taxi service, car rental, sri lanka, colombo, airport transfer',
                    'description' => 'Comma-separated keywords for your website'
                ],
                'seo_og_image' => [
                    'label' => 'Default OG Image',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Default image for social media sharing'
                ],
                'seo_twitter_card' => [
                    'label' => 'Twitter Card Type',
                    'type' => 'select',
                    'options' => [
                        'summary' => 'Summary',
                        'summary_large_image' => 'Summary Large Image',
                    ],
                    'description' => 'Twitter card type for social sharing'
                ],
                'google_analytics_id' => [
                    'label' => 'Google Analytics ID',
                    'type' => 'text',
                    'placeholder' => 'G-XXXXXXXXXX',
                    'description' => 'Google Analytics measurement ID'
                ],
                'google_tag_manager_id' => [
                    'label' => 'Google Tag Manager ID',
                    'type' => 'text',
                    'placeholder' => 'GTM-XXXXXXX',
                    'description' => 'Google Tag Manager container ID'
                ],
                'facebook_pixel_id' => [
                    'label' => 'Facebook Pixel ID',
                    'type' => 'text',
                    'placeholder' => '123456789012345',
                    'description' => 'Facebook Pixel ID for tracking'
                ]
            ]
        ];
    }

    /**
     * Get social media settings configuration
     */
    public function getSocialMediaSettingsConfig(): array
    {
        return [
            'title' => 'Social Media',
            'description' => 'Social media links and integration',
            'icon' => 'share',
            'settings' => [
                'social_facebook' => [
                    'label' => 'Facebook URL',
                    'type' => 'url',
                    'placeholder' => 'https://facebook.com/company',
                    'description' => 'Facebook page URL'
                ],
                'social_twitter' => [
                    'label' => 'Twitter URL',
                    'type' => 'url',
                    'placeholder' => 'https://twitter.com/company',
                    'description' => 'Twitter profile URL'
                ],
                'social_instagram' => [
                    'label' => 'Instagram URL',
                    'type' => 'url',
                    'placeholder' => 'https://instagram.com/company',
                    'description' => 'Instagram profile URL'
                ],
                'social_linkedin' => [
                    'label' => 'LinkedIn URL',
                    'type' => 'url',
                    'placeholder' => 'https://linkedin.com/company/company',
                    'description' => 'LinkedIn company page URL'
                ],
                'social_youtube' => [
                    'label' => 'YouTube URL',
                    'type' => 'url',
                    'placeholder' => 'https://youtube.com/@company',
                    'description' => 'YouTube channel URL'
                ],
                'social_tiktok' => [
                    'label' => 'TikTok URL',
                    'type' => 'url',
                    'placeholder' => 'https://tiktok.com/@company',
                    'description' => 'TikTok profile URL'
                ]
            ]
        ];
    }

    /**
     * Get contact settings configuration
     */
    public function getContactSettingsConfig(): array
    {
        return [
            'title' => 'Contact Information',
            'description' => 'Contact details and office information',
            'icon' => 'contact_mail',
            'settings' => [
                'contact_page_title' => [
                    'label' => 'Contact Page Title',
                    'type' => 'text',
                    'placeholder' => 'Contact Us',
                    'description' => 'Title for the contact page'
                ],
                'contact_hero_heading' => [
                    'label' => 'Contact Hero Heading',
                    'type' => 'text',
                    'placeholder' => 'Get in Touch',
                    'description' => 'Main heading on contact page'
                ],
                'contact_address_1_title' => [
                    'label' => 'Office 1 Title',
                    'type' => 'text',
                    'placeholder' => 'Head Office',
                    'description' => 'Title for first office location'
                ],
                'contact_address_1_address' => [
                    'label' => 'Office 1 Address',
                    'type' => 'textarea',
                    'placeholder' => '123 Main Street, Colombo 01, Sri Lanka',
                    'description' => 'Full address for first office'
                ],
                'contact_address_1_phone' => [
                    'label' => 'Office 1 Phone',
                    'type' => 'tel',
                    'placeholder' => 'Use company phone',
                    'description' => 'Phone number for first office. Leave blank to use the main company phone.'
                ],
                'contact_address_1_email' => [
                    'label' => 'Office 1 Email',
                    'type' => 'email',
                    'placeholder' => 'colombo@example.com',
                    'description' => 'Email for first office'
                ],
                'contact_address_2_title' => [
                    'label' => 'Office 2 Title',
                    'type' => 'text',
                    'placeholder' => 'Branch Office',
                    'description' => 'Title for second office location'
                ],
                'contact_address_2_address' => [
                    'label' => 'Office 2 Address',
                    'type' => 'textarea',
                    'placeholder' => '456 Branch Street, Kandy, Sri Lanka',
                    'description' => 'Full address for second office'
                ],
                'contact_address_2_phone' => [
                    'label' => 'Office 2 Phone',
                    'type' => 'tel',
                    'placeholder' => 'Branch phone',
                    'description' => 'Phone number for second office'
                ],
                'contact_address_2_email' => [
                    'label' => 'Office 2 Email',
                    'type' => 'email',
                    'placeholder' => 'kandy@example.com',
                    'description' => 'Email for second office'
                ],
                'contact_map_latitude' => [
                    'label' => 'Map Latitude',
                    'type' => 'number',
                    'step' => 'any',
                    'placeholder' => '6.9271',
                    'description' => 'Latitude for map center'
                ],
                'contact_map_longitude' => [
                    'label' => 'Map Longitude',
                    'type' => 'number',
                    'step' => 'any',
                    'placeholder' => '79.8612',
                    'description' => 'Longitude for map center'
                ],
                'emergency_contact' => [
                    'label' => '24/7 Emergency Contact',
                    'type' => 'tel',
                    'placeholder' => 'Emergency contact number',
                    'description' => '24-hour emergency contact number'
                ]
            ]
        ];
    }

    /**
     * Get payment settings configuration
     */
    public function getPaymentSettingsConfig(): array
    {
        return [
            'title' => 'Payment Settings',
            'description' => 'Payment methods and gateway configuration',
            'icon' => 'payment',
            'settings' => [
                'payment_online_enabled' => [
                    'label' => 'Online Payments Enabled',
                    'type' => 'toggle',
                    'description' => 'Allow online payments during checkout'
                ],
                'payment_offline_enabled' => [
                    'label' => 'Offline Payments Enabled',
                    'type' => 'toggle',
                    'description' => 'Allow pay-on-check-in payments'
                ],
                'payment_methods_enabled' => [
                    'label' => 'Enabled Payment Methods',
                    'type' => 'checkbox_group',
                    'options' => [
                        'cash' => 'Cash Payment',
                        'bank_transfer' => 'Bank Transfer',
                        'online_banking' => 'Online Banking',
                        'credit_card' => 'Credit Card',
                        'webxpay' => 'WebXPay Gateway'
                    ],
                    'description' => 'Select available payment methods'
                ],
                'webxpay_enabled' => [
                    'label' => 'WebXPay Gateway Enabled',
                    'type' => 'toggle',
                    'description' => 'Enable WebXPay payment gateway'
                ],
                'webxpay_merchant_secret' => [
                    'label' => 'WebXPay Merchant Secret',
                    'type' => 'password',
                    'description' => 'Merchant secret for WebXPay verification'
                ],
                'webxpay_public_key' => [
                    'label' => 'WebXPay Public Key',
                    'type' => 'textarea',
                    'description' => 'RSA public key used for WebXPay encryption'
                ],
                'webxpay_api_url' => [
                    'label' => 'WebXPay API URL',
                    'type' => 'url',
                    'placeholder' => 'https://tokenize.webxpay.com/v1/api',
                    'description' => 'WebXPay token API base URL'
                ],
                'webxpay_api_username' => [
                    'label' => 'WebXPay API Username',
                    'type' => 'text',
                    'description' => 'API authentication username'
                ],
                'webxpay_api_password' => [
                    'label' => 'WebXPay API Password',
                    'type' => 'password',
                    'description' => 'API authentication password'
                ],
                'webxpay_checkout_url' => [
                    'label' => 'WebXPay Checkout URL',
                    'type' => 'url',
                    'placeholder' => 'https://webxpay.com/index.php?route=checkout/billing',
                    'description' => 'WebXPay redirect URL for checkout'
                ],
                'webxpay_return_url' => [
                    'label' => 'WebXPay Return URL',
                    'type' => 'url',
                    'description' => 'Callback URL after payment completion'
                ],
                'webxpay_cancel_url' => [
                    'label' => 'WebXPay Cancel URL',
                    'type' => 'url',
                    'description' => 'Callback URL when the user cancels payment'
                ],
                'webxpay_notify_url' => [
                    'label' => 'WebXPay Notify URL',
                    'type' => 'url',
                    'description' => 'Webhook URL for asynchronous notifications'
                ],
                'webxpay_currency' => [
                    'label' => 'WebXPay Currency',
                    'type' => 'text',
                    'placeholder' => 'LKR',
                    'description' => 'Currency used for WebXPay transactions'
                ],
                'advance_payment_enabled' => [
                    'label' => 'Advance Payment Required',
                    'type' => 'toggle',
                    'description' => 'Require advance payment for bookings'
                ],
                'advance_payment_percentage' => [
                    'label' => 'Advance Payment Percentage',
                    'type' => 'number',
                    'min' => 0,
                    'max' => 100,
                    'placeholder' => '50',
                    'description' => 'Percentage of total amount required as advance'
                ],
                'advance_payment_min_amount' => [
                    'label' => 'Advance Payment Minimum Amount',
                    'type' => 'number',
                    'min' => 0,
                    'placeholder' => '1000',
                    'description' => 'Minimum advance payment amount'
                ],
                'service_fee_enabled' => [
                    'label' => 'Service Fee Enabled',
                    'type' => 'toggle',
                    'description' => 'Add service fee to bookings'
                ],
                'service_fee_type' => [
                    'label' => 'Service Fee Type',
                    'type' => 'select',
                    'options' => [
                        'fixed' => 'Fixed',
                        'percentage' => 'Percentage',
                    ],
                    'description' => 'How the service fee is calculated'
                ],
                'service_fee_amount' => [
                    'label' => 'Service Fee Amount',
                    'type' => 'number',
                    'step' => '0.01',
                    'placeholder' => '750.00',
                    'description' => 'Fixed service fee amount'
                ],
                'service_fee_min_amount' => [
                    'label' => 'Service Fee Minimum',
                    'type' => 'number',
                    'step' => '0.01',
                    'placeholder' => '0.00',
                    'description' => 'Minimum service fee amount'
                ],
                'service_fee_max_amount' => [
                    'label' => 'Service Fee Maximum',
                    'type' => 'number',
                    'step' => '0.01',
                    'placeholder' => '0.00',
                    'description' => 'Maximum service fee amount (leave blank for no limit)'
                ],
                'tax_enabled' => [
                    'label' => 'Tax Enabled (NBT)',
                    'type' => 'toggle',
                    'description' => 'Apply Nation Building Tax'
                ],
                'tax_rate' => [
                    'label' => 'Tax Rate (%)',
                    'type' => 'number',
                    'step' => '0.001',
                    'placeholder' => '2.5',
                    'description' => 'Tax rate as percentage'
                ],
                'tax_label' => [
                    'label' => 'Tax Label',
                    'type' => 'text',
                    'placeholder' => 'Government TAX',
                    'description' => 'Label shown for tax on invoices'
                ],
                'tax_description' => [
                    'label' => 'Tax Description',
                    'type' => 'textarea',
                    'placeholder' => 'Government TAX',
                    'description' => 'Description for tax'
                ],
                'vat_enabled' => [
                    'label' => 'VAT Enabled',
                    'type' => 'toggle',
                    'description' => 'Apply Value Added Tax'
                ],
                'vat_rate' => [
                    'label' => 'VAT Rate (%)',
                    'type' => 'number',
                    'step' => '0.1',
                    'placeholder' => '18.0',
                    'description' => 'VAT rate as percentage'
                ],
                'vat_label' => [
                    'label' => 'VAT Label',
                    'type' => 'text',
                    'placeholder' => 'VAT',
                    'description' => 'Label shown for VAT on invoices'
                ],
                'vat_description' => [
                    'label' => 'VAT Description',
                    'type' => 'textarea',
                    'placeholder' => 'Value Added Tax',
                    'description' => 'Description for VAT'
                ],
                'vat_applies_to_service_fee' => [
                    'label' => 'VAT Applies to Service Fee',
                    'type' => 'toggle',
                    'description' => 'Include service fee in VAT calculation'
                ]
            ]
        ];
    }

    /**
     * Get security settings configuration
     */
    public function getSecuritySettingsConfig(): array
    {
        return [
            'title' => 'Security Settings',
            'description' => 'Website security and privacy configuration',
            'icon' => 'security',
            'settings' => [
                'ssl_force' => [
                    'label' => 'Force HTTPS',
                    'type' => 'toggle',
                    'description' => 'Redirect all HTTP traffic to HTTPS'
                ],
                'security_headers_enabled' => [
                    'label' => 'Security Headers',
                    'type' => 'toggle',
                    'description' => 'Enable security headers (HSTS, CSP, etc.)'
                ],
                'content_security_policy' => [
                    'label' => 'Content Security Policy',
                    'type' => 'textarea',
                    'placeholder' => "default-src 'self'; script-src 'self' 'unsafe-inline';",
                    'description' => 'CSP header content'
                ],
                'rate_limiting_enabled' => [
                    'label' => 'API Rate Limiting',
                    'type' => 'toggle',
                    'description' => 'Enable rate limiting for API endpoints'
                ],
                'rate_limit_per_minute' => [
                    'label' => 'Rate Limit (per minute)',
                    'type' => 'number',
                    'placeholder' => '60',
                    'description' => 'Maximum requests per minute per IP'
                ],
                'maintenance_mode' => [
                    'label' => 'Maintenance Mode',
                    'type' => 'toggle',
                    'description' => 'Enable maintenance mode'
                ],
                'maintenance_message' => [
                    'label' => 'Maintenance Message',
                    'type' => 'textarea',
                    'placeholder' => 'We are currently performing scheduled maintenance...',
                    'description' => 'Message displayed during maintenance'
                ]
            ]
        ];
    }

    /**
     * Get email settings configuration
     */
    public function getEmailSettingsConfig(): array
    {
        return [
            'title' => 'Email Settings',
            'description' => 'Email configuration and templates',
            'icon' => 'email',
            'settings' => [
                'mail_from_name' => [
                    'label' => 'From Name',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => 'Company Support',
                    'description' => 'Name used in outgoing emails'
                ],
                'mail_from_address' => [
                    'label' => 'From Email',
                    'type' => 'email',
                    'required' => true,
                    'placeholder' => 'noreply@example.com',
                    'description' => 'Email address used for outgoing emails'
                ],
                'booking_confirmation_enabled' => [
                    'label' => 'Booking Confirmation Emails',
                    'type' => 'toggle',
                    'description' => 'Send confirmation emails for bookings'
                ],
                'booking_reminder_enabled' => [
                    'label' => 'Booking Reminder Emails',
                    'type' => 'toggle',
                    'description' => 'Send reminder emails before booking date'
                ],
                'contact_form_notification' => [
                    'label' => 'Contact Form Notifications',
                    'type' => 'email',
                    'placeholder' => 'admin@example.com',
                    'description' => 'Email to receive contact form submissions'
                ],
                'email_footer_text' => [
                    'label' => 'Email Footer Text',
                    'type' => 'textarea',
                    'placeholder' => 'Thank you for choosing us. Safe travels!',
                    'description' => 'Text to include in email footers'
                ],
                'email_header_subtitle' => [
                    'label' => 'Email Header Subtitle',
                    'type' => 'text',
                    'placeholder' => 'Premium Taxi Service',
                    'description' => 'Tagline shown under the company name in the email header'
                ]
            ]
        ];
    }

    /**
     * Get homepage settings configuration
     */
    public function getHomepageSettingsConfig(): array
    {
        return [
            'title' => 'Homepage Content',
            'description' => 'Homepage sections and content management',
            'icon' => 'home',
            'settings' => [
                'banner_heading' => [
                    'label' => 'Banner Heading',
                    'type' => 'text',
                    'placeholder' => 'All-in-one Travel Booking',
                    'description' => 'Fallback heading used when a hero slide has no heading'
                ],
                'banner_subheading' => [
                    'label' => 'Banner Subheading',
                    'type' => 'text',
                    'placeholder' => 'Best travel agency in world-wide & achieve',
                    'description' => 'Fallback description used when a hero slide has no description'
                ],
                'about_section_title' => [
                    'label' => 'About Section Title',
                    'type' => 'text',
                    'placeholder' => 'Why Choose Us',
                    'description' => 'Title for about section on homepage'
                ],
                'about_section_description' => [
                    'label' => 'About Section Description',
                    'type' => 'textarea',
                    'placeholder' => 'We provide reliable and comfortable transportation...',
                    'description' => 'Description for about section'
                ],
                'features_section_title' => [
                    'label' => 'Features Section Title',
                    'type' => 'text',
                    'placeholder' => 'Our Services',
                    'description' => 'Title for features section'
                ],
                'testimonials_section_title' => [
                    'label' => 'Testimonials Section Title',
                    'type' => 'text',
                    'placeholder' => 'What Our Clients Say',
                    'description' => 'Title for testimonials section'
                ]
            ]
        ];
    }

    /**
     * Get booking settings configuration
     */
    public function getBookingSettingsConfig(): array
    {
        return [
            'title' => 'Booking Settings',
            'description' => 'Booking system configuration and rules',
            'icon' => 'event_seat',
            'settings' => [
                'booking_base_currency' => [
                    'label' => 'Booking Base Currency',
                    'type' => 'select',
                    'options' => [
                        'LKR' => 'Sri Lankan Rupee (LKR)',
                        'USD' => 'US Dollar (USD)',
                        'EUR' => 'Euro (EUR)',
                        'GBP' => 'British Pound (GBP)',
                        'AED' => 'UAE Dirham (AED)',
                    ],
                    'description' => 'Base currency for booking calculations'
                ],
                'booking_advance_hours' => [
                    'label' => 'Minimum Advance Booking (hours)',
                    'type' => 'number',
                    'placeholder' => '2',
                    'description' => 'Minimum hours in advance for booking'
                ],
                'booking_notice_html' => [
                    'label' => 'Public Booking Notice',
                    'type' => 'textarea',
                    'placeholder' => 'Booking Notice: Bookings must be made at least <strong>4 hours</strong> in advance.',
                    'description' => 'Shown below the public booking form. Supports basic formatting and tel: links.'
                ],
                // Service visibility toggles
                'enable_airport_transfers' => [
                    'label' => 'Enable Airport Transfers',
                    'type' => 'toggle',
                    'description' => 'Show Airport Transfers search form on public booking widget'
                ],
                'enable_ride_now' => [
                    'label' => 'Enable Drop & Pickup (Ride Now)',
                    'type' => 'toggle',
                    'description' => 'Show Drop & Pickup search form on public booking widget'
                ],
                'enable_day_rental' => [
                    'label' => 'Enable Day Rental',
                    'type' => 'toggle',
                    'description' => 'Show Day Rental search form on public booking widget'
                ],
                'enable_selfdrive' => [
                    'label' => 'Enable Self Drive',
                    'type' => 'toggle',
                    'description' => 'Enable Self Drive service tab/form'
                ],
                'enable_with_drive' => [
                    'label' => 'Enable With Driver',
                    'type' => 'toggle',
                    'description' => 'Enable With Driver service tab/form'
                ],
                'enable_wedding' => [
                    'label' => 'Enable Wedding',
                    'type' => 'toggle',
                    'description' => 'Enable Wedding service tab/form'
                ],
                'enable_corporate' => [
                    'label' => 'Enable Corporate Transport',
                    'type' => 'toggle',
                    'description' => 'Enable Corporate Transport service tab/form'
                ],
                'show_return_trip_toggle' => [
                    'label' => 'Show Return Trip Toggle',
                    'type' => 'toggle',
                    'description' => 'Show/Hide "Add Return Trip" option in rentals'
                ],
                'include_garage_distance_in_pricing' => [
                    'label' => 'Include Garage Distance in Pricing',
                    'type' => 'toggle',
                    'description' => 'When enabled, pricing includes distance from garage to pickup and dropoff to garage (garage-to-garage). When disabled, only the journey distance is used for pricing calculations.'
                ],
                'assignment_enable_qc_stage' => [
                    'label' => 'Enable QC Stage in Assignment Flow',
                    'type' => 'toggle',
                    'description' => 'When enabled, Assignment Management includes QC/Inspection after return. Requires Vehicle Return Management.'
                ],
                'assignment_enable_maintenance_stage' => [
                    'label' => 'Enable Maintenance Stage in Assignment Flow',
                    'type' => 'toggle',
                    'description' => 'When enabled, Assignment Management shows the Maintenance stage/tab.'
                ],
                'booking_max_days' => [
                    'label' => 'Maximum Advance Booking (days)',
                    'type' => 'number',
                    'placeholder' => '365',
                    'description' => 'Maximum days in advance for booking'
                ],
                'cancellation_allowed' => [
                    'label' => 'Cancellation Allowed',
                    'type' => 'toggle',
                    'description' => 'Allow customers to cancel bookings'
                ],
                'cancellation_hours' => [
                    'label' => 'Cancellation Window (hours)',
                    'type' => 'number',
                    'placeholder' => '24',
                    'description' => 'Hours before trip when cancellation is allowed'
                ],
                'auto_dispatch_enabled' => [
                    'label' => 'Auto Dispatch',
                    'type' => 'toggle',
                    'description' => 'Automatically assign vehicles to bookings'
                ],
                'guest_booking_enabled' => [
                    'label' => 'Guest Booking',
                    'type' => 'toggle',
                    'description' => 'Allow bookings without account registration'
                ],
                'feature_corporate_management_enabled' => [
                    'label' => 'Corporate Management',
                    'type' => 'toggle',
                    'description' => 'Enable corporate accounts, corporate portal, employees, departments, approvals, and staff transport features. Disabled by default.'
                ],
                'feature_vehicle_return_management_enabled' => [
                    'label' => 'Vehicle Return Management',
                    'type' => 'toggle',
                    'description' => 'Enable rental return processing and post-return workflows. Leave disabled for taxi or trip-based businesses that complete directly after service. Disabled by default.'
                ]
            ]
        ];
    }

    /**
     * Get appearance settings configuration
     */
    public function getAppearanceSettingsConfig(): array
    {
        return [
            'title' => 'Appearance',
            'description' => 'Website theme and visual settings',
            'icon' => 'palette',
            'settings' => [
                'primary_color' => [
                    'label' => 'Primary Color',
                    'type' => 'color',
                    'placeholder' => '#BF2629',
                    'description' => 'Main theme color for the website'
                ],
                'secondary_color' => [
                    'label' => 'Secondary Color',
                    'type' => 'color',
                    'placeholder' => '#717171',
                    'description' => 'Secondary theme color'
                ],
                'logo_header' => [
                    'label' => 'Header Logo',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Logo displayed in website header'
                ],
                'logo_footer' => [
                    'label' => 'Footer Logo',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Logo displayed in website footer'
                ],
                'favicon' => [
                    'label' => 'Favicon',
                    'type' => 'file',
                    'accept' => '.ico,image/*',
                    'description' => 'Website favicon (16x16 or 32x32 pixels)'
                ],
                'cms_content_placeholder_image' => [
                    'label' => 'CMS Placeholder Image',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Fallback image shown for CMS content without a thumbnail or featured image'
                ]
            ]
        ];
    }

    /**
     * Get white-label branding settings configuration
     */
    public function getBrandingSettingsConfig(): array
    {
        return [
            'title' => 'Branding',
            'description' => 'Portal logos, brand identity, and theme colors',
            'icon' => 'brush',
            'settings' => [
                'brand_name' => [
                    'label' => 'Brand Name',
                    'type' => 'text',
                    'placeholder' => 'The Taxi',
                    'description' => 'Customer-facing brand name'
                ],
                'brand_tagline' => [
                    'label' => 'Brand Tagline',
                    'type' => 'text',
                    'placeholder' => 'Your Trusted Transport Partner',
                    'description' => 'Short brand message shown in portal and auth screens'
                ],
                'brand_short_name' => [
                    'label' => 'Brand Short Name',
                    'type' => 'text',
                    'placeholder' => 'The Taxi',
                    'description' => 'Short display name for compact spaces'
                ],
                'portal_title' => [
                    'label' => 'Portal Title',
                    'type' => 'text',
                    'placeholder' => 'The Taxi | Portal',
                    'description' => 'Browser title for the portal'
                ],
                'brand_logo_primary' => [
                    'label' => 'Primary Logo',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Main brand logo'
                ],
                'brand_logo_secondary' => [
                    'label' => 'Secondary Logo',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Logo variant for dark or alternate backgrounds'
                ],
                'brand_logo_icon' => [
                    'label' => 'Logo Icon',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Compact logo or icon'
                ],
                'brand_favicon' => [
                    'label' => 'Favicon',
                    'type' => 'file',
                    'accept' => '.ico,image/*',
                    'description' => 'Browser favicon'
                ],
                'portal_logo' => [
                    'label' => 'Portal Logo',
                    'type' => 'file',
                    'accept' => 'image/*',
                    'description' => 'Logo used in portal loading, auth, and dashboard screens'
                ],
                'brand_color_primary' => [
                    'label' => 'Primary Color',
                    'type' => 'color',
                    'placeholder' => '#BF2629',
                    'description' => 'Main portal action color'
                ],
                'brand_color_primary_light' => [
                    'label' => 'Primary Light Color',
                    'type' => 'color',
                    'placeholder' => '#F4E2E2',
                    'description' => 'Light primary shade'
                ],
                'brand_color_primary_dark' => [
                    'label' => 'Primary Dark Color',
                    'type' => 'color',
                    'placeholder' => '#891318',
                    'description' => 'Dark primary shade'
                ],
                'brand_color_secondary' => [
                    'label' => 'Secondary Color',
                    'type' => 'color',
                    'placeholder' => '#717171',
                    'description' => 'Secondary/accent portal color'
                ],
                'brand_color_secondary_light' => [
                    'label' => 'Secondary Light Color',
                    'type' => 'color',
                    'placeholder' => '#E5E5E5',
                    'description' => 'Light secondary shade'
                ],
                'brand_color_secondary_dark' => [
                    'label' => 'Secondary Dark Color',
                    'type' => 'color',
                    'placeholder' => '#404040',
                    'description' => 'Dark secondary shade'
                ],
                'brand_color_accent' => [
                    'label' => 'Accent Color',
                    'type' => 'color',
                    'placeholder' => '#FFFFFF',
                    'description' => 'Additional brand accent color'
                ],
                'brand_color_accent_light' => [
                    'label' => 'Accent Light Color',
                    'type' => 'color',
                    'placeholder' => '#FFFFFF',
                    'description' => 'Light accent shade'
                ],
                'brand_color_accent_dark' => [
                    'label' => 'Accent Dark Color',
                    'type' => 'color',
                    'placeholder' => '#F5F5F5',
                    'description' => 'Dark accent shade'
                ],
                'portal_theme' => [
                    'label' => 'Portal Theme',
                    'type' => 'select',
                    'options' => [
                        'theme-brand' => 'Brand',
                        'theme-default' => 'Default',
                        'theme-casons' => 'Casons',
                        'theme-teal' => 'Teal',
                        'theme-rose' => 'Rose',
                        'theme-purple' => 'Purple',
                        'theme-amber' => 'Amber',
                    ],
                    'description' => 'Base portal theme class'
                ],
                'portal_scheme' => [
                    'label' => 'Portal Scheme',
                    'type' => 'select',
                    'options' => [
                        'light' => 'Light',
                        'dark' => 'Dark',
                        'auto' => 'Auto',
                    ],
                    'description' => 'Light, dark, or operating-system driven portal scheme'
                ],
                'portal_sidebar_appearance' => [
                    'label' => 'Sidebar Appearance',
                    'type' => 'select',
                    'options' => [
                        'default' => 'Default',
                        'dense' => 'Dense',
                        'thin' => 'Thin',
                        'compact' => 'Compact',
                    ],
                    'description' => 'Sidebar density and label behavior'
                ],
                'portal_sidebar_style' => [
                    'label' => 'Sidebar Style',
                    'type' => 'select',
                    'options' => [
                        'dark' => 'Dark',
                        'light' => 'Light',
                        'brand' => 'Brand Color',
                    ],
                    'description' => 'Sidebar background style'
                ],
                'footer_copyright_text' => [
                    'label' => 'Footer Copyright Text',
                    'type' => 'text',
                    'placeholder' => 'The Taxi. All rights reserved.',
                    'description' => 'Portal footer copyright text'
                ],
                'footer_company_name' => [
                    'label' => 'Footer Company Name',
                    'type' => 'text',
                    'placeholder' => 'The Taxi',
                    'description' => 'Company name shown in portal footer'
                ],
            ]
        ];
    }

    /**
     * Get settings for a specific category
     */
    public function getCategorySettings(string $category): array
    {
        $categories = $this->getAllSettingCategories();

        if (!isset($categories[$category])) {
            throw new \InvalidArgumentException("Invalid settings category: {$category}");
        }

        $config = $categories[$category];
        $settingTypes = array_keys($config['settings']);

        // Get actual values from the settings service
        $values = $this->settingsService->getMultiple($settingTypes);

        return [
            'config' => $config,
            'values' => $values
        ];
    }

    /**
     * Get all settings with their configurations and current values
     */
    public function getAllSettingsWithValues(): array
    {
        $categories = $this->getAllSettingCategories();
        $result = [];

        foreach ($categories as $categoryKey => $categoryConfig) {
            $settingTypes = array_keys($categoryConfig['settings']);
            $values = $this->settingsService->getMultiple($settingTypes);

            $result[$categoryKey] = [
                'config' => $categoryConfig,
                'values' => $values
            ];
        }

        return $result;
    }

    /**
     * Update settings for a specific category
     */
    public function updateCategorySettings(string $category, array $settings): void
    {
        $categories = $this->getAllSettingCategories();

        if (!isset($categories[$category])) {
            throw new \InvalidArgumentException("Invalid settings category: {$category}");
        }

        $validSettings = array_keys($categories[$category]['settings']);

        foreach ($settings as $type => $value) {
            if (in_array($type, $validSettings)) {
                $this->settingsService->set($type, $value);
            }
        }
    }
}
