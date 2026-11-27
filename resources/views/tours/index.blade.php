@extends('layouts.app')

@section('title', 'Plan your tour')

@push('styles')
    <style>
        .tour-page {
            --tour-primary: var(--primary-color1, #BF2629);
            --tour-primary-dark: color-mix(in srgb, var(--tour-primary) 72%, #09111a);
            --tour-primary-darker: color-mix(in srgb, var(--tour-primary) 52%, #050a10);
            --tour-primary-soft: color-mix(in srgb, var(--tour-primary) 8%, #ffffff);
            --tour-primary-tint: color-mix(in srgb, var(--tour-primary) 14%, #ffffff);
            --tour-primary-border: color-mix(in srgb, var(--tour-primary) 26%, transparent);
            --tour-primary-ring: color-mix(in srgb, var(--tour-primary) 18%, transparent);
            --tour-ink: #0f172a;
            --tour-ink-soft: #334155;
            --tour-muted: #64748b;
            --tour-canvas: #f8fafc;
            --tour-surface: #ffffff;
            --tour-border: #e2e8f0;
            --tour-emerald: #059669;
            --tour-emerald-soft: #ecfdf5;
            --tour-emerald-border: #a7f3d0;
            color: var(--tour-ink);
            background-color: var(--tour-canvas);
        }

        /* Hero Banner */
        .tour-hero {
            background: linear-gradient(135deg, var(--tour-primary) 0%, var(--tour-primary-dark) 58%, var(--tour-primary-darker) 100%);
            color: #ffffff;
            padding: clamp(2.5rem, 5vw, 4rem) 0;
            position: relative;
            overflow: hidden;
        }

        .tour-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 85% 20%, rgba(255, 255, 255, 0.12) 0%, transparent 55%);
            pointer-events: none;
        }

        .tour-hero-inner {
            position: relative;
            z-index: 1;
            max-width: 52rem;
        }

        .tour-hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.875rem;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .tour-hero h1 {
            color: #ffffff;
            font-weight: 800;
            letter-spacing: -0.025em;
            line-height: 1.15;
            font-size: clamp(1.85rem, 3.8vw, 2.85rem);
            margin-bottom: 0.875rem;
        }

        .tour-hero-lead {
            color: rgba(255, 255, 255, 0.88);
            font-size: clamp(0.95rem, 1.5vw, 1.125rem);
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .tour-trust-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem 1.25rem;
        }

        @media (min-width: 640px) {
            .tour-trust-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        .tour-trust-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.92);
        }

        .tour-trust-icon {
            width: 1rem;
            height: 1rem;
            color: rgba(255, 255, 255, 0.95);
            flex-shrink: 0;
        }

        /* Sticky Quick-Nav & Status Strip */
        .tour-status-bar {
            background: var(--tour-surface);
            border-bottom: 1px solid var(--tour-border);
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }

        .tour-nav-pills {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            padding: 0.25rem;
            background: #f1f5f9;
            border: 1px solid var(--tour-border);
            border-radius: 0.625rem;
        }

        .tour-nav-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.45rem 0.85rem;
            border-radius: 0.45rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--tour-muted);
            text-decoration: none;
            transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
        }

        .tour-nav-pill:hover,
        .tour-nav-pill:focus-visible {
            color: var(--tour-primary);
            background: rgba(255, 255, 255, 0.8);
        }

        .tour-nav-pill.is-active {
            background: var(--tour-surface);
            color: var(--tour-primary);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        }

        .tour-live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.78rem;
            font-weight: 500;
            color: var(--tour-muted);
        }

        .tour-live-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            background: var(--tour-emerald);
            flex-shrink: 0;
        }

        /* Cards & Step Headers */
        .tour-card {
            background: var(--tour-surface);
            border: 1px solid var(--tour-border);
            border-radius: 0.9rem;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.04);
        }

        .tour-card-header {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .tour-step-badge {
            width: 2.125rem;
            height: 2.125rem;
            border-radius: 9999px;
            background: var(--tour-primary);
            color: #ffffff;
            font-size: 0.875rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .tour-card-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--tour-ink);
            margin: 0;
        }

        .tour-card-subtitle {
            font-size: 0.8125rem;
            color: var(--tour-muted);
            margin: 0.15rem 0 0;
        }

        /* Forms & Controls (Modern Web Guidance) */
        .tour-page .form-label,
        .tour-page .tour-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--tour-ink-soft);
            margin-bottom: 0.375rem;
        }

        .tour-page .form-control,
        .tour-page .form-select {
            font-size: max(1rem, 0.9375rem);
            min-height: 44px;
            border-radius: 0.5rem;
            border-color: #cbd5e1;
            color: var(--tour-ink);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .tour-page .form-select-sm,
        .tour-page .form-control-sm {
            min-height: 38px;
            font-size: max(0.875rem, 14px);
        }

        .tour-page .form-control:focus,
        .tour-page .form-select:focus {
            border-color: var(--tour-primary);
            box-shadow: 0 0 0 3px var(--tour-primary-ring);
        }

        .tour-page .form-control:focus-visible,
        .tour-page .form-select:focus-visible,
        .tour-page button:focus-visible,
        .tour-page a:focus-visible,
        .tour-page summary:focus-visible {
            outline: 2px solid var(--tour-primary);
            outline-offset: 2px;
        }

        .tour-page :is(input, select, textarea):user-invalid {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
        }

        .tour-subpanel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1rem 1.125rem;
        }

        .tour-subpanel-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--tour-muted);
            margin-bottom: 0.75rem;
        }

        /* Selectable Option Cards (Vehicle Tiers & Destinations) */
        .tour-option {
            border: 1px solid #dbe3ec;
            border-radius: 0.85rem;
            padding: 1rem;
            height: 100%;
            background: var(--tour-surface);
            transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
        }

        label.tour-option {
            cursor: pointer;
        }

        .tour-option:hover {
            border-color: var(--tour-primary-border);
        }

        .tour-option:has(input.tour-class-choice:checked),
        .tour-option:has(input.tour-destination:checked) {
            border: 2px solid var(--tour-primary);
            background: var(--tour-primary-soft);
            box-shadow: 0 4px 14px -2px var(--tour-primary-ring);
        }

        .tour-page .form-check-input:checked {
            background-color: var(--tour-primary);
            border-color: var(--tour-primary);
        }

        .tour-page .form-check-input:focus {
            border-color: var(--tour-primary);
            box-shadow: 0 0 0 3px var(--tour-primary-ring);
        }

        /* Day Cards */
        .tour-day-card {
            border: 1px solid var(--tour-border);
            border-radius: 0.75rem;
            padding: 1rem;
            background: #f8fafc;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }

        .tour-day-card:hover {
            background: var(--tour-surface);
            border-color: #cbd5e1;
        }

        .tour-day-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: 0.375rem;
            background: var(--tour-primary);
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        /* AI Helper Box */
        .tour-ai-box {
            background: var(--tour-primary-soft);
            border: 1px solid var(--tour-primary-border);
            border-radius: 0.85rem;
            padding: 1.125rem;
        }

        /* Theme Buttons */
        .tour-btn-primary {
            background-color: var(--tour-primary);
            border-color: var(--tour-primary);
            color: #ffffff;
            font-weight: 700;
            border-radius: 0.55rem;
            min-height: 44px;
            padding: 0.65rem 1.35rem;
            transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
        }

        .tour-btn-primary:hover,
        .tour-btn-primary:focus-visible {
            background-color: var(--tour-primary-dark);
            border-color: var(--tour-primary-dark);
            color: #ffffff;
            box-shadow: 0 8px 18px -4px var(--tour-primary-ring);
        }

        .tour-btn-primary:disabled {
            background-color: var(--tour-primary);
            border-color: var(--tour-primary);
            opacity: 0.65;
        }

        .tour-btn-outline {
            background-color: transparent;
            border: 1.5px solid var(--tour-primary);
            color: var(--tour-primary);
            font-weight: 600;
            border-radius: 0.55rem;
            min-height: 42px;
            padding: 0.5rem 1.1rem;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        .tour-btn-outline:hover,
        .tour-btn-outline:focus-visible {
            background-color: var(--tour-primary-soft);
            color: var(--tour-primary-dark);
        }

        /* Route Timeline */
        .tour-route-stop {
            border-left: 2px solid #cbd5e1;
            margin-left: 0.55rem;
            padding: 0 0 1.1rem 1.25rem;
            position: relative;
        }

        .tour-route-stop::before {
            content: "";
            position: absolute;
            left: -0.42rem;
            top: 0.25rem;
            width: 0.7rem;
            height: 0.7rem;
            border-radius: 50%;
            background: var(--tour-primary);
        }

        .tour-route-stop:last-child {
            border: 0;
            padding-bottom: 0;
        }

        /* Guarantee / Advisory Callout */
        .tour-guarantee-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 0.85rem;
            padding: 1.25rem;
            color: #1e293b;
        }

        .tour-guarantee-title {
            color: #78350f;
            font-weight: 700;
            font-size: 0.9375rem;
            margin-bottom: 0.5rem;
        }

        /* Sidebar Cards */
        .tour-sidebar-card {
            background: var(--tour-surface);
            border: 1px solid var(--tour-border);
            border-radius: 0.9rem;
            box-shadow: 0 10px 25px -4px rgba(15, 23, 42, 0.06);
        }

        .tour-badge-guaranteed {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.55rem;
            border-radius: 0.375rem;
            background: var(--tour-emerald-soft);
            color: #065f46;
            border: 1px solid var(--tour-emerald-border);
            font-size: 0.7rem;
            font-weight: 700;
        }

        .tour-assistance-card {
            background: var(--tour-primary-soft);
            border: 1px solid var(--tour-primary-border);
            border-radius: 0.85rem;
            padding: 1rem 1.125rem;
        }

        .tour-assistance-link {
            color: var(--tour-primary);
            font-weight: 700;
            text-decoration: none;
        }

        .tour-assistance-link:hover {
            text-decoration: underline;
            color: var(--tour-primary-dark);
        }

        /* Modern Accordion (FAQ) */
        .tour-faq-section {
            background: var(--tour-surface);
            border-top: 1px solid var(--tour-border);
        }

        .tour-faq-item {
            background: #f8fafc;
            border: 1px solid var(--tour-border);
            border-radius: 0.65rem;
            overflow: hidden;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }

        .tour-faq-item[open] {
            background: var(--tour-surface);
            border-color: var(--tour-primary-border);
        }

        .tour-faq-item > summary {
            list-style: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.125rem;
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--tour-ink);
            cursor: pointer;
        }

        .tour-faq-item > summary::-webkit-details-marker {
            display: none;
        }

        .tour-faq-item > summary::before {
            content: "";
            inline-size: 0.65rem;
            block-size: 0.65rem;
            flex-shrink: 0;
            border-right: 2px solid var(--tour-primary);
            border-bottom: 2px solid var(--tour-primary);
            transform: rotate(-45deg);
            margin-inline-end: 0.25rem;
        }

        .tour-faq-item[open] > summary::before {
            transform: rotate(45deg);
        }

        @media (prefers-reduced-motion: no-preference) {
            .tour-faq-item > summary::before {
                transition: transform 0.2s ease;
            }

            .tour-faq-item::details-content {
                transition: block-size 0.25s ease-in-out, content-visibility 0.25s ease-in-out;
                transition-behavior: allow-discrete;
            }
        }

        .tour-faq-item::details-content {
            interpolate-size: allow-keywords;
            overflow: clip;
            contain: layout;
        }

        .tour-faq-item:not([open])::details-content {
            block-size: 0;
        }

        .tour-faq-body {
            padding: 0 1.125rem 1rem 2.25rem;
            font-size: 0.875rem;
            line-height: 1.6;
            color: var(--tour-muted);
        }

        .tour-muted {
            color: var(--tour-muted);
        }

        .tour-mobile-summary {
            z-index: 1030;
        }

        @media (max-width: 991.98px) {
            .tour-page {
                padding-bottom: 5.5rem;
            }
        }

        [hidden] {
            display: none !important;
        }
    </style>
@endpush

@section('content')
    @php
        $supportPhone = $settings['company_phone'] ?? '';
        $supportPhoneHref = preg_replace('/[^0-9+]/', '', $supportPhone);
        $supportWhatsapp = preg_replace('/[^0-9]/', '', $settings['company_whatsapp'] ?? $supportPhone);
    @endphp

    <div class="tour-page">
        <!-- Hero Banner -->
        <section class="tour-hero">
            <div class="container">
                <div class="tour-hero-inner">
                    <div class="tour-hero-eyebrow">
                        <span>Multi-Day Private Tours &amp; Chauffeur Hire</span>
                    </div>
                    <h1>Custom Multi-Day Tour &amp; Private Driver Hire</h1>
                    <p class="tour-hero-lead">
                        Design your bespoke road trip or select a handcrafted tour package. Choose from our published places
                        and activities — we compare your order with a shorter, practical route and calculate a transparent transport quote.
                    </p>
                    <div class="tour-trust-grid">
                        <div class="tour-trust-item">
                            <svg class="tour-trust-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                            </svg>
                            <span>Vetted Tourist Drivers</span>
                        </div>
                        <div class="tour-trust-item">
                            <svg class="tour-trust-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                            </svg>
                            <span>Transparent Pricing</span>
                        </div>
                        <div class="tour-trust-item">
                            <svg class="tour-trust-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                            </svg>
                            <span>Smart Route Comparison</span>
                        </div>
                        <div class="tour-trust-item">
                            <svg class="tour-trust-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                            </svg>
                            <span>Shared Secure Checkout</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Quick Step Navigation & Live Status Strip -->
        <div class="tour-status-bar">
            <div class="container py-2">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <nav class="tour-nav-pills" aria-label="Tour planner steps">
                        <a class="tour-nav-pill is-active" href="#tour-step-1">1. Trip &amp; Vehicle</a>
                        <a class="tour-nav-pill" href="#tour-step-2">2. Route &amp; Stops</a>
                        <a class="tour-nav-pill" href="#tour-step-3">3. Quote &amp; Route</a>
                        <a class="tour-nav-pill" href="#tour-contact-section">4. Contact</a>
                    </nav>
                    <div class="tour-live-indicator d-none d-md-inline-flex">
                        <span class="tour-live-dot" aria-hidden="true"></span>
                        <span>Live route &amp; tariff calculator</span>
                    </div>
                </div>
            </div>
        </div>

        <main class="container py-4 py-lg-5">
            <div id="tour-error" class="alert alert-danger mb-4" role="alert" hidden></div>

            <div id="tour-loading" class="tour-card p-5 text-center">
                <div class="spinner-border text-secondary mb-3" role="status" aria-hidden="true"></div>
                <p class="mb-0 fw-semibold">Loading available tours and published destinations…</p>
            </div>

            <form id="tour-form" class="row g-4 align-items-start" hidden>
                <!-- LEFT COLUMN: Step-by-Step Interactive Planner (8 Cols) -->
                <div class="col-lg-8 d-flex flex-column gap-4">

                    <!-- STEP 1: Trip Essentials & Vehicle Tier -->
                    <section id="tour-step-1" class="tour-card p-3 p-md-4">
                        <div class="tour-card-header">
                            <span class="tour-step-badge">1</span>
                            <div>
                                <h2 class="tour-card-title">Trip Essentials &amp; Vehicle Tier</h2>
                                <p class="tour-card-subtitle">Specify your dates, tour service, party size, and private vehicle class.</p>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="tour-service" class="form-label">Tour service *</label>
                                <select id="tour-service" class="form-select" required></select>
                            </div>
                            <div class="col-md-6">
                                <label for="tour-package" class="form-label">Handcrafted package <span class="text-lowercase fw-normal tour-muted">(optional)</span></label>
                                <select id="tour-package" class="form-select">
                                    <option value="">Choose later</option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="tour-date" class="form-label">Start date *</label>
                                <input id="tour-date" type="date" class="form-control" required>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="tour-days" class="form-label">Number of days *</label>
                                <input id="tour-days" type="number" class="form-control" min="1" max="60" value="2" required>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="tour-time" class="form-label">Daily start time *</label>
                                <input id="tour-time" type="time" class="form-control" value="09:00" required>
                            </div>
                        </div>

                        <!-- Party Size & Baggage Sub-panel -->
                        <fieldset class="tour-subpanel mb-4">
                            <legend class="tour-subpanel-title">Party Size &amp; Baggage Load</legend>
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label for="tour-adults" class="form-label">Adults *</label>
                                    <input id="tour-adults" type="number" class="form-control" min="0" max="100" value="2" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label for="tour-children" class="form-label">Children *</label>
                                    <input id="tour-children" type="number" class="form-control" min="0" max="100" value="0" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label for="tour-child-seats" class="form-label">Child seats</label>
                                    <input id="tour-child-seats" type="number" class="form-control" min="0" max="100" value="0" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label for="tour-luggage" class="form-label">Luggage *</label>
                                    <input id="tour-luggage" type="number" class="form-control" min="0" max="100" value="0" required>
                                </div>
                            </div>
                            <p class="form-text mb-0 mt-2">At checkout, select matching add-ons tagged child-seat. Seat availability is subject to confirmation.</p>
                        </fieldset>

                        <div id="tour-custom-fields" class="row g-3 mb-4"></div>

                        <!-- Vehicle Tier Selector Cards -->
                        <fieldset class="border-0 p-0 m-0">
                            <legend class="tour-label mb-2">Select Dedicated Private Vehicle *</legend>
                            <div id="tour-class-cards" class="row g-3" role="radiogroup" aria-label="Vehicle class"></div>
                            <select id="tour-class" class="d-none" tabindex="-1" aria-hidden="true"></select>
                        </fieldset>

                        <!-- Optional AI Itinerary Ideas -->
                        <div id="tour-ai-helper" class="mt-4" hidden>
                            <div class="tour-ai-box">
                                <h3 class="h6 fw-bold mb-1">Optional itinerary ideas</h3>
                                <p class="small tour-muted mb-3">
                                    Share a few interests to get an editable draft based on our published places. We send only
                                    your trip length, optional start date, interests, and the published tour catalogue.
                                    Suggestions do not confirm a route, price, availability, tickets, or reservations.
                                </p>
                                <label for="tour-interests" class="form-label">What are you interested in? <span class="text-lowercase fw-normal tour-muted">(optional)</span></label>
                                <textarea id="tour-interests" class="form-control" rows="2" maxlength="500"
                                    placeholder="For example: beaches, wildlife, history"></textarea>
                                <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                                    <button id="tour-ai-suggest" class="btn tour-btn-outline btn-sm" type="button">Suggest itinerary ideas</button>
                                    <span id="tour-ai-status" class="small tour-muted" role="status" aria-live="polite"></span>
                                </div>
                                <div id="tour-ai-preview" class="mt-3" hidden></div>
                            </div>
                        </div>
                    </section>

                    <!-- STEP 2: Day-by-Day Route & Stop Customizer -->
                    <section id="tour-step-2" class="tour-card p-3 p-md-4">
                        <div class="tour-card-header">
                            <span class="tour-step-badge">2</span>
                            <div>
                                <h2 class="tour-card-title">Day-by-Day Route &amp; Stop Customizer</h2>
                                <p class="tour-card-subtitle">Select a start and finish point, overnight stays, and the places or activities you want to visit.</p>
                            </div>
                        </div>

                        <div class="tour-subpanel mb-4">
                            <search class="mb-3 d-block">
                                <label for="tour-start-search" class="form-label">Filter start/finish locations</label>
                                <input id="tour-start-search" type="search" class="form-control mb-3"
                                    autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"
                                    placeholder="Search listed places…">
                            </search>
                            <div>
                                <label for="tour-start" class="form-label">Start and finish at *</label>
                                <select id="tour-start" class="form-select" required>
                                    <option value="">Choose a listed place</option>
                                </select>
                            </div>
                        </div>

                        <div id="tour-day-settings" class="row g-3 mb-4"></div>

                        <search class="mb-3 d-block">
                            <label for="tour-destination-search" class="form-label">Search published places and activities</label>
                            <input id="tour-destination-search" type="search" class="form-control"
                                autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"
                                placeholder="Type a place or activity name…">
                        </search>

                        <div id="tour-destinations" class="row g-3"></div>
                        <p class="small tour-muted mt-3 mb-0">
                            Only locations published by our team are selectable here. Activities add a stop to your route when selected.
                        </p>
                    </section>

                    <!-- STEP 3: Review Route & Transport Price -->
                    <section id="tour-step-3" class="tour-card p-3 p-md-4">
                        <div class="tour-card-header">
                            <span class="tour-step-badge">3</span>
                            <div>
                                <h2 class="tour-card-title">Review Your Route &amp; Transport Price</h2>
                                <p class="tour-card-subtitle">We calculate the route and configured transport price, then connect you to our shared checkout.</p>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <button id="tour-plan" class="btn tour-btn-primary" type="submit">Build route and quote</button>
                            <span id="tour-working" class="small tour-muted fw-medium" hidden>Checking route and price…</span>
                        </div>
                        <div id="tour-results" class="mt-4" hidden></div>
                    </section>

                    <!-- STEP 4: Lead Passenger Contact -->
                    <section id="tour-contact-section" class="tour-card p-3 p-md-4">
                        <div class="tour-card-header">
                            <span class="tour-step-badge">4</span>
                            <div>
                                <h2 class="tour-card-title">Lead Passenger Contact</h2>
                                <p class="tour-card-subtitle">Add your contact details so we can save this route and quote to your booking.</p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="tour-first-name" class="form-label">First name *</label>
                                <input id="tour-first-name" class="form-control" autocomplete="given-name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="tour-last-name" class="form-label">Last name</label>
                                <input id="tour-last-name" class="form-control" autocomplete="family-name">
                            </div>
                            <div class="col-md-6">
                                <label for="tour-email" class="form-label">Email address *</label>
                                <input id="tour-email" type="email" class="form-control" autocomplete="email"
                                    autocorrect="off" autocapitalize="none" spellcheck="false" required>
                            </div>
                            <div class="col-md-6">
                                <label for="tour-phone" class="form-label">Phone / WhatsApp *</label>
                                <input id="tour-phone" type="tel" class="form-control" autocomplete="tel" required>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-top">
                            <button id="tour-send-code" class="btn btn-link px-0 text-decoration-none fw-semibold" style="color: var(--tour-primary);" type="button">
                                Returning customer? Send a verification code
                            </button>
                            <div class="row g-2 align-items-end mt-1" id="tour-code-panel" hidden>
                                <div class="col-sm-6">
                                    <label for="tour-code" class="form-label">6-digit email code</label>
                                    <input id="tour-code" class="form-control" inputmode="numeric" autocomplete="one-time-code"
                                        autocorrect="off" autocapitalize="none" spellcheck="false" maxlength="6">
                                </div>
                                <div class="col-sm-auto">
                                    <button id="tour-verify-code" class="btn tour-btn-outline" type="button">Verify and load details</button>
                                </div>
                            </div>
                            <div id="tour-code-status" class="small mt-2 tour-muted" role="status" aria-live="polite"></div>
                        </div>
                    </section>

                    <!-- Transparency & Planning Guarantee Callout -->
                    <div class="tour-guarantee-box">
                        <div class="d-flex align-items-start gap-3">
                            <svg width="22" height="22" class="flex-shrink-0 mt-1" style="color: #b45309;" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                            <div class="small lh-base">
                                <h3 class="tour-guarantee-title">Transparent Route &amp; Transport Guarantee</h3>
                                <p class="mb-2">
                                    <strong>Smart Route Optimization:</strong> Our planner compares your selected stop order against a practical route that reduces backtracking while respecting published activity opening hours.
                                </p>
                                <p class="mb-0">
                                    <strong>Activity Tickets Separate:</strong> Activity visits are included in your route only. Entry tickets and activity reservations are arranged separately.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Sticky Live Quote & Assistance (4 Cols) -->
                <aside class="col-lg-4 sticky-lg-top d-flex flex-column gap-4" style="top: 1.5rem;">
                    <div class="tour-sidebar-card p-3 p-md-4">
                        <div class="d-flex align-items-center justify-content-between pb-3 border-bottom">
                            <span class="text-uppercase fw-bold small tour-muted" style="letter-spacing: 0.06em;">Live Transport Quote</span>
                            <span class="tour-badge-guaranteed">Guaranteed Rate</span>
                        </div>

                        <section id="tour-total-summary" class="py-3 border-bottom" aria-live="polite" hidden>
                            <h2 class="h6 tour-muted mb-1">Estimated tour transport</h2>
                            <p id="tour-total-amount" class="h3 fw-extrabold mb-1" style="color: var(--tour-ink);"></p>
                            <p id="tour-total-distance" class="small tour-muted mb-0"></p>
                        </section>

                        <div class="py-3 border-bottom">
                            <h2 class="h6 fw-bold mb-2">Before you continue</h2>
                            <ul class="list-unstyled small mb-0 d-flex flex-column gap-2 tour-muted">
                                <li class="d-flex align-items-start gap-2">
                                    <svg width="16" height="16" class="flex-shrink-0 mt-1" style="color: var(--tour-emerald);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                                    </svg>
                                    <span>The suggested order tries to reduce backtracking while respecting activity opening times.</span>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <svg width="16" height="16" class="flex-shrink-0 mt-1" style="color: var(--tour-emerald);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                                    </svg>
                                    <span>Displayed transport price is based on your selected route and current rates.</span>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <svg width="16" height="16" class="flex-shrink-0 mt-1" style="color: var(--tour-emerald);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                                    </svg>
                                    <span>Vehicle availability is checked as part of our shared checkout process.</span>
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <svg width="16" height="16" class="flex-shrink-0 mt-1" style="color: var(--tour-emerald);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                                    </svg>
                                    <span>Activity visits are route stops only; tickets and entry fees are arranged separately.</span>
                                </li>
                            </ul>
                        </div>

                        <p class="small tour-muted pt-3 mb-0">
                            Complete steps 1–4 and click <strong>Build route and quote</strong> to lock in your itinerary and proceed to checkout.
                        </p>
                    </div>

                    @if (!empty($supportPhone) || !empty($supportWhatsapp))
                        <div class="tour-assistance-card small">
                            <div class="fw-bold mb-1 d-flex align-items-center gap-2" style="color: var(--tour-ink);">
                                <svg width="16" height="16" style="color: var(--tour-primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                </svg>
                                <span>Prefer to discuss your route directly?</span>
                            </div>
                            <p class="tour-muted mb-2">Speak with our tour dispatch team for custom multi-day advice.</p>
                            <div class="d-flex flex-wrap gap-3">
                                @if (!empty($supportPhone))
                                    <a class="tour-assistance-link" href="tel:{{ $supportPhoneHref }}">Call {{ $supportPhone }}</a>
                                @endif
                                @if (!empty($supportWhatsapp))
                                    <a class="tour-assistance-link" href="https://wa.me/{{ $supportWhatsapp }}" target="_blank" rel="noopener noreferrer">WhatsApp Desk</a>
                                @endif
                            </div>
                        </div>
                    @endif
                </aside>
            </form>
        </main>

        <!-- FAQ Section (Native <details> Accordion) -->
        <section class="tour-faq-section py-5" aria-labelledby="tour-faq-heading">
            <div class="container" style="max-width: 54rem;">
                <div class="text-center mb-4">
                    <h2 id="tour-faq-heading" class="h3 fw-bold mb-1">Frequently Asked Questions</h2>
                    <p class="small tour-muted mb-0">Clear answers about planning your multi-day private tour.</p>
                </div>

                <div class="d-flex flex-column gap-3">
                    <details class="tour-faq-item">
                        <summary>How does the route comparison work?</summary>
                        <div class="tour-faq-body">
                            When you select your destinations and activities, our planner evaluates your chosen order alongside a practical route that minimizes backtracking while respecting activity opening hours. You can choose either route before generating your quote.
                        </div>
                    </details>
                    <details class="tour-faq-item">
                        <summary>Are activity tickets or entry fees included in the transport quote?</summary>
                        <div class="tour-faq-body">
                            No. The quote covers your private vehicle and driver transport for the selected itinerary. Attraction tickets, safari jeeps, train tickets, and entry fees are arranged separately.
                        </div>
                    </details>
                    <details class="tour-faq-item">
                        <summary>Can I request a staff review if my schedule has timing conflicts?</summary>
                        <div class="tour-faq-body">
                            Yes. If a route needs manual verification or custom vehicle availability, you can click <strong>Ask our team to review this Tour</strong> after building your route. No payment is taken for a review request.
                        </div>
                    </details>
                    <details class="tour-faq-item">
                        <summary>How do I proceed to booking once I see my price?</summary>
                        <div class="tour-faq-body">
                            After selecting your preferred route, review the transport price and booking terms, then click <strong>Accept price and continue to checkout</strong> to enter the shared checkout flow.
                        </div>
                    </details>
                </div>
            </div>
        </section>

        <!-- Mobile Sticky Quote Summary -->
        <div id="tour-mobile-summary"
            class="tour-mobile-summary d-lg-none position-fixed bottom-0 start-0 end-0 bg-white border-top shadow p-2"
            hidden>
            <div class="container d-flex align-items-center justify-content-between gap-3">
                <div>
                    <span class="d-block small tour-muted">Estimated transport</span>
                    <strong id="tour-mobile-total"></strong>
                </div>
                <button id="tour-mobile-review" class="btn tour-btn-primary btn-sm" type="button">Review quote</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.getElementById('tour-form');
            const loading = document.getElementById('tour-loading');
            const error = document.getElementById('tour-error');
            const serviceSelect = document.getElementById('tour-service');
            const classSelect = document.getElementById('tour-class');
            const packageSelect = document.getElementById('tour-package');
            const startSelect = document.getElementById('tour-start');
            const daySettings = document.getElementById('tour-day-settings');
            const destinationList = document.getElementById('tour-destinations');
            const results = document.getElementById('tour-results');
            const api = '/api/public/tours';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let services = [];
            let catalogue = null;
            let savedItinerary = null;
            let selectedTemplateDays = [];

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
            const showError = (message) => { error.textContent = message; error.hidden = false; error.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); };
            const clearError = () => { error.textContent = ''; error.hidden = true; };
            function invalidateDisplayedQuote() {
                results.hidden = true;
                document.getElementById('tour-total-summary').hidden = true;
                document.getElementById('tour-mobile-summary').hidden = true;
            }
            function showQuoteSummary(quote) {
                if (quote.total_amount === null || quote.total_amount === undefined || !Number.isFinite(Number(quote.total_amount))) return;
                const formatted = `${quote.currency} ${Number(quote.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                document.getElementById('tour-total-amount').textContent = formatted;
                document.getElementById('tour-total-distance').textContent = `${quote.distance_km} km estimated · Price held until ${new Date(quote.expires_at).toLocaleString()}`;
                document.getElementById('tour-mobile-total').textContent = formatted;
                document.getElementById('tour-total-summary').hidden = false;
                document.getElementById('tour-mobile-summary').hidden = false;
            }
            async function request(url, options = {}) {
                const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) } });
                const body = await response.json().catch(() => ({}));
                if (!response.ok || body.success === false) {
                    const messages = body.errors ? Object.values(body.errors).flat().join(' ') : body.message;
                    throw new Error(messages || 'We could not load tour information. Please try again.');
                }
                return body.data;
            }
            function currentService() { return services.find(item => item.id === serviceSelect.value); }
            function fillServices() {
                serviceSelect.innerHTML = services.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
            }
            function fillOptions() {
                const service = currentService();
                classSelect.innerHTML = '<option value="">Choose a vehicle class</option>' + (service?.classes || []).map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.display_name || item.name)}${item.max_passengers ? ` — up to ${escapeHtml(item.max_passengers)} passengers` : ''}</option>`).join('');
                packageSelect.innerHTML = '<option value="">Choose later</option>' + (service?.packages || []).map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
                document.getElementById('tour-ai-helper').hidden = !service?.ai_itinerary_helper_enabled;
                document.getElementById('tour-ai-preview').hidden = true;
                document.getElementById('tour-ai-status').textContent = '';
                renderClassCards();
                renderCustomFields();
            }
            function renderCustomFields() {
                const fields = currentService()?.form_fields || [];
                document.getElementById('tour-custom-fields').innerHTML = fields.map(field => {
                    const id = `tour-custom-${field.name}`; const required = field.required ? 'required' : ''; const label = escapeHtml(field.label);
                    let control = '';
                    if (field.type === 'textarea') control = `<textarea class="form-control" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required} placeholder="${escapeHtml(field.placeholder || '')}"></textarea>`;
                    else if (field.type === 'select') control = `<select class="form-select" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required}><option value="">Choose</option>${(field.options || []).map(option => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`).join('')}</select>`;
                    else if (field.type === 'radio') control = `<div>${(field.options || []).map((option, index) => `<label class="form-check me-3"><input class="form-check-input" type="radio" id="${escapeHtml(id)}-${index}" name="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" value="${escapeHtml(option.value)}" ${required && index === 0 ? 'required' : ''}><span class="form-check-label">${escapeHtml(option.label)}</span></label>`).join('')}</div>`;
                    else if (field.type === 'checkbox') control = `<label class="form-check mt-2"><input class="form-check-input" type="checkbox" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required}><span class="form-check-label">${label}</span></label>`;
                    else control = `<input class="form-control" type="${['number', 'date', 'time'].includes(field.type) ? field.type : 'text'}" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required} placeholder="${escapeHtml(field.placeholder || '')}" ${field.type === 'number' && field.validation?.min != null ? `min="${escapeHtml(field.validation.min)}"` : ''} ${field.type === 'number' && field.validation?.max != null ? `max="${escapeHtml(field.validation.max)}"` : ''}>`;
                    const caption = field.type === 'radio' ? `<div class="form-label">${label}${field.required ? ' *' : ''}</div>` : `<label class="form-label" for="${escapeHtml(id)}">${label}${field.required ? ' *' : ''}</label>`;
                    return `<div class="col-md-6">${caption}${control}${field.hint ? `<div class="form-text">${escapeHtml(field.hint)}</div>` : ''}</div>`;
                }).join('');
            }
            function limitPackageClasses() {
                const chosen = (currentService()?.packages || []).find(item => item.id === packageSelect.value);
                const allowed = chosen ? new Set([chosen.default_vehicle_class_id, ...(chosen.allowed_upgrade_class_ids || [])].filter(Boolean)) : null;
                [...classSelect.options].forEach(option => { option.disabled = !!allowed && option.value !== '' && !allowed.has(option.value); });
                if (allowed && !allowed.has(classSelect.value)) classSelect.value = chosen.default_vehicle_class_id || '';
                renderClassCards();
            }
            function renderClassCards() {
                const chosen = (currentService()?.packages || []).find(item => item.id === packageSelect.value);
                const allowed = chosen ? new Set([chosen.default_vehicle_class_id, ...(chosen.allowed_upgrade_class_ids || [])].filter(Boolean)) : null;
                const partySize = Number(document.getElementById('tour-adults').value || 0) + Number(document.getElementById('tour-children').value || 0);
                const luggage = Number(document.getElementById('tour-luggage').value || 0);
                const cards = document.getElementById('tour-class-cards');
                cards.innerHTML = (currentService()?.classes || []).map((item, index) => {
                    const packageAllowed = !allowed || allowed.has(item.id);
                    const capacityFits = partySize > 0 && partySize <= Number(item.max_passengers) && luggage <= Number(item.max_luggage);
                    const disabled = !packageAllowed || !capacityFits;
                    if (classSelect.value === item.id && disabled) classSelect.value = '';
                    const selected = classSelect.value === item.id;
                    const reason = !packageAllowed ? 'Not offered with this package' : !capacityFits ? 'Does not fit the selected travellers or luggage' : '';
                    return `<div class="col-md-6"><label class="tour-option d-flex flex-column position-relative ${disabled ? 'opacity-50' : ''}" for="tour-class-choice-${index}"><input class="form-check-input position-absolute top-0 end-0 m-3 tour-class-choice" type="radio" name="tour_class_choice" id="tour-class-choice-${index}" value="${escapeHtml(item.id)}" ${selected ? 'checked' : ''} ${disabled ? 'disabled' : ''} required><div class="pe-4"><strong class="d-block text-dark">${escapeHtml(item.display_name || item.name)}</strong>${item.description ? `<span class="d-block small tour-muted mt-1">${escapeHtml(item.description)}</span>` : ''}</div><div class="small tour-muted mt-auto pt-3 d-flex flex-wrap gap-3"><span>Up to ${escapeHtml(item.max_passengers)} travellers</span><span>${escapeHtml(item.max_luggage)} luggage items</span></div>${item.is_inquiry_only ? '<span class="d-block small text-warning fw-semibold mt-2">Availability confirmed by our team</span>' : ''}${reason ? `<span class="d-block small text-danger mt-2">${escapeHtml(reason)}</span>` : ''}</label></div>`;
                }).join('');
                cards.querySelectorAll('.tour-class-choice').forEach(input => input.addEventListener('change', () => {
                    if (input.checked) { classSelect.value = input.value; results.hidden = true; }
                }));
            }
            function applyPackageTemplate() {
                const packageData = (currentService()?.packages || []).find(item => item.id === packageSelect.value);
                const days = packageData?.tour_itinerary_template?.days;
                selectedTemplateDays = Array.isArray(days) ? days : [];
                if (!selectedTemplateDays.length) return;
                document.getElementById('tour-days').value = days.length;
                const count = days.length;
                document.querySelectorAll('.tour-place-day').forEach(select => {
                    const current = Number(select.value) || 1;
                    select.innerHTML = Array.from({ length: count }, (_, day) => `<option value="${day + 1}" ${current === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('');
                });
                document.querySelectorAll('.tour-activity-day').forEach(select => {
                    const current = Number(select.value) || 1;
                    select.innerHTML = Array.from({ length: count }, (_, day) => `<option value="${day + 1}" ${current === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('');
                });
                const firstDay = [...days].sort((a, b) => a.day_number - b.day_number)[0];
                startSelect.value = firstDay.start_destination_id;
                renderDaySettings();
                for (const day of days) {
                    const end = document.getElementById(`tour-day-end-${day.day_number}`);
                    if (end) end.value = day.end_destination_id;
                }
                document.querySelectorAll('.tour-destination,.tour-activity').forEach(input => { input.checked = false; });
                for (const day of days) {
                    for (const stop of day.stops || []) {
                        const selector = stop.kind === 'destination' ? '.tour-destination' : '.tour-activity';
                        const input = [...document.querySelectorAll(selector)].find(item => item.value === stop.catalogue_id);
                        if (input) {
                            input.checked = true;
                            if (stop.kind === 'destination') {
                                const daySelect = document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`);
                                if (daySelect) daySelect.value = String(day.day_number);
                            }
                            if (stop.kind === 'activity') {
                                const activityDay = document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(stop.catalogue_id)}"]`);
                                if (activityDay) activityDay.value = String(day.day_number);
                            }
                        }
                    }
                }
                limitPackageClasses();
                results.hidden = true;
            }
            function renderDaySettings() {
                if (!catalogue) return;
                const destinations = catalogue.destinations || [];
                const days = Number(document.getElementById('tour-days').value) || 1;
                const priorEnds = new Map([...daySettings.querySelectorAll('.tour-day-end')].map(select => [Number(select.dataset.day), select.value]));
                daySettings.innerHTML = Array.from({ length: Math.max(1, Math.min(days, 60)) }, (_, index) => {
                    const day = index + 1;
                    const selectedEnd = priorEnds.get(day) || startSelect.value;
                    const label = day === days ? `Day ${day} finish location` : `Day ${day} overnight location`;
                    const help = day === days ? 'Choose where the tour ends. Return to start is selected by default.' : `Day ${day + 1} starts from this location.`;
                    return `<div class="col-md-6"><div class="tour-day-card h-100"><div class="d-flex align-items-center justify-content-between mb-2"><span class="tour-day-badge">Day ${day}</span><span class="small tour-muted">${day === days ? 'Final Stop' : 'Overnight'}</span></div><search class="d-block mb-2"><label class="form-label" for="tour-day-end-search-${day}">Filter places</label><input id="tour-day-end-search-${day}" type="search" class="form-control form-control-sm" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" placeholder="Search listed places…"></search><label class="form-label" for="tour-day-end-${day}">${label} *</label><select id="tour-day-end-${day}" class="form-select form-select-sm tour-day-end" data-day="${day}" required><option value="${escapeHtml(startSelect.value)}" ${selectedEnd === startSelect.value ? 'selected' : ''}>Return to start</option>${destinations.map(item => `<option value="${escapeHtml(item.id)}" ${selectedEnd === item.id ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}</select><div class="form-text">${help}</div></div></div>`;
                }).join('');
                daySettings.querySelectorAll('input[type="search"]').forEach(input => bindLocationSelectSearch(input, document.getElementById(input.id.replace('-search', ''))));
            }
            function bindLocationSelectSearch(input, select) {
                if (!input || !select) return;
                if (input.dataset.searchBound === 'true') return;
                input.dataset.searchBound = 'true';
                input.value = '';
                input.addEventListener('input', () => {
                    const query = input.value.trim().toLocaleLowerCase();
                    [...select.options].forEach(option => {
                        option.hidden = Boolean(query && option.value !== select.value && !option.textContent.toLocaleLowerCase().includes(query));
                    });
                });
            }
            async function loadCatalogue() {
                const sameService = catalogue?.service_type_id === serviceSelect.value;
                const previousStart = sameService ? startSelect.value : '';
                const previousDestinations = new Set(sameService ? [...document.querySelectorAll('.tour-destination:checked')].map(box => box.value) : []);
                const previousActivities = new Set(sameService ? [...document.querySelectorAll('.tour-activity:checked')].map(box => box.value) : []);
                const previousDays = new Map(sameService ? [...document.querySelectorAll('.tour-place-day')].map(select => [select.id.replace('tour-place-day-', ''), select.value]) : []);
                const previousActivityDays = new Map(sameService ? [...document.querySelectorAll('.tour-activity-day')].map(select => [select.dataset.activity, select.value]) : []);
                clearError(); results.hidden = true; destinationList.innerHTML = ''; daySettings.innerHTML = ''; startSelect.innerHTML = '<option value="">Choose a listed place</option>'; document.getElementById('tour-start-search').value = ''; document.getElementById('tour-destination-search').value = '';
                const data = await request(`${api}/catalogue?service_type_id=${encodeURIComponent(serviceSelect.value)}`);
                catalogue = data;
                const destinations = data.destinations || [];
                startSelect.innerHTML += destinations.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
                bindLocationSelectSearch(document.getElementById('tour-start-search'), startSelect);
                if (destinations.some(item => item.id === previousStart)) startSelect.value = previousStart;
                destinationList.innerHTML = destinations.map((item, index) => `
                <div class="col-md-6"><div class="tour-option d-flex flex-column">
                    <label class="form-check-label d-flex gap-2 align-items-start" style="cursor: pointer;"><input class="form-check-input mt-1 tour-destination" type="checkbox" value="${escapeHtml(item.id)}" id="tour-destination-${index}" ${previousDestinations.has(item.id) ? 'checked' : ''}><span><strong>Visit ${escapeHtml(item.name)}</strong><br><span class="small tour-muted">${escapeHtml(item.description || item.location?.city || item.location?.address || '')}</span></span></label>
                    <div class="mt-2"><label class="form-label mb-1" for="tour-place-day-${index}">Destination visit on</label><select class="form-select form-select-sm tour-place-day" id="tour-place-day-${index}">${Array.from({ length: Number(document.getElementById('tour-days').value) || 1 }, (_, day) => `<option value="${day + 1}" ${Number(previousDays.get(String(index))) === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('')}</select></div>
                    ${(item.activities || []).length ? `<div class="border-top mt-3 pt-3"><p class="small fw-bold text-uppercase tour-muted mb-2" style="letter-spacing: 0.04em;">Activities at this place</p>${item.activities.map((activity, activityIndex) => `<div class="mb-2 pb-2 border-bottom border-light"><label class="form-check small mb-1" style="cursor: pointer;"><input class="form-check-input tour-activity" type="checkbox" value="${escapeHtml(activity.id)}" id="tour-activity-${index}-${activityIndex}" ${previousActivities.has(activity.id) ? 'checked' : ''}><span class="form-check-label"><strong>${escapeHtml(activity.name)}</strong>${activity.visit_duration_minutes ? ` <span class="tour-muted">(${escapeHtml(activity.visit_duration_minutes)} min)</span>` : ''}<br><span class="tour-muted">Route visit: ${escapeHtml(activity.location?.name || activity.location?.address || 'mapped entrance')}</span>${activity.description ? `<br><span class="tour-muted">${escapeHtml(activity.description)}</span>` : ''}</span></label><label class="small tour-muted ms-4 d-block mb-1" for="tour-activity-day-${index}-${activityIndex}">Activity visit on</label><select class="form-select form-select-sm tour-activity-day ms-4" style="width: calc(100% - 1.5rem);" id="tour-activity-day-${index}-${activityIndex}" data-activity="${escapeHtml(activity.id)}">${Array.from({ length: Number(document.getElementById('tour-days').value) || 1 }, (_, day) => `<option value="${day + 1}" ${Number(previousActivityDays.get(activity.id) ?? previousDays.get(String(index))) === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('')}</select></div>`).join('')}</div>` : ''}
                </div></div>`).join('');
                filterDestinationCards();
                renderDaySettings();
            }
            function filterDestinationCards() {
                const query = document.getElementById('tour-destination-search').value.trim().toLocaleLowerCase();
                destinationList.querySelectorAll(':scope > .col-md-6').forEach(card => { card.hidden = Boolean(query && !card.textContent.toLocaleLowerCase().includes(query)); });
            }
            function renderAiSuggestion(suggestion) {
                const destinations = new Map((catalogue?.destinations || []).map(item => [item.id, item]));
                const activities = new Map((catalogue?.destinations || []).flatMap(item => (item.activities || []).map(activity => [activity.id, { ...activity, destinationName: item.name }])));
                document.getElementById('tour-ai-preview').innerHTML = `<p class="small tour-muted mb-2">Review the suggested visits, then choose whether to add them. You can edit every selection afterward.</p>${suggestion.days.map(day => `<div class="mb-2"><strong>Day ${escapeHtml(day.day_number)}</strong><ul class="small mb-0">${day.stops.map(stop => { const place = stop.kind === 'activity' ? activities.get(stop.catalogue_id) : destinations.get(stop.catalogue_id); return `<li>${escapeHtml(place?.name || 'Published place')}${stop.kind === 'activity' && place?.destinationName ? ` <span class="tour-muted">at ${escapeHtml(place.destinationName)}</span>` : ''}</li>`; }).join('') || '<li>Choose visits manually for this day.</li>'}</ul></div>`).join('')}<div class="d-flex flex-wrap gap-2 mt-3"><button id="tour-ai-apply" class="btn tour-btn-primary btn-sm" type="button">Add these ideas to my itinerary</button><button id="tour-ai-discard" class="btn btn-outline-secondary btn-sm" type="button">Discard ideas</button></div>`;
                const preview = document.getElementById('tour-ai-preview');
                preview.hidden = false;
                document.getElementById('tour-ai-apply').addEventListener('click', () => {
                    document.querySelectorAll('.tour-destination,.tour-activity').forEach(input => { input.checked = false; });
                    for (const day of suggestion.days) {
                        for (const stop of day.stops) {
                            const activity = stop.kind === 'activity'
                                ? [...document.querySelectorAll('.tour-activity')].find(input => input.value === stop.catalogue_id)
                                : null;
                            const destinationId = stop.kind === 'destination' ? stop.catalogue_id : null;
                            const destination = destinationId
                                ? [...document.querySelectorAll('.tour-destination')].find(input => input.value === destinationId)
                                : null;
                            if (destination) {
                                destination.checked = true;
                                const index = destination.id.replace('tour-destination-', '');
                                const daySelect = document.getElementById(`tour-place-day-${index}`);
                                if (daySelect) daySelect.value = String(day.day_number);
                            }
                            if (activity) {
                                activity.checked = true;
                                const daySelect = document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(activity.value)}"]`);
                                if (daySelect) daySelect.value = String(day.day_number);
                            }
                        }
                    }
                    selectedTemplateDays = [];
                    invalidateDisplayedQuote();
                    preview.hidden = true;
                    document.getElementById('tour-ai-status').textContent = 'Ideas added. You can edit the places and days before building your route.';
                });
                document.getElementById('tour-ai-discard').addEventListener('click', () => {
                    preview.hidden = true;
                    document.getElementById('tour-ai-status').textContent = 'Ideas discarded. You can continue planning manually.';
                });
            }
            function clearAiSuggestion() {
                const preview = document.getElementById('tour-ai-preview');
                if (!preview.hidden) {
                    preview.hidden = true;
                    document.getElementById('tour-ai-status').textContent = 'Trip details changed. Request fresh ideas if you still want suggestions.';
                }
            }
            function itineraryDays() {
                const destinations = new Map((catalogue?.destinations || []).map(item => [item.id, item]));
                const start = destinations.get(startSelect.value);
                if (!start) throw new Error('Choose a start and finish location.');
                const count = Number(document.getElementById('tour-days').value);
                if (!Number.isInteger(count) || count < 1 || count > 60) throw new Error('Choose between 1 and 60 trip days.');
                const selected = [...document.querySelectorAll('.tour-destination:checked')];
                const selectedActivities = [...document.querySelectorAll('.tour-activity:checked')];
                if (!selected.length && !selectedActivities.length) throw new Error('Choose at least one destination or activity to visit.');
                const endByDay = new Map();
                document.querySelectorAll('.tour-day-end').forEach(select => endByDay.set(Number(select.dataset.day), select.value));
                return Array.from({ length: count }, (_, index) => {
                    const day = index + 1;
                    const templateDay = selectedTemplateDays.find(item => Number(item.day_number) === day);
                    const startId = day === 1 ? start.id : endByDay.get(day - 1);
                    const endId = endByDay.get(day);
                    if (!startId || !endId) throw new Error('Choose an overnight location for each day.');
                    const stops = [];
                    const templateStop = (kind, id) => templateDay?.stops?.find(item => item.kind === kind && item.catalogue_id === id) || {};
                    selected.filter(box => Number(document.getElementById(box.id.replace('tour-destination-', 'tour-place-day-')).value) === day).forEach(box => {
                        const destination = destinations.get(box.value);
                        if (destination) { const preset = templateStop('destination', destination.id); stops.push({ kind: 'destination', catalogue_id: destination.id, is_locked: !!preset.is_locked, arrival_time: preset.arrival_time || null }); }
                    });
                    [...document.querySelectorAll('.tour-activity:checked')].filter(activity => Number(document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(activity.value)}"]`)?.value) === day).forEach(activity => { const preset = templateStop('activity', activity.value); stops.push({ kind: 'activity', catalogue_id: activity.value, is_locked: !!preset.is_locked, arrival_time: preset.arrival_time || null }); });
                    const startPlace = destinations.get(startId);
                    return { day_number: day, timezone: templateDay?.timezone || startPlace?.timezone || 'Asia/Colombo', start_time: templateDay?.start_time || document.getElementById('tour-time').value, start_destination_id: startId, end_destination_id: endId, overnight_destination_id: day < count ? endId : null, is_locked: !!templateDay?.is_locked, stops };
                });
            }
            function renderRoute(data) {
                const unavailable = data.status !== 'ready';
                const routeMessage = data.status === 'unavailable'
                    ? 'We could not verify every road route. You can save this plan and ask our team to review it.'
                    : data.status === 'review_required'
                        ? 'This schedule has timing conflicts. Ask our team to review it before a transport price can be confirmed.'
                        : data.changed
                            ? 'Compare the original order with the suggested order for every day. Every selected destination and activity stays in the plan.'
                            : 'Your selected order is already the best route we found.';
                const renderAggregate = (route, label) => {
                    const canQuote = route.quote?.status === 'priced' && !unavailable;
                    const disabledLabel = route.quote?.status === 'quotation_required' || unavailable ? 'Staff review required' : 'Price needs staff review';
                    return `<div class="col-md-6"><div class="tour-option d-flex flex-column h-100"><h3 class="h6 fw-bold mb-2">${escapeHtml(label)}</h3><p class="small mb-2"><strong>${route.distance_km == null ? 'Distance unavailable' : `${escapeHtml(route.distance_km)} km total`}</strong>${route.duration_seconds != null ? ` · ${Math.round(route.duration_seconds / 3600 * 10) / 10} hr driving` : ''}</p>${route.quote?.status === 'priced' ? `<p class="h5 fw-bold mb-3" style="color: var(--tour-primary);">${escapeHtml(route.quote.currency)} ${Number(route.quote.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>` : `<p class="small text-warning fw-semibold mb-3">${escapeHtml(route.quote?.reason || 'Price needs staff review.')}</p>`}<div class="mb-3">${(route.days || []).map(day => `<div class="tour-route-stop small"><span class="fw-bold">Day ${day.day_number}:</span> ${escapeHtml((day.schedule || []).map(stop => stop.stop_name).join(' → '))}</div>`).join('')}</div><button type="button" class="btn tour-btn-outline mt-auto tour-use-route" data-route="${label === 'Suggested route' ? 'suggested' : 'original'}" ${canQuote ? '' : 'disabled'}>${canQuote ? 'Use this route and review price' : disabledLabel}</button></div></div>`;
                };
                return `<div class="alert ${unavailable ? 'alert-warning' : 'alert-info'}">${routeMessage}</div><div class="row g-3">${renderAggregate(data.original, 'Your selected order')}${renderAggregate(data.suggested, 'Suggested route')}</div><div class="small tour-muted mt-3">${(data.issues || []).map(issue => `<p class="mb-1">Day ${issue.day_number}: ${escapeHtml(issue.message)}</p>`).join('')}<p class="mb-0">Activity visits are included in your route only. Tickets, entry fees and activity reservations are not included. Please arrange these separately. Activity booking will be introduced in a future release.</p></div><div class="mt-3"><button type="button" id="tour-request-review" class="btn tour-btn-outline">Ask our team to review this Tour</button><p class="small tour-muted mt-2 mb-0">This sends your itinerary for follow-up only. It does not book a vehicle or take payment.</p></div>`;
            }
            form.addEventListener('submit', async event => {
                event.preventDefault(); clearError(); invalidateDisplayedQuote();
                const button = document.getElementById('tour-plan'); button.disabled = true; document.getElementById('tour-working').hidden = false;
                const busyControls = [...form.querySelectorAll('input,select,textarea,button')].filter(control => control !== button);
                const priorDisabled = busyControls.map(control => control.disabled);
                busyControls.forEach(control => { control.disabled = true; });
                try {
                    const payload = {
                        first_name: document.getElementById('tour-first-name').value,
                        last_name: document.getElementById('tour-last-name').value,
                        email: document.getElementById('tour-email').value,
                        phone: document.getElementById('tour-phone').value,
                        service_type_id: serviceSelect.value,
                        vehicle_class_id: classSelect.value,
                        service_package_id: packageSelect.value || null,
                        start_date: document.getElementById('tour-date').value,
                        duration_days: Number(document.getElementById('tour-days').value),
                        adult_count: Number(document.getElementById('tour-adults').value),
                        child_count: Number(document.getElementById('tour-children').value),
                        child_seat_count: Number(document.getElementById('tour-child-seats').value),
                        luggage_count: Number(document.getElementById('tour-luggage').value),
                        days: itineraryDays(),
                        custom_answers: Object.fromEntries([...document.querySelectorAll('[data-custom-name]')].reduce((entries, input) => { if (input.type === 'radio') { if (input.checked) entries.push([input.dataset.customName, input.value]); } else entries.push([input.dataset.customName, input.type === 'checkbox' ? input.checked : input.value]); return entries; }, [])),
                    };
                    if (!payload.vehicle_class_id) throw new Error('Choose a vehicle class.');
                    const created = await request('/tours/drafts', { method: 'POST', body: JSON.stringify(payload) });
                    savedItinerary = created.itinerary;
                    const routes = created.route_options;
                    results.innerHTML = renderRoute(routes) + `<div id="tour-quote" class="mt-4"></div>`;
                    results.hidden = false;
                    document.getElementById('tour-request-review')?.addEventListener('click', async event => {
                        const requestButton = event.currentTarget;
                        requestButton.disabled = true;
                        try {
                            const review = await request(`/tours/drafts/${savedItinerary.id}/request-review`, { method: 'POST', body: JSON.stringify({}) });
                            window.location.assign(review.confirmation_url);
                        } catch (exception) {
                            showError(exception.message || 'We could not send your Tour for staff review. Please try again.');
                            requestButton.disabled = false;
                        }
                    });
                    results.querySelectorAll('.tour-use-route').forEach(button => button.addEventListener('click', async () => {
                        let quoteReady = false;
                        button.disabled = true;
                        results.querySelectorAll('.tour-use-route').forEach(option => { option.disabled = true; });
                        try {
                            if (button.dataset.route === 'suggested' && routes.changed) {
                                const routeDays = (routes.days || []).map(day => ({ day_number: day.day_number, stop_ids: day.suggested_stop_ids || [] }));
                                const applied = await request(`/tours/drafts/${savedItinerary.id}/route`, { method: 'POST', body: JSON.stringify({ expected_revision: savedItinerary.revision, days: routeDays }) });
                                savedItinerary = applied;
                            }
                            const quote = await request(`/tours/drafts/${savedItinerary.id}/quote`, { method: 'POST', body: JSON.stringify({ expected_revision: savedItinerary.revision }) });
                            const q = quote;
                            const amount = Number(q.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            showQuoteSummary(q);
                            document.getElementById('tour-quote').innerHTML = `<div class="alert alert-success border"><h3 class="h5 fw-bold">Transport price for your selected route</h3><p class="h4 fw-bold">${escapeHtml(q.currency)} ${amount}</p><p class="small">Estimated route distance: ${escapeHtml(q.distance_km)} km. The price is held until ${escapeHtml(new Date(q.expires_at).toLocaleString())}. Vehicle availability is checked when you submit your booking through checkout.</p>${(q.terms_snapshot || []).map(term => `<div class="form-check mb-2"><input class="form-check-input tour-term" type="checkbox" value="${escapeHtml(term.id)}" id="tour-term-${escapeHtml(term.id)}"><label class="form-check-label" for="tour-term-${escapeHtml(term.id)}"><strong>${escapeHtml(term.title || 'Booking terms')}</strong> (${escapeHtml(term.version || 'current')}) — I agree</label><div class="small tour-muted ms-4" style="white-space:pre-wrap;line-height:1.55">${escapeHtml(term.content)}</div></div>`).join('')}<button class="btn tour-btn-primary mt-3" id="tour-accept-quote" type="button">Accept price and continue to checkout</button></div>`;
                            quoteReady = true;
                            document.getElementById('tour-accept-quote').addEventListener('click', async () => {
                                const terms = document.querySelectorAll('.tour-term');
                                if ([...terms].some(item => !item.checked)) { showError('Please accept each required booking term to continue.'); return; }
                                const acceptButton = document.getElementById('tour-accept-quote'); acceptButton.disabled = true;
                                try {
                                    await request(`/tours/drafts/${savedItinerary.id}/quotes/${q.id}/accept`, { method: 'POST', body: JSON.stringify({ expected_revision: savedItinerary.revision, accept_terms: true }) });
                                    const checkout = await request(`/tours/drafts/${savedItinerary.id}/booking`, { method: 'POST', body: JSON.stringify({ quote_id: q.id, expected_revision: savedItinerary.revision }) });
                                    window.location.assign(checkout.checkout_url);
                                } catch (exception) { showError(exception.message || 'We could not submit the Tour booking. Your accepted quote is saved so you can try again.'); acceptButton.disabled = false; }
                            });
                        } catch (exception) {
                            showError(exception.message || 'We could not prepare the quote. Please review your route and try again.');
                        } finally { results.querySelectorAll('.tour-use-route').forEach(option => { option.disabled = quoteReady; }); }
                    }));
                } catch (exception) { showError(exception.message || 'We could not plan this route. Please check your choices and try again.'); }
                finally { busyControls.forEach((control, index) => { control.disabled = priorDisabled[index]; }); button.disabled = false; document.getElementById('tour-working').hidden = true; }
            });
            serviceSelect.addEventListener('change', async () => { selectedTemplateDays = []; fillOptions(); try { await loadCatalogue(); } catch (exception) { showError(exception.message); } });
            packageSelect.addEventListener('change', () => { clearAiSuggestion(); limitPackageClasses(); applyPackageTemplate(); });
            document.getElementById('tour-destination-search').addEventListener('input', filterDestinationCards);
            form.addEventListener('input', event => {
                if (results.contains(event.target) || event.target.closest('#tour-ai-helper') || event.target.type === 'search') return;
                invalidateDisplayedQuote();
            });
            form.addEventListener('change', event => {
                if (results.contains(event.target) || event.target.closest('#tour-ai-helper') || event.target.type === 'search') return;
                invalidateDisplayedQuote();
            });
            ['tour-adults', 'tour-children', 'tour-luggage'].forEach(id => document.getElementById(id).addEventListener('input', () => { renderClassCards(); results.hidden = true; }));
            document.getElementById('tour-date').addEventListener('change', clearAiSuggestion);
            document.getElementById('tour-interests').addEventListener('input', clearAiSuggestion);
            document.getElementById('tour-days').addEventListener('change', () => {
                clearAiSuggestion();
                const count = Math.max(1, Math.min(60, Number(document.getElementById('tour-days').value) || 1));
                document.querySelectorAll('.tour-place-day').forEach(select => {
                    const selected = Math.min(count, Number(select.value) || 1);
                    select.innerHTML = Array.from({ length: count }, (_, day) => `<option value="${day + 1}" ${selected === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('');
                });
                document.querySelectorAll('.tour-activity-day').forEach(select => {
                    const selected = Math.min(count, Number(select.value) || 1);
                    select.innerHTML = Array.from({ length: count }, (_, day) => `<option value="${day + 1}" ${selected === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('');
                });
                renderDaySettings(); invalidateDisplayedQuote();
            });
            startSelect.addEventListener('change', renderDaySettings);
            document.getElementById('tour-send-code').addEventListener('click', async () => {
                const email = document.getElementById('tour-email').value.trim();
                if (!email) { showError('Enter your email first.'); return; }
                const status = document.getElementById('tour-code-status');
                try { const response = await request('/checkout/customer-email/send-code', { method: 'POST', body: JSON.stringify({ channel: 'email', value: email }) }); status.textContent = response.message || 'If these details match a previous booking, check your email for a code.'; document.getElementById('tour-code-panel').hidden = false; }
                catch (exception) { status.textContent = exception.message; }
            });
            document.getElementById('tour-verify-code').addEventListener('click', async () => {
                const status = document.getElementById('tour-code-status');
                try { const customer = await request('/checkout/customer-email/verify-code', { method: 'POST', body: JSON.stringify({ channel: 'email', value: document.getElementById('tour-email').value.trim(), code: document.getElementById('tour-code').value.trim() }) }); for (const [field, id] of [['first_name', 'tour-first-name'], ['last_name', 'tour-last-name'], ['email', 'tour-email'], ['phone', 'tour-phone']]) if (customer[field]) document.getElementById(id).value = customer[field]; status.textContent = 'Your details are verified.'; }
                catch (exception) { status.textContent = exception.message; }
            });
            document.getElementById('tour-ai-suggest').addEventListener('click', async event => {
                const button = event.currentTarget;
                const status = document.getElementById('tour-ai-status');
                const requestData = {
                    service_type_id: serviceSelect.value,
                    duration_days: Number(document.getElementById('tour-days').value),
                    start_date: document.getElementById('tour-date').value || null,
                    interests: document.getElementById('tour-interests').value.trim(),
                };
                if (requestData.duration_days > 14) {
                    status.textContent = 'AI ideas support trips up to 14 days. You can still plan longer trips manually.';
                    return;
                }
                const requestKey = JSON.stringify(requestData);
                button.disabled = true;
                status.textContent = 'Preparing ideas from published places…';
                try {
                    const response = await request('/api/public/tours/suggestions', { method: 'POST', body: JSON.stringify(requestData) });
                    if (serviceSelect.value !== requestData.service_type_id
                        || JSON.stringify({ service_type_id: serviceSelect.value, duration_days: Number(document.getElementById('tour-days').value), start_date: document.getElementById('tour-date').value || null, interests: document.getElementById('tour-interests').value.trim() }) !== requestKey) {
                        status.textContent = 'Trip details changed while ideas were loading. Request fresh ideas if you still want them.';
                        return;
                    }
                    renderAiSuggestion(response);
                    status.textContent = response.notice || 'Review the draft before adding it to your plan.';
                } catch (exception) {
                    status.textContent = exception.message || 'Suggestions are unavailable. You can continue planning manually.';
                } finally {
                    button.disabled = false;
                }
            });
            document.getElementById('tour-mobile-review').addEventListener('click', () => {
                document.getElementById('tour-quote')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                document.getElementById('tour-accept-quote')?.focus({ preventScroll: true });
            });

            const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1); document.getElementById('tour-date').min = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;
            request(`${api}/services`).then(data => {
                services = data.services || [];
                if (!services.length) throw new Error('Tours are not available at the moment.');
                fillServices(); fillOptions(); return loadCatalogue();
            }).then(() => { loading.hidden = true; form.hidden = false; }).catch(exception => { loading.hidden = true; showError(exception.message); });
        })();
    </script>
@endpush
