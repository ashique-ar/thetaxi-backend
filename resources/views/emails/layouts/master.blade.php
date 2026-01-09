<!DOCTYPE html>
<html lang="en">

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
            padding: 40px 20px;
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
            background: linear-gradient(135deg, #BF2629 0%, #8f1d1f 100%);
            padding: 40px 30px;
            text-align: center;
        }

        .logo-container {
            margin-bottom: 20px;
        }

        .logo-container img {
            max-height: 50px;
            width: auto;
        }

        .email-header h1 {
            color: #ffffff;
            font-size: 26px;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .email-header .subtitle {
            color: rgba(255, 255, 255, 0.85);
            font-size: 14px;
            margin-top: 8px;
        }

        /* Content */
        .email-content {
            padding: 40px 35px;
        }

        .greeting {
            font-size: 16px;
            color: #333333;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .greeting strong {
            color: #BF2629;
        }

        .intro-text {
            font-size: 15px;
            color: #555555;
            line-height: 1.7;
            margin-bottom: 25px;
        }

        /* Reference Box */
        .reference-box {
            background: linear-gradient(135deg, #fef5f5 0%, #fff8f8 100%);
            border: 1px solid rgba(191, 38, 41, 0.15);
            border-left: 4px solid #BF2629;
            border-radius: 8px;
            padding: 20px 24px;
            margin: 25px 0;
        }

        .reference-label {
            font-size: 12px;
            color: #717171;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .reference-number {
            font-size: 22px;
            font-weight: 700;
            color: #BF2629;
            letter-spacing: 1px;
        }

        /* Section */
        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 17px;
            font-weight: 600;
            color: #BF2629;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #BF2629;
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
            padding: 12px 0;
            font-size: 14px;
            vertical-align: top;
        }

        .info-table td:first-child {
            font-weight: 600;
            color: #717171;
            width: 40%;
            padding-right: 15px;
        }

        .info-table td:last-child {
            color: #333333;
        }

        /* Highlight Box */
        .highlight-box {
            background-color: #f8f9fa;
            border-left: 4px solid #BF2629;
            border-radius: 0 8px 8px 0;
            padding: 20px 24px;
            margin: 20px 0;
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
            margin: 0 0 12px 0;
            font-size: 16px;
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
            margin: 8px 0;
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
            margin: 30px 0;
        }

        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #BF2629 0%, #a02123 100%);
            color: #ffffff !important;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(191, 38, 41, 0.25);
        }

        .btn:hover {
            background: linear-gradient(135deg, #a02123 0%, #8f1d1f 100%);
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
            background: linear-gradient(to right, transparent, #e5e7eb, transparent);
            margin: 30px 0;
        }

        /* Footer */
        .email-footer {
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f2f4 100%);
            padding: 30px 35px;
            text-align: center;
            border-top: 1px solid #eef0f2;
        }

        .footer-brand {
            margin-bottom: 20px;
        }

        .footer-brand img {
            max-height: 32px;
            opacity: 0.8;
        }

        .footer-text {
            font-size: 13px;
            color: #717171;
            margin: 0;
            line-height: 1.6;
        }

        .footer-text strong {
            color: #BF2629;
        }

        .footer-links {
            margin: 15px 0;
        }

        .footer-links a {
            color: #717171;
            text-decoration: none;
            font-size: 13px;
            margin: 0 10px;
        }

        .footer-links a:hover {
            color: #BF2629;
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
            margin-top: 15px;
        }

        /* Contact Card */
        .contact-card {
            background: #ffffff;
            border: 1px solid #eef0f2;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
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
            color: #BF2629;
        }

        .contact-item a {
            color: #BF2629;
            text-decoration: none;
        }

        /* Price Row */
        .price-total {
            background-color: #fef5f5;
            font-weight: 600;
        }

        .price-total td {
            font-size: 16px !important;
            padding: 16px 0 !important;
        }

        .price-total td:last-child {
            color: #BF2629 !important;
        }

        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-wrapper {
                padding: 20px 15px;
            }

            .email-header {
                padding: 30px 20px;
            }

            .email-header h1 {
                font-size: 22px;
            }

            .email-content {
                padding: 25px 20px;
            }

            .email-footer {
                padding: 25px 20px;
            }

            .info-table td {
                display: block;
                width: 100%;
            }

            .info-table td:first-child {
                padding-bottom: 4px;
                border-bottom: none;
            }

            .info-table td:last-child {
                padding-top: 0;
            }

            .btn {
                display: block;
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <div class="email-wrapper">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="center">
                    <div class="email-container">
                        <!-- Header -->
                        <div class="email-header">
                            <div class="logo-container">
                                <img src="{{ asset('assets/img/casons-logo-white.png') }}"
                                    alt="{{ env('COMPANY_NAME', 'Casons Rent A Car') }}">
                            </div>
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
                            <div class="footer-brand">
                                <img src="{{ asset('assets/img/casons-logo-gray.png') }}"
                                    alt="{{ env('COMPANY_NAME', 'Casons Rent A Car') }}">
                            </div>

                            <p class="footer-text">
                                Best regards,<br>
                                <strong>{{ env('COMPANY_NAME', 'Casons Rent A Car') }} Team</strong>
                            </p>

                            <div class="footer-links">
                                <a href="{{ config('app.url') }}">Website</a>
                                <a href="mailto:{{ config('mail.from.address', 'info@casonsrentacar.lk') }}">Email
                                    Us</a>
                                <a href="tel:+94112345678">Call Us</a>
                            </div>

                            <p class="copyright">
                                © {{ date('Y') }} {{ env('COMPANY_NAME', 'Casons Rent A Car') }} (Pvt) Ltd. All
                                rights reserved.<br>
                                <span style="font-size: 11px; color: #aaa;">This is an automated email. Please do not
                                    reply directly to this message.</span>
                            </p>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>

</html>
