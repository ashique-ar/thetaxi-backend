<!DOCTYPE html>
<html lang="en">

@php
    $brandColor = $brandColor ?? '#BF2629';
    $emailTheme = $emailTheme ?? 'default';
@endphp

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>@yield('title', config('app.name'))</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        /* Reset styles */
        body,
        table,
        td,
        a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        table,
        td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }

        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }

        /* Base styles */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            width: 100% !important;
            min-width: 100%;
            background-color: #f4f5f7;
            -webkit-font-smoothing: antialiased;
        }

        /* Container */
        .email-wrapper {
            width: 100%;
            background-color: #f4f5f7;
            padding: 0;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
        }

        /* Header */
        .email-header {
            background-color: {{ $brandColor }};
            padding: 14px 24px;
            text-align: center;
        }

        .logo-container {
            margin-bottom: 0;
        }

        .logo-container img {
            height: 40px;
            max-height: 40px;
            width: auto;
        }

        .email-header h1 {
            color: #ffffff;
            font-size: 20px;
            font-weight: 600;
            margin: 6px 0 0;
            letter-spacing: -0.5px;
        }

        .email-header .subtitle {
            color: rgba(255, 255, 255, 0.85);
            font-size: 13px;
            margin: 2px 0 0;
        }

        /* Content */
        .email-content {
            padding: 18px 24px;
        }

        .greeting {
            font-size: 14px;
            color: #333333;
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .greeting strong {
            color: {{ $brandColor }};
        }

        .intro-text {
            font-size: 14px;
            color: #555555;
            line-height: 1.55;
            margin-bottom: 16px;
        }

        /* Reference Box */
        .reference-box {
            background-color: #f7f7f8;
            border: 1px solid #e5e7eb;
            border-left: 4px solid {{ $brandColor }};
            border-radius: 8px;
            padding: 8px 16px;
            margin: 14px 0;
        }

        .reference-label {
            font-size: 12px;
            color: #717171;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .reference-number {
            font-size: 18px;
            font-weight: 700;
            color: {{ $brandColor }};
            letter-spacing: 1px;
        }

        /* Section */
        .section {
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 15px;
            font-weight: 600;
            color: {{ $brandColor }};
            margin: 0 0 6px;
            padding-bottom: 4px;
            border-bottom: 2px solid {{ $brandColor }};
            display: flex;
            align-items: center;
        }

        .section-title .icon {
            margin-right: 10px;
        }

        /* Info Table */
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table tr {
            border-bottom: 1px solid #eef0f2;
        }

        .info-table tr:last-child {
            border-bottom: none;
        }

        .info-table td {
            padding: 3px 0;
            font-size: 14px;
            vertical-align: top;
        }

        .info-table td:first-child {
            font-weight: 600;
            color: #717171;
            width: 40%;
            padding-right: 12px;
        }

        .info-table td:last-child {
            color: #333333;
        }

        /* Highlight Box */
        .highlight-box {
            background-color: #f8f9fa;
            border-left: 4px solid {{ $brandColor }};
            border-radius: 0 8px 8px 0;
            padding: 10px 14px;
            margin: 10px 0;
        }

        .highlight-box.success {
            background-color: #f0fdf4;
            border-left-color: #22c55e;
        }

        .highlight-box.warning {
            background-color: #fffbeb;
            border-left-color: #f59e0b;
        }

        .highlight-box.info {
            background-color: #eff6ff;
            border-left-color: #3b82f6;
        }

        .highlight-box h3 {
            margin: 0 0 6px 0;
            font-size: 15px;
            font-weight: 600;
        }

        .highlight-box.success h3 {
            color: #16a34a;
        }

        .highlight-box.warning h3 {
            color: #d97706;
        }

        .highlight-box.info h3 {
            color: #2563eb;
        }

        .highlight-box p {
            margin: 4px 0;
            font-size: 14px;
            color: #555555;
            line-height: 1.6;
        }

        .highlight-box ul {
            margin: 12px 0 0 0;
            padding-left: 20px;
        }

        .highlight-box li {
            font-size: 14px;
            color: #555555;
            margin-bottom: 6px;
            line-height: 1.5;
        }

        /* Button */
        .btn-container {
            text-align: center;
            margin: 18px 0 6px;
        }

        .btn {
            display: inline-block;
            padding: 10px 24px;
            background-color: {{ $brandColor }};
            color: #ffffff !important;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
        }

        .btn:hover {
            background-color: #166534;
        }

        .btn-secondary {
            background: #717171;
            box-shadow: 0 4px 12px rgba(113, 113, 113, 0.25);
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-paid {
            background-color: #dcfce7;
            color: #16a34a;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #d97706;
        }

        .status-processing {
            background-color: #dbeafe;
            color: #2563eb;
        }

        /* Divider */
        .divider {
            height: 1px;
            line-height: 1px;
            font-size: 1px;
            background-color: #e5e7eb;
            margin: 14px 0;
        }

        /* Footer */
        .email-footer {
            background-color: #f6f7f8;
            padding: 14px 24px;
            text-align: center;
            border-top: 1px solid #eef0f2;
        }

        .footer-text {
            font-size: 13px;
            color: #717171;
            margin: 0;
            line-height: 1.6;
        }

        .footer-text strong {
            color: {{ $brandColor }};
        }

        .footer-links {
            margin: 6px 0;
        }

        .footer-links a {
            color: #717171;
            text-decoration: none;
            font-size: 13px;
            margin: 0 10px;
        }

        .footer-links a:hover {
            color: {{ $brandColor }};
        }

        .social-links {
            margin: 20px 0;
        }

        .social-links a {
            display: inline-block;
            margin: 0 8px;
        }

        .social-links img {
            width: 28px;
            height: 28px;
            opacity: 0.7;
        }

        .copyright {
            font-size: 12px;
            color: #999999;
            margin: 6px 0 0;
        }

        /* Contact Card */
        .contact-card {
            background: #ffffff;
            border: 1px solid #eef0f2;
            border-radius: 10px;
            padding: 12px 14px;
            margin: 12px 0;
        }

        .contact-card .title {
            font-size: 15px;
            font-weight: 600;
            color: #333333;
            margin-bottom: 15px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .contact-item:last-child {
            margin-bottom: 0;
        }

        .contact-icon {
            width: 20px;
            margin-right: 12px;
            color: {{ $brandColor }};
        }

        .contact-item a {
            color: {{ $brandColor }};
            text-decoration: none;
        }

        /* Price Row */
        .price-total {
            background-color: #f7f7f8;
            font-weight: 600;
        }

        .price-total td {
            font-size: 15px !important;
            padding: 8px 0 !important;
        }

        .price-total td:last-child {
            color: {{ $brandColor }} !important;
        }

        .brand-name {
            color: #ffffff;
            font-size: 18px;
        }

        /* Theme layers: same structure and brand colour, each theme's own look. */
        @if ($emailTheme === 'theme-02')
        /* Theme 02 - contemporary: light header with a brand bar, soft rounded cards, pill buttons. */
        .email-container { border-radius: 16px; }
        .email-header { background-color: #ffffff; border-top: 4px solid {{ $brandColor }}; border-bottom: 1px solid #eef0f2; }
        .email-header h1, .brand-name { color: #1f2937; }
        .email-header .subtitle { color: #6b7280; }
        .section-title { border-bottom: 1px solid #e5e7eb; }
        .reference-box, .highlight-box { border-radius: 12px; }
        .btn { border-radius: 999px; }
        @elseif ($emailTheme === 'theme-03')
        /* Theme 03 - Ceylon Modernist: warm paper, ink header, square edges, editorial serif headings. */
        body, .email-wrapper { background-color: #efe9dd; }
        .email-container { background-color: #fffdf8; border-radius: 0; border: 1px solid #d9d1c1; }
        .email-header { background-color: #1d211f; border-bottom: 4px solid {{ $brandColor }}; text-align: left; }
        .email-header h1 { font-family: Georgia, 'Times New Roman', serif; font-weight: 400; }
        .email-header .subtitle { color: #d9d1c1; }
        .section-title { font-family: Georgia, 'Times New Roman', serif; font-weight: 400; color: #1d211f; border-bottom: 1px solid #d9d1c1; }
        .reference-box, .highlight-box, .contact-card { border-radius: 0; background-color: #f6f1e7; }
        .reference-label { color: #6b6457; }
        .info-table tr { border-bottom-color: #ebe4d6; }
        .btn { border-radius: 0; background-color: #1d211f; }
        .email-footer { background-color: #f6f1e7; border-top-color: #d9d1c1; }
        @elseif ($emailTheme === 'theme-04')
        /* Theme 04 - Executive: white header with a brand rule, crisp white body, tight corners. */
        .email-container { border-radius: 6px; border: 1px solid #e5e7eb; }
        .email-header { background-color: #ffffff; border-top: 4px solid {{ $brandColor }}; text-align: left; }
        .email-header h1, .brand-name { color: #1f2937; font-weight: 500; letter-spacing: -0.2px; }
        .email-header .subtitle { color: #6b7280; }
        .section-title { color: #111111; text-transform: uppercase; font-size: 13px; letter-spacing: 1px; border-bottom: 2px solid {{ $brandColor }}; }
        .reference-box, .highlight-box, .contact-card { border-radius: 4px; }
        .btn { border-radius: 4px; }
        .email-footer { background-color: #fafafa; }
        @endif

        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-wrapper {
                padding: 0;
            }

            .email-header {
                padding: 12px 16px !important;
            }

            .email-header h1 {
                font-size: 18px !important;
            }

            .email-content {
                padding: 14px 16px !important;
            }

            .email-footer {
                padding: 12px 16px !important;
            }

            .info-table td {
                display: block !important;
                width: 100% !important;
            }

            .info-table td:first-child {
                padding-bottom: 0 !important;
            }

            .info-table td:last-child {
                padding-top: 0 !important;
                padding-bottom: 4px !important;
            }

            .btn {
                display: block !important;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    @php
        $brandName = \App\Models\BusinessSetting::getSetting('company_name') ?: ($settings['company_name'] ?? $settings['brand_name'] ?? $settings['site_name'] ?? 'Company');
        $brandLogo = \App\Models\BusinessSetting::getSetting('company_logo_path') ?: ($settings['company_logo_path'] ?? $settings['brand_logo_primary'] ?? $settings['logo_header'] ?? null);
        $brandLogoUrl = $brandLogo ? app(\App\Services\EmailImageVariantService::class)->url($brandLogo, 'logo') : null;
        $brandEmail = $settings['company_email'] ?? config('mail.from.address');
        $brandPhone = $settings['company_phone'] ?? null;
        $brandWebsite = $settings['company_website'] ?? config('app.url');
    @endphp
    <div class="email-wrapper">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width: 100%;">
            <tr>
                <td align="center">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="width: 100%; max-width: 600px; background-color: #ffffff;">
                    <tr><td class="email-container" style="width: 600px; max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden;">
                        <!-- Header -->
                        <div class="email-header">
                            @if($brandLogoUrl)
                                <div class="logo-container"><img src="{{ $brandLogoUrl }}" alt="{{ $brandName }}" height="40" style="height: 40px; max-height: 40px; width: auto;"></div>
                            @else
                                <strong class="brand-name">{{ $brandName }}</strong>
                            @endif
                            <h1>@yield('header_title', 'Welcome')</h1>
                            @hasSection('header_subtitle')
                                <p class="subtitle">@yield('header_subtitle')</p>
                            @endif
                        </div>

                        <!-- Content -->
                        <div class="email-content">
                            @yield('content')
                        </div>

                        <!-- Footer -->
                        <div class="email-footer">
                            <p class="footer-text">
                                Best regards,<br>
                                <strong>{{ $brandName }} Team</strong>
                            </p>

                            <div class="footer-links">
                                <a href="{{ $brandWebsite }}">Website</a>
                                @if($brandEmail)
                                <a href="mailto:{{ $brandEmail }}">Email
                                    Us</a>
                                @endif
                                @if($brandPhone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $brandPhone) }}">Call Us</a>
                                @endif
                            </div>

                            <p class="copyright">
                                © {{ date('Y') }} {{ $brandName }}. All
                                rights reserved.<br>
                                <span style="font-size: 11px; color: #aaa;">This is an automated email. Please do not
                                    reply directly to this message.</span>
                            </p>
                        </div>
                    </td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>

</html>
