@extends('layouts.app')

@section('title', 'Plan your tour')

@push('styles')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css" rel="stylesheet">
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
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .tour-nav-pills {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            gap: 0.25rem;
            padding: 0.25rem;
            background: #f1f5f9;
            border: 1px solid var(--tour-border);
            border-radius: 0.625rem;
            max-width: 100%;
        }

        .tour-nav-pills::-webkit-scrollbar {
            display: none;
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
            white-space: nowrap;
            flex-shrink: 0;
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

        .tour-page .select2-container { width: 100% !important; font-size: 1rem; }
        .tour-page .select2-container--default .select2-selection--single,
        .tour-page .select2-container--default .select2-selection--multiple {
            min-height: 44px;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            background: #fff;
        }
        .tour-page .tour-day-card .select2-container--default .select2-selection--single { min-height: 38px; }
        .tour-page .select2-container--default .select2-selection--single .select2-selection__rendered { padding: 0.45rem 2rem 0.45rem 0.75rem; line-height: 1.5; color: var(--tour-ink); }
        .tour-page .tour-day-card .select2-container--default .select2-selection--single .select2-selection__rendered { padding-top: 0.35rem; padding-bottom: 0.35rem; }
        .tour-page .select2-container--default .select2-selection--single .select2-selection__arrow { height: 42px; }
        .tour-page .tour-day-card .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
        .tour-page .select2-container--default .select2-selection--multiple .select2-selection__rendered { display: flex; flex-wrap: wrap; gap: 0.25rem; padding: 0.25rem 0.5rem; }
        .tour-page .select2-container--default .select2-selection--multiple .select2-search--inline .select2-search__field { min-height: 28px; margin-top: 0.25rem; }
        .tour-page .select2-dropdown { border-color: #cbd5e1; }
        .tour-page .select2-container--default .select2-results__option--highlighted[aria-selected] { background: var(--tour-primary); }
        .tour-page .select2-container--focus .select2-selection { border-color: var(--tour-primary); box-shadow: 0 0 0 3px var(--tour-primary-ring); }

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
            clear: both;
            width: 100%;
        }

        #tour-day-settings,
        #tour-destinations {
            clear: both;
        }

        #tour-route-map {
            height: 410px;
            min-height: 240px;
            border: 1px solid #d7e0ea;
            border-radius: 12px;
            background: #f3f6f8;
            z-index: 0;
        }

        @media (max-width: 991.98px) {
            #tour-route-map {
                height: 260px;
            }
        }

        /* Stepper +/- Controls for Mobile-Friendly Counters */
        .tour-stepper {
            display: flex;
            align-items: stretch;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            background: #ffffff;
            overflow: hidden;
            min-height: 44px;
        }

        .tour-stepper-btn {
            width: 42px;
            min-height: 44px;
            border: 0;
            background: #f8fafc;
            color: var(--tour-ink);
            font-size: 1.125rem;
            font-weight: 700;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.12s ease, color 0.12s ease;
            flex-shrink: 0;
        }

        .tour-stepper-btn:hover {
            background: var(--tour-primary-soft);
            color: var(--tour-primary);
        }

        .tour-stepper .form-control {
            border: 0 !important;
            border-radius: 0 !important;
            text-align: center;
            font-weight: 700;
            padding-left: 0.25rem;
            padding-right: 0.25rem;
            min-width: 0;
            box-shadow: none !important;
            -moz-appearance: textfield;
        }

        .tour-stepper .form-control::-webkit-outer-spin-button,
        .tour-stepper .form-control::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
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

        .tour-class-option { padding: 0.75rem 0.875rem; border-radius: 0.7rem; }
        .tour-class-option .tour-class-meta { padding-top: 0.5rem; gap: 0.75rem !important; }
        .tour-class-estimate { max-width: 48%; text-align: right; line-height: 1.2; }
        .tour-class-estimate strong { display: block; color: var(--tour-primary); font-size: 0.95rem; }
        .tour-class-estimate small { display: block; font-size: 0.65rem; }
        .tour-page .iti { width: 100%; }
        .tour-itinerary-day { border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 0.85rem 0.95rem; background: #fff; transition: border-color 0.15s ease; }
        .tour-itinerary-day:hover { border-color: #cbd5e1; }
        .tour-itinerary-day + .tour-itinerary-day { margin-top: 0.65rem; }
        .tour-itinerary-route { font-size: 0.85rem; font-weight: 700; color: #14213d; }
        .tour-itinerary-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; font-size: 0.72rem; color: var(--tour-muted); }
        .tour-itinerary-warning { display: flex; width: fit-content; align-items: center; gap: 0.35rem; font-size: 0.72rem; font-weight: 600; color: #92400e; background: #fef3c7; border: 1px solid #fde68a; border-radius: 0.4rem; padding: 0.25rem 0.55rem; margin-top: 0.4rem; }
        .tour-text-action { background: none; border: 0; padding: 0; color: var(--tour-primary); font-size: 0.8rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem; cursor: pointer; }
        .tour-text-action:hover { text-decoration: underline; color: var(--tour-primary-dark); }
        .tour-itinerary-actions { color: var(--tour-primary); font-size: 0.76rem; font-weight: 600; background: var(--tour-primary-soft); border: 1px solid var(--tour-primary-border); border-radius: 999px; padding: 0.3rem 0.75rem; min-height: 34px; display: inline-flex; align-items: center; gap: 0.3rem; transition: background-color 0.15s ease, color 0.15s ease; }
        .tour-itinerary-actions:hover { background: var(--tour-primary); color: #fff; text-decoration: none; }
        .tour-itinerary-endpoint { font-size: 0.75rem; white-space: nowrap; }

        /* Edit Day Modal Redesign */
        #tour-day-editor .modal-content { border: 0; border-radius: 0.95rem; overflow: hidden; }
        #tour-day-editor .modal-header { padding: 0.9rem 1.25rem; border-bottom: 1px solid var(--tour-border); background: #fff; }
        #tour-day-editor .modal-body { padding: 1rem 1.25rem; background: #f8fafc; }
        #tour-day-editor .modal-footer { padding: 0.75rem 1.25rem; padding-bottom: calc(0.75rem + env(safe-area-inset-bottom)); border-top: 1px solid var(--tour-border); background: #fff; }
        @media (max-width: 767.98px) {
            #tour-day-editor .modal-dialog { margin: 0; max-width: 100%; height: 100dvh; }
            #tour-day-editor .modal-content { height: 100dvh; max-height: 100dvh; border-radius: 0; }
            #tour-day-editor .modal-header { padding: 0.75rem 1rem; }
            #tour-day-editor .modal-body { padding: 0.75rem 1rem; }
            #tour-day-editor .modal-footer { padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom)); }
        }
        .tour-editor-top-panel { background: #fff; border: 1px solid var(--tour-border); border-radius: 0.75rem; padding: 0.75rem 0.9rem; }
        .tour-category-scroll { display: flex; flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; gap: 0.45rem; padding-bottom: 0.2rem; }
        .tour-category-scroll::-webkit-scrollbar { display: none; }
        .tour-stop-chip { border: 0; border-radius: 999px; padding: 0.25rem 0.6rem; background: #eef8f4; color: #155f4b; font-size: 0.72rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem; }
        .tour-stop-chip:hover { background: #dcfce7; }
        .tour-category-chip { border-radius: 999px; white-space: nowrap; flex-shrink: 0; min-height: 34px; font-size: 0.78rem; font-weight: 600; }
        .tour-category-chip.active { background: #14213d; border-color: #14213d; color: #fff; }
        .tour-highlight-category { display: inline-block; font-size: 0.62rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--tour-primary); background: var(--tour-primary-soft); border-radius: 999px; padding: 0.18rem 0.45rem; }
        .tour-other-day-badge { display: inline-block; font-size: 0.65rem; font-weight: 700; color: #0369a1; background: #e0f2fe; border-radius: 999px; padding: 0.18rem 0.5rem; }
        .tour-place-desc { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        label.tour-option {
            cursor: pointer;
        }

        .tour-option:hover {
            border-color: var(--tour-primary-border);
        }

        .tour-option:has(input.tour-class-choice:checked),
        .tour-option:has(input.tour-destination:checked),
        .tour-option:has(input.tour-activity:checked) {
            border: 2px solid var(--tour-primary);
            background: var(--tour-primary-soft);
            box-shadow: 0 4px 14px -2px var(--tour-primary-ring);
        }

        .tour-stop-day-control[hidden] { display: none !important; }

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

        .tour-summary-head { background: #192638; color: #fff; margin: -1rem -1rem 0; padding: 0.8rem 0.85rem; border-radius: 0.75rem 0.75rem 0 0; }
        .tour-summary-head h2 { color: #fff; font-size: 1rem; font-weight: 800; margin: 0.35rem 0 0.1rem; }
        .tour-summary-head .small { color: #dbe3ef; }
        .tour-summary-duration { border-radius: 999px; background: var(--tour-primary); padding: 0.18rem 0.48rem; font-size: 0.65rem; font-weight: 800; white-space: nowrap; }
        .tour-summary-facts { display: grid; grid-template-columns: 1fr auto; gap: 0.35rem 0.65rem; padding: 0.75rem 0; border-bottom: 1px solid var(--tour-border); font-size: 0.7rem; }
        .tour-summary-facts dt { color: var(--tour-muted); font-weight: 500; }
        .tour-summary-facts dd { margin: 0; text-align: right; font-weight: 700; }
        .tour-summary-route { padding: 0.75rem 0; border-bottom: 1px solid var(--tour-border); }
        .tour-summary-route ol { list-style: none; padding: 0; margin: 0.42rem 0 0; font-size: 0.68rem; }
        .tour-summary-route li { position: relative; padding: 0.16rem 0 0.16rem 0.7rem; }
        .tour-summary-route li::before { content: ''; position: absolute; left: 0; top: 0.55rem; width: 0.3rem; height: 0.3rem; border-radius: 50%; background: var(--tour-primary); }
        .tour-summary-price { padding: 0.7rem 0; border-bottom: 1px solid var(--tour-border); }
        .tour-summary-price strong { color: #8f1b1e; font-size: 1.2rem; line-height: 1.15; }
        .tour-summary-price-class { font-size: 0.68rem; }
        .tour-summary-breakdown { border-top: 1px solid #edf0f4; margin-top: 0.45rem; padding-top: 0.25rem; }
        .tour-summary-breakdown-line { display: flex; justify-content: space-between; gap: 0.5rem; padding: 0.16rem 0; font-size: 0.65rem; }
        .tour-summary-breakdown-line.is-included { color: #059669; }
        .tour-summary-total { border-top: 1px solid #edf0f4; margin-top: 0.35rem; padding-top: 0.55rem; }
        .tour-summary-total > div:first-child strong { color: #111827; font-size: 0.75rem; }
        .tour-advance-note { margin: 0.6rem 0 0.7rem; padding: 0.55rem 0.6rem; border: 1px solid #f4d57b; border-radius: 0.45rem; background: #fffbeb; color: #8b4b06; font-size: 0.63rem; line-height: 1.4; }
        .tour-summary-trust { display: grid; grid-template-columns: 1fr 1fr; gap: 0.42rem 0.35rem; padding: 0.65rem 0 0.2rem; color: var(--tour-muted); font-size: 0.6rem; }
        .tour-summary-trust span::before { content: '✓'; color: var(--tour-emerald); font-weight: 800; margin-right: 0.3rem; }
        .tour-summary-advice { margin-top: 0.65rem; padding: 0.55rem; border: 1px solid var(--tour-border); border-radius: 0.55rem; background: #f8fafc; text-align: center; font-size: 0.64rem; }
        .tour-summary-advice a { display: inline-block; margin-top: 0.2rem; color: var(--tour-emerald); font-weight: 800; text-decoration: none; }
        .tour-summary-term { font-size: 0.76rem; }
        .tour-summary-term + .tour-summary-term { margin-top: 0.65rem; }
        .tour-summary-pay { width: 100%; }
        .tour-summary-request { width: 100%; }

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

        @media (min-width: 992px) {
            .tour-summary-aside {
                position: sticky;
                top: 4.25rem;
            }
        }

        .tour-mobile-summary {
            z-index: 1030;
            padding-bottom: calc(0.5rem + env(safe-area-inset-bottom)) !important;
        }

        @media (max-width: 991.98px) {
            .tour-page {
                padding-bottom: 6rem;
            }
            body:has(.tour-mobile-summary) .whatsapp-float,
            body:has(.tour-mobile-summary) .call-float {
                bottom: 5.25rem !important;
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
                        <span>Private Tours with a Driver</span>
                    </div>
                    <h1>Custom Multi-Day Tour &amp; Private Driver Hire</h1>
                    <p class="tour-hero-lead">
                        Plan a trip that suits you, or choose one of our ready-made tours. Pick the places and activities you want to visit. We’ll suggest a practical route and show the transport price when it is available.
                    </p>
                    <div class="tour-trust-grid">
                        <div class="tour-trust-item">
                            <svg class="tour-trust-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path>
                            </svg>
                            <span>Experienced Tour Drivers</span>
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
                            <span>Help Finding a Better Route</span>
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
                        <a class="tour-nav-pill" href="#tour-step-3">3. Price &amp; Route</a>
                        <a class="tour-nav-pill" href="#tour-contact-section">4. Contact</a>
                    </nav>
                    <div class="tour-live-indicator d-none d-md-inline-flex">
                        <span class="tour-live-dot" aria-hidden="true"></span>
                        <span>Plan your route and see the transport price</span>
                    </div>
                </div>
            </div>
        </div>

        <main class="container py-4 py-lg-5">
            <div id="tour-error" class="alert alert-danger mb-4" role="alert" hidden></div>
            <div id="tour-restore-banner" class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4 shadow-sm" hidden>
                <div class="small">
                    <strong class="text-dark"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Previous trip plan found</strong>
                    <span class="tour-muted ms-1" id="tour-restore-meta"></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" id="tour-restore-apply" class="btn btn-sm tour-btn-primary">Restore draft</button>
                    <button type="button" id="tour-restore-dismiss" class="btn btn-sm btn-outline-secondary">Dismiss</button>
                </div>
            </div>

            <div id="tour-loading" class="tour-card p-5 text-center">
                <div class="spinner-border text-secondary mb-3" role="status" aria-hidden="true"></div>
                <p class="mb-0 fw-semibold">Loading available tours and places…</p>
            </div>

            <form id="tour-form" class="row g-4 align-items-start" hidden>
                <!-- LEFT COLUMN: Step-by-Step Interactive Planner (8 Cols) -->
                <div class="col-lg-8 d-flex flex-column gap-4">

                    <!-- STEP 1: Trip Essentials & Vehicle Tier -->
                    <section id="tour-step-1" class="tour-card p-3 p-md-4">
                        <div class="tour-card-header">
                            <span class="tour-step-badge">1</span>
                            <div>
                                <h2 class="tour-card-title">Your Trip &amp; Vehicle</h2>
                                <p class="tour-card-subtitle">Choose your dates, group size, and the vehicle that suits your trip.</p>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6" id="tour-package-field">
                                <label for="tour-package" class="form-label">Ready-made tour <span class="text-lowercase fw-normal tour-muted">(optional)</span></label>
                                <select id="tour-package" class="form-select no-nice">
                                    <option value="">Choose later</option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="tour-date" class="form-label">Start date *</label>
                                <input id="tour-date" type="date" class="form-control" required>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="tour-days" class="form-label">Number of days *</label>
                                <input id="tour-days" type="number" class="form-control" min="1" max="60" value="3" required>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="tour-time" class="form-label">Daily start time *</label>
                                <input id="tour-time" type="time" class="form-control" value="09:00" required>
                            </div>
                        </div>

                        <!-- Party Size & Baggage Sub-panel -->
                        <fieldset class="tour-subpanel mb-4">
                            <legend class="tour-subpanel-title">People &amp; Luggage</legend>
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label for="tour-adults" class="form-label">Adults *</label>
                                    <div class="tour-stepper">
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-adults" data-step-delta="-1" aria-label="Decrease adults">−</button>
                                        <input id="tour-adults" type="number" class="form-control" min="0" max="100" value="2" required>
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-adults" data-step-delta="1" aria-label="Increase adults">+</button>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label for="tour-children" class="form-label">Children *</label>
                                    <div class="tour-stepper">
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-children" data-step-delta="-1" aria-label="Decrease children">−</button>
                                        <input id="tour-children" type="number" class="form-control" min="0" max="100" value="0" required>
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-children" data-step-delta="1" aria-label="Increase children">+</button>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label for="tour-child-seats" class="form-label">Child seats</label>
                                    <div class="tour-stepper">
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-child-seats" data-step-delta="-1" aria-label="Decrease child seats">−</button>
                                        <input id="tour-child-seats" type="number" class="form-control" min="0" max="100" value="0" required>
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-child-seats" data-step-delta="1" aria-label="Increase child seats">+</button>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label for="tour-luggage" class="form-label">Luggage *</label>
                                    <div class="tour-stepper">
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-luggage" data-step-delta="-1" aria-label="Decrease luggage">−</button>
                                        <input id="tour-luggage" type="number" class="form-control" min="0" max="100" value="0" required>
                                        <button type="button" class="tour-stepper-btn" data-step-target="tour-luggage" data-step-delta="1" aria-label="Increase luggage">+</button>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-3 mt-3 pt-2 border-top small">
                                <label class="form-check mb-0" style="cursor: pointer;">
                                    <input class="form-check-input tour-gear-toggle" type="checkbox" id="tour-gear-airport" value="Airport pickup">
                                    <span class="form-check-label">Airport pickup</span>
                                </label>
                                <label class="form-check mb-0" style="cursor: pointer;">
                                    <input class="form-check-input tour-gear-toggle" type="checkbox" id="tour-gear-surfboard" value="Surfboard roof rack">
                                    <span class="form-check-label">Surfboard rack</span>
                                </label>
                                <label class="form-check mb-0" style="cursor: pointer;">
                                    <input class="form-check-input tour-gear-toggle" type="checkbox" id="tour-gear-wifi" value="In-car Wi-Fi">
                                    <span class="form-check-label">In-car Wi-Fi</span>
                                </label>
                            </div>
                            <p class="form-text mb-0 mt-2">Confirm child seats at checkout.</p>
                            <div id="tour-capacity-warning" class="alert alert-warning py-2 px-3 small mt-2 mb-0" hidden></div>
                        </fieldset>

                        <div id="tour-custom-fields" class="row g-3 mb-4"></div>

                        <!-- Vehicle Tier Selector Cards -->
                        <fieldset class="border-0 p-0 m-0">
                            <legend class="tour-label mb-2">Choose your private vehicle *</legend>
                            <div id="tour-class-cards" class="row g-3" role="radiogroup" aria-label="Choose a vehicle type"></div>
                            <select id="tour-class" class="d-none no-nice" tabindex="-1" aria-hidden="true"></select>
                        </fieldset>

                        <!-- Optional Trip Ideas -->
                        <div id="tour-ai-helper" class="mt-4" hidden>
                            <div class="tour-ai-box">
                                <h3 class="h6 fw-bold mb-1">Need trip ideas?</h3>
                                <p class="small tour-muted mb-3">
                                    Tell us your interests and we’ll suggest a starting plan you can edit anytime.
                                </p>
                                <label for="tour-interests" class="form-label">What are you interested in? <span class="text-lowercase fw-normal tour-muted">(optional)</span></label>
                                <textarea id="tour-interests" class="form-control" rows="2" maxlength="500"
                                    placeholder="For example: beaches, wildlife, history"></textarea>
                                <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                                    <button id="tour-ai-suggest" class="btn tour-btn-outline btn-sm" type="button">Suggest a plan</button>
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
                                <h2 class="tour-card-title">Custom Itinerary &amp; Attractions</h2>
                                <p class="tour-card-subtitle">Add destinations to your days. No rigid schedules, stop whenever you like!</p>
                            </div>
                            <span id="tour-stop-count" class="badge rounded-pill text-bg-light border">0 stops added</span>
                        </div>

                        <div id="tour-day-settings" hidden></div>

                        <div class="tour-subpanel mb-4">
                            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
                                <div>
                                    <h3 class="h6 fw-bold mb-1">Your trip plan</h3>
                                    <p class="small tour-muted mb-0">Add places and activities to each day, or optimize your route below.</p>
                                </div>
                                <div id="tour-optimize-actions" class="d-flex flex-wrap align-items-center gap-3" hidden>
                                    <button type="button" id="tour-optimize-route" class="tour-text-action">
                                        <i class="bi bi-arrow-down-up" aria-hidden="true"></i> Optimize stop order
                                    </button>
                                    <button type="button" id="tour-optimize-plan" class="tour-text-action">
                                        <i class="bi bi-stars" aria-hidden="true"></i> Optimize entire plan
                                    </button>
                                </div>
                            </div>
                            <div id="tour-quick-templates" class="mb-3" hidden></div>
                            <div id="tour-itinerary-days" aria-live="polite"></div>
                        </div>

                        <div class="tour-subpanel mt-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <h3 class="h6 fw-bold mb-1">Your route on the map</h3>
                                    <p class="small tour-muted mb-0">The line connects your stops in order. It is a route overview, not a road-by-road navigation map.</p>
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button type="button" id="tour-map-toggle" class="btn btn-sm tour-btn-outline d-lg-none" aria-expanded="true" aria-controls="tour-map-container">Hide Map</button>
                                    <div id="tour-map-route-options" class="btn-group btn-group-sm" role="group" aria-label="Choose route to show" hidden>
                                        <button type="button" class="btn tour-btn-outline" data-map-route="original">Your selected order</button>
                                        <button type="button" class="btn tour-btn-outline" data-map-route="suggested">Suggested route</button>
                                    </div>
                                </div>
                            </div>
                            <div id="tour-map-container">
                                <div id="tour-route-map" role="img" aria-label="Map showing selected tour locations and route order"></div>
                                <p id="tour-map-help" class="small tour-muted mt-2 mb-0" aria-live="polite">Choose a start place and locations to see them on the map.</p>
                            </div>
                        </div>

                        <div class="modal fade" id="tour-day-editor" tabindex="-1" aria-labelledby="tour-day-editor-title" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-fullscreen-md-down modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h2 class="modal-title fs-5 fw-bold" id="tour-day-editor-title">Edit Day 1</h2>
                                            <p class="small tour-muted mb-0">Pick where this day ends and add places or activities for this day.</p>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body d-flex flex-column gap-3">
                                        <div class="tour-editor-top-panel">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-6" id="tour-day-editor-start-wrap">
                                                    <label for="tour-start" class="form-label mb-1">Tour starts at *</label>
                                                    <select id="tour-start" class="form-select no-nice tour-location-select" required><option value="">Choose a place</option></select>
                                                </div>
                                                <div class="col-md-6" id="tour-day-editor-end-wrap">
                                                    <label for="tour-day-editor-end" class="form-label mb-1" id="tour-day-editor-end-label">Day 1 overnight location</label>
                                                    <select id="tour-day-editor-end" class="form-select no-nice tour-location-select"></select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="tour-editor-top-panel">
                                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-2">
                                                <div class="input-group flex-grow-1">
                                                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted" aria-hidden="true"></i></span>
                                                    <input id="tour-stop-text-search" type="search" class="form-control border-start-0" placeholder="Search places or activities (e.g. Ella, Safari, Sigiriya)..." aria-label="Search places or activities">
                                                </div>
                                                <span id="tour-highlight-count" class="small tour-muted text-nowrap"></span>
                                            </div>
                                            <div id="tour-day-selected-strip" class="d-flex flex-wrap align-items-center gap-2 mb-2" hidden></div>
                                            <div id="tour-category-filters" class="tour-category-scroll" role="group" aria-label="Filter highlights by category"></div>
                                            <select id="tour-destination-search" class="d-none" multiple aria-hidden="true" tabindex="-1"></select>
                                        </div>

                                        <div id="tour-destinations" class="row g-3"></div>
                                        <p id="tour-destination-empty" class="small tour-muted mt-1 mb-0" hidden></p>
                                    </div>
                                    <div class="modal-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <div class="small">
                                            <strong id="tour-day-editor-summary" class="d-block text-dark">Day 1: 0 places</strong>
                                            <span class="tour-muted" style="font-size: 0.72rem;">Entry tickets &amp; activity reservations are not included.</span>
                                        </div>
                                        <button type="button" id="tour-day-editor-done" class="btn tour-btn-primary" data-bs-dismiss="modal">Save Day 1</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- STEP 3: Review Route & Transport Price -->
                    <section id="tour-step-3" class="tour-card p-3 p-md-4">
                        <div class="tour-card-header">
                            <span class="tour-step-badge">3</span>
                            <div>
                                <h2 class="tour-card-title">Review Your Route &amp; Transport Price</h2>
                                <p class="tour-card-subtitle">Compare your route and estimated transport price. Our team may need to check some trips.</p>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <button id="tour-plan" class="btn tour-btn-primary" type="submit">Compare route and price</button>
                            <span id="tour-working" class="small tour-muted fw-medium" hidden>Checking route and price…</span>
                        </div>
                        <div class="row g-3 mt-3 pt-3 border-top small">
                            <div class="col-md-6">
                                <div class="fw-bold text-success mb-1"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Included in transport quote</div>
                                <ul class="list-unstyled mb-0 tour-muted d-flex flex-column gap-1">
                                    <li>• Private A/C vehicle &amp; English-speaking chauffeur-guide</li>
                                    <li>• Fuel, highway tolls, parking &amp; driver meals/lodging</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <div class="fw-bold text-secondary mb-1"><i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i>Arranged separately</div>
                                <ul class="list-unstyled mb-0 tour-muted d-flex flex-column gap-1">
                                    <li>• Hotel stays &amp; personal meals</li>
                                    <li>• Attraction entry tickets &amp; safari jeep fees</li>
                                </ul>
                            </div>
                        </div>
                        <div id="tour-results" class="mt-4" hidden></div>
                    </section>

                    <!-- STEP 4: Lead Passenger Contact -->
                    <section id="tour-contact-section" class="tour-card p-3 p-md-4">
                        <div class="tour-card-header">
                            <span class="tour-step-badge">4</span>
                            <div>
                                <h2 class="tour-card-title">Your Contact Details</h2>
                                <p class="tour-card-subtitle">Add your details so we can save your trip plan and price.</p>
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
                                <label for="tour-phone" class="form-label">Mobile number *</label>
                                <input id="tour-phone" type="tel" class="form-control" autocomplete="tel" required>
                            </div>
                            <div class="col-md-6">
                                <label for="tour-whatsapp" class="form-label">WhatsApp number <span class="small tour-muted">(optional)</span></label>
                                <input id="tour-whatsapp" type="tel" class="form-control" autocomplete="tel-national" data-custom-name="contact_whatsapp">
                            </div>
                            <div class="col-12">
                                <label for="tour-special-requests" class="form-label">Special Requests or Flight Numbers <span class="small tour-muted">(Optional)</span></label>
                                <textarea id="tour-special-requests" class="form-control" rows="3" maxlength="2000" placeholder="For example, your flight number or any special requests for the trip." data-custom-name="special_requests"></textarea>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-top">
                            @if ($guestBookingEnabled)
                                <p class="small tour-muted mb-2">Already booked with us? Enter the same email and continue. Your booking will be saved with your existing account. You can verify by email to fill in your saved details here.</p>
                            @elseif ($customerLookupEnabled)
                                <p class="small tour-muted mb-2">Returning customers can verify their email to continue. New customers need to sign in.</p>
                            @else
                                <p class="small tour-muted mb-2">Please sign in before planning a Tour.</p>
                            @endif
                            @if ($customerLookupEnabled)
                                <button id="tour-send-code" class="btn btn-link px-0 text-decoration-none fw-semibold" style="color: var(--tour-primary);" type="button">
                                    Verify email and load saved details
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
                            @endif
                        </div>
                    </section>

                    <!-- Transparency & Planning Guarantee Callout -->
                    <div class="tour-guarantee-box">
                        <div class="d-flex align-items-start gap-3">
                            <svg width="22" height="22" class="flex-shrink-0 mt-1" style="color: #b45309;" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                            <div class="small lh-base">
                                <h3 class="tour-guarantee-title">Before You Book</h3>
                                <p class="mb-2">
                                    We can suggest a shorter trip plan by changing the visit order or moving visits between days. Your overnight and finish places stay as chosen.
                                </p>
                                <p class="mb-0">
                                    <strong>Activity tickets:</strong> The price covers travel between places. Entry tickets and activity bookings are not included. We plan to offer activity bookings in the future.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Tour Summary -->
                <aside class="col-lg-4 tour-summary-aside d-flex flex-column gap-4">
                    <div class="tour-sidebar-card p-3">
                        <header class="tour-summary-head">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="text-uppercase fw-bold small" style="letter-spacing: 0.06em;">Tour Summary</span>
                                <span id="tour-summary-duration" class="tour-summary-duration">3 Days / 2 Nights</span>
                            </div>
                            <h2>Private Tour</h2>
                            <span id="tour-summary-vehicle" class="small">Choose your vehicle</span>
                        </header>

                        <dl class="tour-summary-facts mb-0" aria-live="polite">
                            <dt>Dates</dt><dd id="tour-summary-dates">Choose your travel dates</dd>
                            <dt>Travellers</dt><dd id="tour-summary-travellers">2 Adults</dd>
                            <dt>Pick-up location</dt><dd id="tour-summary-pickup">Choose a place</dd>
                            <dt>Driver language</dt><dd>Fluent English</dd>
                        </dl>

                        <section class="tour-summary-route" aria-labelledby="tour-summary-route-title">
                            <h3 id="tour-summary-route-title" class="small text-uppercase fw-bold mb-0">Planned route stops</h3>
                            <ol id="tour-summary-route-stops" aria-live="polite"><li>Choose places to build your route</li></ol>
                        </section>

                        <section id="tour-total-summary" class="tour-summary-price" aria-live="polite">
                            <div class="d-flex justify-content-between align-items-center gap-2 small mb-2">
                                <span id="tour-summary-price-label">Estimated transport price</span>
                            </div>
                            <div class="tour-summary-breakdown-line">
                                <span id="tour-summary-price-class" class="tour-summary-price-class">Choose a vehicle</span>
                                <span id="tour-total-distance" class="small tour-muted"></span>
                            </div>
                            <div id="tour-summary-breakdown" class="tour-summary-breakdown" hidden></div>
                            <div class="tour-summary-total d-flex justify-content-between align-items-center gap-2">
                                <div><strong class="d-block text-dark" style="font-size:.75rem">Total price</strong><span class="small tour-muted">Estimated transport price</span></div>
                                <div class="text-end"><strong id="tour-total-amount">—</strong><span class="d-block small tour-muted" id="tour-summary-currency"></span></div>
                            </div>
                            <p id="tour-summary-price-status" class="small tour-muted mt-2 mb-0" role="status" aria-live="polite"></p>
                        </section>
                        <div id="tour-summary-advance-note" class="tour-advance-note" data-enabled="{{ $advancePaymentEnabled ? 'true' : 'false' }}" data-percentage="{{ number_format($advancePercentage, 4, '.', '') }}" data-minimum="{{ number_format($advanceMinAmount, 2, '.', '') }}" hidden></div>
                        <div id="tour-summary-terms" class="py-3" hidden></div>
                        <div class="d-grid gap-2">
                            <button id="tour-summary-checkout" type="button" class="btn tour-btn-primary tour-summary-pay" hidden disabled>Confirm &amp; Reserve Driver <span aria-hidden="true">→</span></button>
                            <button id="tour-summary-request-quote" type="button" class="btn tour-btn-outline tour-summary-request" hidden>Request a quotation</button>
                            <button id="tour-summary-goto-contact" type="button" class="btn tour-btn-primary btn-sm">Continue to Contact &amp; Price <span aria-hidden="true">→</span></button>
                            @if (!empty($supportWhatsapp))
                                <a id="tour-whatsapp-share" href="https://wa.me/{{ $supportWhatsapp }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-success btn-sm fw-semibold">
                                    <i class="bi bi-whatsapp me-1" aria-hidden="true"></i> Send itinerary via WhatsApp
                                </a>
                            @endif
                            <button id="tour-copy-link" type="button" class="btn btn-outline-secondary btn-sm fw-semibold">
                                <i class="bi bi-link-45deg me-1" aria-hidden="true"></i> Copy shareable link
                            </button>
                        </div>
                        <p class="text-center tour-muted mt-2 mb-0" style="font-size: 0.65rem;">No payment required to quote • Free changes • Fast reply</p>
                        <div class="tour-summary-trust">
                            <span>Price updates with your trip</span>
                            <span>Stops arranged by day</span>
                            <span>Choose places and activities</span>
                            <span>Tickets and activity fees not included</span>
                        </div>
                        @if (!empty($supportPhone) || !empty($supportWhatsapp))
                            <div class="tour-summary-advice">
                                <div class="tour-muted">Need route planning advice?</div>
                                @if (!empty($supportWhatsapp))
                                    <a href="https://wa.me/{{ $supportWhatsapp }}" target="_blank" rel="noopener noreferrer">Talk with our team on WhatsApp <span aria-hidden="true">→</span></a>
                                @else
                                    <a href="tel:{{ $supportPhoneHref }}">Talk with our team <span aria-hidden="true">→</span></a>
                                @endif
                            </div>
                        @endif
                    </div>
                </aside>
            </form>
        </main>

        @if ($tourFaqs->isNotEmpty())
            <section class="tour-faq-section py-5" aria-labelledby="tour-faq-heading">
                <div class="container" style="max-width: 54rem;">
                    <div class="text-center mb-4">
                        <h2 id="tour-faq-heading" class="h3 fw-bold mb-1">Frequently Asked Questions</h2>
                        <p class="small tour-muted mb-0">Clear answers about planning your multi-day private tour.</p>
                    </div>

                    <div class="d-flex flex-column gap-3">
                        @foreach ($tourFaqs as $faq)
                            <details class="tour-faq-item">
                                <summary>{{ $faq->question }}</summary>
                                <div class="tour-faq-body">{{ $faq->answer }}</div>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <!-- Mobile Sticky Quote Summary -->
        <div id="tour-mobile-summary"
            class="tour-mobile-summary d-lg-none position-fixed bottom-0 start-0 end-0 bg-white border-top shadow-lg p-2">
            <div class="container d-flex align-items-center justify-content-between gap-2">
                <div class="min-w-0">
                    <span class="d-block text-truncate tour-muted" style="font-size: 0.72rem;" id="tour-mobile-meta">3 Days • Choose vehicle</span>
                    <strong id="tour-mobile-total" class="d-block text-dark" style="font-size: 0.95rem;">Plan route for price</strong>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <button id="tour-mobile-drawer-btn" class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#tour-mobile-drawer" aria-controls="tour-mobile-drawer">Summary</button>
                    <button id="tour-mobile-review" class="btn tour-btn-primary btn-sm" type="button">Get Price</button>
                </div>
            </div>
        </div>

        <!-- Mobile Tour Summary Drawer -->
        <div class="offcanvas offcanvas-bottom d-lg-none" tabindex="-1" id="tour-mobile-drawer" aria-labelledby="tour-mobile-drawer-label" style="height: auto; max-height: 85dvh; border-radius: 1rem 1rem 0 0;">
            <div class="offcanvas-header border-bottom">
                <h2 class="offcanvas-title fs-6 fw-bold" id="tour-mobile-drawer-label">Tour Summary</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body" id="tour-mobile-drawer-body"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        (() => {
            const form = document.getElementById('tour-form');
            const mobileInput = document.getElementById('tour-phone');
            const whatsappInput = document.getElementById('tour-whatsapp');
            const phoneInputOptions = {
                initialCountry: 'lk',
                preferredCountries: ['lk', 'gb', 'us', 'in', 'au'],
                separateDialCode: true,
                nationalMode: true,
                utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js',
            };
            const mobilePhoneInput = typeof window.intlTelInput === 'function' ? window.intlTelInput(mobileInput, phoneInputOptions) : null;
            const whatsappPhoneInput = typeof window.intlTelInput === 'function' ? window.intlTelInput(whatsappInput, phoneInputOptions) : null;
            const loading = document.getElementById('tour-loading');
            const error = document.getElementById('tour-error');
            const classSelect = document.getElementById('tour-class');
            const packageSelect = document.getElementById('tour-package');
            const startSelect = document.getElementById('tour-start');
            const daySettings = document.getElementById('tour-day-settings');
            const destinationList = document.getElementById('tour-destinations');
            const itineraryDaysSummary = document.getElementById('tour-itinerary-days');
            const categoryFilters = document.getElementById('tour-category-filters');
            const highlightCount = document.getElementById('tour-highlight-count');
            const results = document.getElementById('tour-results');
            const stopSearchSelect = document.getElementById('tour-destination-search');
            const stopTextSearch = document.getElementById('tour-stop-text-search');
            const dayEditor = document.getElementById('tour-day-editor');
            const dayEditorEnd = document.getElementById('tour-day-editor-end');
            const whatsappSupportNumber = @json($supportWhatsapp);
            const api = '/api/public/tours';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let services = [];
            let selectedService = null;
            let catalogue = null;
            let classPriceEstimates = new Map();
            let classPriceRouteLabel = null;
            let tourMap = null;
            let tourMapOverlays = [];
            let routeMapOptions = null;
            let mapRouteSelection = 'suggested';
            let savedItinerary = null;
            let selectedTemplateDays = [];
            let activeDestinationCategory = 'route';
            let preferredRouteChoice = 'suggested';
            let currentTourQuote = null;
            let currentRouteOptions = null;
            let autoPriceRefreshTimer = null;
            let activeEditDay = null;
            let knownStopSelection = new Set();
            let focusStopSearchOnModalOpen = false;
            let lastTourStart = '';

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
            function trackTourEvent(eventName, params = {}) {
                try {
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({ event: eventName, ...params });
                    if (typeof window.gtag === 'function') window.gtag('event', eventName, params);
                } catch (_) {}
            }
            function selectedGearList() {
                return [...document.querySelectorAll('.tour-gear-toggle:checked')].map(el => el.value);
            }
            function destroyTourSearch(select) {
                const $ = window.jQuery;
                if ($?.fn?.select2 && $(select).hasClass('select2-hidden-accessible')) $(select).select2('destroy');
            }
            function enhanceTourSearch(select, placeholder, multiple = false) {
                const $ = window.jQuery;
                if (!$?.fn?.select2) return;
                $(select).select2({
                    width: '100%',
                    minimumResultsForSearch: 0,
                    placeholder,
                    allowClear: select === startSelect,
                    closeOnSelect: !multiple,
                    dropdownParent: $(select).closest('.modal').length ? $(select).closest('.modal') : $('#tour-step-2'),
                });
            }
            const showError = (message) => { error.textContent = message; error.hidden = false; error.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); };
            const clearError = () => { error.textContent = ''; error.hidden = true; };
            function invalidateDisplayedQuote() {
                results.hidden = true;
                currentTourQuote = null;
                document.getElementById('tour-summary-terms').hidden = true;
                document.getElementById('tour-summary-checkout').hidden = true;
                const gotoBtn = document.getElementById('tour-summary-goto-contact');
                if (gotoBtn) gotoBtn.hidden = false;
                document.getElementById('tour-summary-price-label').textContent = 'Estimated transport price';
                document.getElementById('tour-total-amount').textContent = savedItinerary ? 'Updating…' : '—';
                document.getElementById('tour-summary-price-status').textContent = savedItinerary ? 'Updating your route and price…' : 'Complete your trip details to see an estimate.';
                document.getElementById('tour-summary-breakdown').hidden = true;
                document.getElementById('tour-summary-advance-note').hidden = true;
                document.getElementById('tour-summary-currency').textContent = '';
                updateMobileSummaryBar(savedItinerary ? 'Updating…' : 'Plan route for price');
                if (classPriceEstimates.size) {
                    classPriceEstimates.clear();
                    classPriceRouteLabel = null;
                    renderClassCards();
                }
            }
            function updateMobileSummaryBar(priceText = null) {
                const days = Math.max(1, Number(document.getElementById('tour-days').value) || 1);
                const selectedClass = classSelect.selectedOptions[0]?.textContent?.split('—')[0]?.trim() || 'Choose vehicle';
                const metaEl = document.getElementById('tour-mobile-meta');
                const totalEl = document.getElementById('tour-mobile-total');
                if (metaEl) metaEl.textContent = `${days} ${days === 1 ? 'Day' : 'Days'} • ${selectedClass}`;
                if (totalEl && priceText !== null) totalEl.textContent = priceText;
            }
            function showQuoteSummary(quote) {
                if (quote.total_amount === null || quote.total_amount === undefined || !Number.isFinite(Number(quote.total_amount))) return;
                currentTourQuote = quote;
                const formatted = `${quote.currency} ${Number(quote.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                document.getElementById('tour-total-amount').textContent = Number(quote.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                document.getElementById('tour-summary-currency').textContent = quote.currency;
                document.getElementById('tour-summary-price-label').textContent = 'Transport';
                document.getElementById('tour-summary-price-class').textContent = `${classSelect.selectedOptions[0]?.textContent?.trim() || 'Selected vehicle'} (${document.getElementById('tour-days').value} Days)`;
                document.getElementById('tour-total-distance').textContent = `${quote.distance_km} km estimated`;
                document.getElementById('tour-summary-price-status').textContent = 'Price held for 30 minutes.';
                renderPricingBreakdown(quote.breakdown, quote.currency);
                renderAdvanceNote(Number(quote.total_amount), quote.currency);
                updateMobileSummaryBar(formatted);
                const terms = quote.terms_snapshot || [];
                const termsContainer = document.getElementById('tour-summary-terms');
                termsContainer.innerHTML = terms.map((term, index) => `<div class="form-check tour-summary-term"><input class="form-check-input tour-term" type="checkbox" value="${escapeHtml(term.id)}" id="tour-summary-term-${escapeHtml(term.id || index)}"><label class="form-check-label" for="tour-summary-term-${escapeHtml(term.id || index)}"><strong>${escapeHtml(term.title || 'Booking terms')}</strong> (${escapeHtml(term.version || 'current')}) — I agree</label><div class="small tour-muted ms-4 mt-1" style="white-space:pre-wrap;line-height:1.5">${escapeHtml(term.content)}</div></div>`).join('');
                termsContainer.hidden = terms.length === 0;
                const checkoutButton = document.getElementById('tour-summary-checkout');
                checkoutButton.hidden = false;
                checkoutButton.disabled = terms.length > 0;
                const gotoBtn = document.getElementById('tour-summary-goto-contact');
                if (gotoBtn) gotoBtn.hidden = true;
                document.getElementById('tour-summary-request-quote').hidden = false;
            }
            function renderPricingBreakdown(breakdown, currency = '') {
                const element = document.getElementById('tour-summary-breakdown');
                const lines = Array.isArray(breakdown) ? breakdown : Object.entries(breakdown || {}).map(([component, amount]) => ({ component, amount }));
                const visibleLines = lines.filter(line => line && typeof line === 'object' && Number.isFinite(Number(line.amount)));
                element.innerHTML = visibleLines.map(line => {
                    const amount = Number(line.amount);
                    const value = amount === 0 ? `Included (${escapeHtml(currency)} 0.00)` : `${escapeHtml(currency)} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    return `<div class="tour-summary-breakdown-line${amount === 0 ? ' is-included' : ''}"><span>${escapeHtml(line.component || line.label || line.name || 'Transport')}</span><span>${value}</span></div>`;
                }).join('');
                element.hidden = visibleLines.length === 0;
            }
            function renderAdvanceNote(total, currency) {
                const note = document.getElementById('tour-summary-advance-note');
                const percentage = Number(note.dataset.percentage);
                if (note.dataset.enabled !== 'true' || !Number.isFinite(percentage) || percentage <= 0 || total <= 0) {
                    note.hidden = true;
                    return;
                }
                const amount = Math.min(total, Math.max(total * percentage / 100, Number(note.dataset.minimum) || 0));
                note.textContent = `Pay ${percentage}% (${currency} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}) at checkout to confirm your Tour. The remaining balance is payable according to the booking terms.`;
                note.hidden = false;
            }
            function renderTourSummaryDetails(routeOptions = null, routeChoice = preferredRouteChoice) {
                const date = document.getElementById('tour-date').value;
                const dayCount = Math.max(1, Number(document.getElementById('tour-days').value) || 1);
                const adults = Number(document.getElementById('tour-adults').value || 0);
                const children = Number(document.getElementById('tour-children').value || 0);
                const luggage = Number(document.getElementById('tour-luggage').value || 0);
                const places = new Map((catalogue?.destinations || []).map(item => [String(item.id), item]));
                const start = places.get(String(startSelect.value));
                const selectedClass = classSelect.selectedOptions[0]?.textContent?.trim() || 'Choose your vehicle';
                const classDetails = (currentService()?.classes || []).find(item => String(item.id) === String(classSelect.value));
                const nightCount = Math.max(0, dayCount - 1);
                document.getElementById('tour-summary-duration').textContent = `${dayCount} ${dayCount === 1 ? 'Day' : 'Days'} / ${nightCount} ${nightCount === 1 ? 'Night' : 'Nights'}`;
                document.getElementById('tour-summary-vehicle').textContent = classDetails?.display_name || selectedClass;
                document.getElementById('tour-summary-travellers').textContent = `${adults} ${adults === 1 ? 'Adult' : 'Adults'}${children ? `, ${children} ${children === 1 ? 'Child' : 'Children'}` : ''}, ${luggage} ${luggage === 1 ? 'Bag' : 'Bags'}`;
                document.getElementById('tour-summary-pickup').textContent = start?.name || 'Choose a place';
                if (date) {
                    const startDate = new Date(`${date}T00:00:00`);
                    const endDate = new Date(startDate);
                    endDate.setDate(endDate.getDate() + dayCount - 1);
                    const formatDate = value => value.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                    document.getElementById('tour-summary-dates').textContent = dayCount > 1 ? `${formatDate(startDate)} – ${formatDate(endDate)}` : formatDate(startDate);
                } else {
                    document.getElementById('tour-summary-dates').textContent = 'Choose your travel dates';
                }

                const selectedRoute = routeOptions?.[routeChoice];
                const routeDays = selectedRoute?.days;
                const dayRows = routeDays?.length ? routeDays.map(day => {
                    const stops = (day.schedule || []).map(stop => stop.stop_name).filter(Boolean);
                    return `<li><strong>Day ${escapeHtml(day.day_number)}:</strong> ${escapeHtml(stops.join(' · ') || 'Route stops to be selected')}</li>`;
                }) : Array.from({ length: dayCount }, (_, index) => {
                    const day = index + 1;
                    const destinations = [...document.querySelectorAll('.tour-destination:checked')]
                        .filter(input => Number(document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`)?.value) === day)
                        .map(input => places.get(String(input.value))?.name);
                    const activities = [...document.querySelectorAll('.tour-activity:checked')]
                        .filter(input => Number(document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(input.value)}"]`)?.value) === day)
                        .map(input => (catalogue?.destinations || []).flatMap(place => place.activities || []).find(activity => String(activity.id) === String(input.value))?.name);
                    const names = [...destinations, ...activities].filter(Boolean);
                    return `<li><strong>Day ${day}:</strong> ${escapeHtml(names.join(' · ') || 'Choose places or activities')}</li>`;
                });
                document.getElementById('tour-summary-route-stops').innerHTML = dayRows.join('');
                updateMobileSummaryBar();
                updateWhatsappShareLink(dayCount, start?.name || 'TBD', selectedClass);
            }
            function updateWhatsappShareLink(dayCount, startName, vehicleName) {
                const link = document.getElementById('tour-whatsapp-share');
                if (!link || !whatsappSupportNumber) return;
                const date = document.getElementById('tour-date').value || 'Flexible';
                const adults = Number(document.getElementById('tour-adults').value || 0);
                const children = Number(document.getElementById('tour-children').value || 0);
                const luggage = Number(document.getElementById('tour-luggage').value || 0);
                const dayLines = [...document.querySelectorAll('#tour-summary-route-stops li')].map(li => `• ${li.textContent.trim()}`);
                const gear = selectedGearList();
                const text = [
                    `Hi The Taxi team! I'd like a quote for a ${dayCount}-day private tour:`,
                    `Start Date: ${date}`,
                    `Pick-up: ${startName}`,
                    `Vehicle: ${vehicleName}`,
                    `Group: ${adults} Adults${children ? `, ${children} Children` : ''}, ${luggage} Bags`,
                    gear.length ? `Extras: ${gear.join(', ')}` : '',
                    dayLines.length ? `\nItinerary:\n${dayLines.join('\n')}` : '',
                ].filter(Boolean).join('\n');
                link.href = `https://wa.me/${whatsappSupportNumber}?text=${encodeURIComponent(text)}`;
            }
            function showEstimatedSummary(routes, routeChoice = preferredRouteChoice) {
                renderTourSummaryDetails(routes, routeChoice);
                const route = routes?.[routeChoice];
                const estimate = route?.quote;
                document.getElementById('tour-summary-price-class').textContent = `${classSelect.selectedOptions[0]?.textContent?.trim() || 'Choose a vehicle'} (${document.getElementById('tour-days').value} Days)`;
                document.getElementById('tour-summary-price-label').textContent = 'Transport';
                if (estimate?.status === 'priced' && Number.isFinite(Number(estimate.total_amount))) {
                    const formatted = `${estimate.currency} ${Number(estimate.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    document.getElementById('tour-total-amount').textContent = Number(estimate.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    document.getElementById('tour-summary-currency').textContent = estimate.currency;
                    document.getElementById('tour-total-distance').textContent = `${route.distance_km} km estimated`;
                    document.getElementById('tour-summary-price-status').textContent = 'Estimate updates as your trip changes.';
                    renderPricingBreakdown(estimate.breakdown, estimate.currency);
                    renderAdvanceNote(Number(estimate.total_amount), estimate.currency);
                    updateMobileSummaryBar(formatted);
                } else {
                    document.getElementById('tour-total-amount').textContent = 'Request quote';
                    document.getElementById('tour-summary-currency').textContent = '';
                    document.getElementById('tour-summary-breakdown').hidden = true;
                    document.getElementById('tour-summary-advance-note').hidden = true;
                    document.getElementById('tour-total-distance').textContent = route?.distance_km == null ? 'Route distance pending review' : `${route.distance_km} km estimated`;
                    document.getElementById('tour-summary-price-status').textContent = estimate?.reason || 'Our team will prepare a quotation for this route.';
                    updateMobileSummaryBar('Request quote');
                }
                document.getElementById('tour-summary-request-quote').hidden = !savedItinerary;
                document.getElementById('tour-summary-checkout').hidden = true;
            }
            function scheduleAutomaticPriceRefresh() {
                if (!savedItinerary?.id) return;
                clearTimeout(autoPriceRefreshTimer);
                document.getElementById('tour-summary-price-status').textContent = 'Updating your route and price…';
                autoPriceRefreshTimer = setTimeout(() => {
                    if (!form.checkValidity()) {
                        document.getElementById('tour-summary-price-status').textContent = 'Complete the required trip details to refresh your price.';
                        return;
                    }
                    form.requestSubmit();
                }, 900);
            }
            function tourLocationPoint(location) {
                const latitude = Number(location?.latitude);
                const longitude = Number(location?.longitude);
                return Number.isFinite(latitude) && Number.isFinite(longitude) && (latitude !== 0 || longitude !== 0) && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180
                    ? [latitude, longitude]
                    : null;
            }
            function selectedTourMapStops() {
                if (!catalogue || !startSelect.value) return [];
                const destinations = new Map((catalogue.destinations || []).map(item => [String(item.id), item]));
                const activities = new Map((catalogue.destinations || []).flatMap(item => (item.activities || []).map(activity => [String(activity.id), activity])));
                const days = Number(document.getElementById('tour-days').value) || 1;
                return Array.from({ length: days }, (_, index) => {
                    const day = index + 1;
                    const startId = day === 1 ? startSelect.value : document.querySelector(`.tour-day-end[data-day="${day - 1}"]`)?.value;
                    const endId = document.querySelector(`.tour-day-end[data-day="${day}"]`)?.value;
                    const start = destinations.get(String(startId));
                    const end = destinations.get(String(endId));
                    const places = [...destinationList.querySelectorAll('.tour-destination:checked')]
                        .filter(input => Number(document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`)?.value) === day)
                        .map(input => destinations.get(String(input.value)))
                        .filter(Boolean)
                        .map(item => ({ name: item.name, point: tourLocationPoint(item.location) }));
                    const visits = [...destinationList.querySelectorAll('.tour-activity:checked')]
                        .filter(input => Number(document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(input.value)}"]`)?.value) === day)
                        .map(input => activities.get(String(input.value)))
                        .filter(Boolean)
                        .map(item => ({ name: item.name, point: tourLocationPoint(item.location) }));
                    return { day, points: [
                        ...(start ? [{ name: `Start · ${start.name}`, point: tourLocationPoint(start.location) }] : []),
                        ...places, ...visits,
                        ...(end ? [{ name: `${day === days ? 'Finish' : 'Overnight'} · ${end.name}`, point: tourLocationPoint(end.location) }] : []),
                    ].filter(item => item.point) };
                }).filter(day => day.points.length);
            }
            function drawTourMap(dayRoutes, helpText) {
                const help = document.getElementById('tour-map-help');
                const mapElement = document.getElementById('tour-route-map');
                const validRoutes = (dayRoutes || []).map(day => ({ ...day, points: (day.points || []).filter(item => item.point) })).filter(day => day.points.length);
                tourMapOverlays.forEach(overlay => overlay.setMap(null));
                tourMapOverlays = [];
                if (!validRoutes.length) {
                    help.textContent = 'Choose places with a mapped location to see them here.';
                    if (tourMap) {
                        tourMap.setCenter({ lat: 7.8731, lng: 80.7718 });
                        tourMap.setZoom(7);
                    }
                    return;
                }
                if (!window.google?.maps) { help.textContent = 'Loading Google Maps…'; return; }
                if (!tourMap) {
                    tourMap = new google.maps.Map(mapElement, {
                        center: { lat: 7.8731, lng: 80.7718 },
                        zoom: 7,
                        mapTypeControl: false,
                        streetViewControl: false,
                        fullscreenControl: true,
                        gestureHandling: 'cooperative',
                    });
                }
                const bounds = [];
                const colors = ['#bf2629', '#126b9a', '#23835b', '#7650a0', '#c27616'];
                validRoutes.forEach((day, dayIndex) => {
                    const color = colors[dayIndex % colors.length];
                    const points = day.points.map(item => ({ lat: item.point[0], lng: item.point[1] }));
                    points.forEach((point, index) => {
                        bounds.push(point);
                        tourMapOverlays.push(new google.maps.Marker({
                            map: tourMap,
                            position: point,
                            title: `Day ${day.day}: ${itemName(day.points[index].name)}`,
                            label: { text: String(index + 1), color: '#fff', fontWeight: '700' },
                            icon: { path: google.maps.SymbolPath.CIRCLE, scale: 13, fillColor: color, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
                        }));
                    });
                    if (points.length > 1) tourMapOverlays.push(new google.maps.Polyline({ map: tourMap, path: points, strokeColor: color, strokeOpacity: 0.85, strokeWeight: 4 }));
                });
                if (bounds.length > 1) {
                    const extent = new google.maps.LatLngBounds();
                    bounds.forEach(point => extent.extend(point));
                    tourMap.fitBounds(extent, 48);
                }
                else tourMap.setCenter(bounds[0]);
                if (bounds.length === 1) tourMap.setZoom(9);
                help.textContent = helpText || 'Stops are numbered in visit order. The line shows the order between places, not the road-by-road route.';
            }
            function itemName(value) { return String(value ?? 'Tour stop'); }
            function renderSelectedTourMap() {
                routeMapOptions = null;
                document.getElementById('tour-map-route-options').hidden = true;
                drawTourMap(selectedTourMapStops());
            }
            function renderComparedTourMap(routeOptions) {
                routeMapOptions = routeOptions;
                const hasSuggestion = routeOptions?.changed === true;
                mapRouteSelection = hasSuggestion && routeOptions?.[preferredRouteChoice] ? preferredRouteChoice : 'original';
                document.getElementById('tour-map-route-options').hidden = !hasSuggestion;
                drawComparedTourMap(mapRouteSelection);
            }
            function setClassPriceEstimates(routeOptions, routeChoice) {
                classPriceEstimates.clear();
                classPriceRouteLabel = routeChoice === 'original' ? 'EST. YOUR ORDER' : 'EST. SUGGESTED ROUTE';
                (routeOptions?.class_estimates || []).forEach(item => {
                    if (item[routeChoice]) classPriceEstimates.set(String(item.vehicle_class_id), item[routeChoice]);
                });
                renderClassCards();
            }
            function drawComparedTourMap(which) {
                const route = routeMapOptions?.[which];
                if (!route) return renderSelectedTourMap();
                mapRouteSelection = which;
                const destinations = new Map((catalogue?.destinations || []).map(item => [String(item.id), item]));
                const activities = new Map((catalogue?.destinations || []).flatMap(item => (item.activities || []).map(activity => [String(activity.id), activity])));
                const days = Number(document.getElementById('tour-days').value) || 1;
                const endpoints = [];
                for (let day = 1; day <= days; day++) {
                    const startId = day === 1 ? startSelect.value : document.querySelector(`.tour-day-end[data-day="${day - 1}"]`)?.value;
                    const endId = document.querySelector(`.tour-day-end[data-day="${day}"]`)?.value;
                    endpoints.push({ day, start: destinations.get(String(startId)), end: destinations.get(String(endId)) });
                }
                const routeDays = Array.from({ length: days }, (_, index) => ({ day: index + 1, points: [] }));
                (route.stops || []).forEach(stop => {
                    const day = Number(stop.day_number) || 1;
                    const plan = routeDays[day - 1];
                    if (!plan) return;
                    let location = stop.kind === 'activity' ? activities.get(String(stop.catalogue_id))?.location
                        : stop.kind === 'destination' ? destinations.get(String(stop.catalogue_id))?.location : null;
                    if (!location) {
                        const endpoint = endpoints[day - 1];
                        location = String(stop.id || '').startsWith('start-') ? endpoint?.start?.location : endpoint?.end?.location;
                    }
                    plan.points.push({ name: stop.name, point: tourLocationPoint(location) || tourLocationPoint(stop) });
                });
                drawTourMap(routeDays, `${which === 'suggested' ? 'Suggested route' : 'Your selected order'} shown. Lines connect stops in order and do not show exact roads.`);
                document.querySelectorAll('[data-map-route]').forEach(button => button.classList.toggle('active', button.dataset.mapRoute === which));
            }
            const initializeGoogleMaps = window.initializeGoogleMaps;
            window.initializeGoogleMaps = function () {
                if (typeof initializeGoogleMaps === 'function') initializeGoogleMaps();
                if (routeMapOptions) drawComparedTourMap(mapRouteSelection);
                else renderSelectedTourMap();
            };
            stopTextSearch?.addEventListener('input', () => {
                applyDestinationCategoryFilter();
            });
            document.getElementById('tour-day-selected-strip')?.addEventListener('click', event => {
                const remove = event.target.closest('[data-remove-day-stop]');
                if (!remove) return;
                const selector = remove.dataset.removeDayStop === 'destination' ? '.tour-destination' : '.tour-activity';
                const input = [...destinationList.querySelectorAll(selector)].find(item => item.value === remove.dataset.stopId);
                if (!input) return;
                input.checked = false;
                syncStopSearchSelection();
                syncStopDaySelectors();
                applyDestinationCategoryFilter();
                renderSelectedTourMap();
                invalidateDisplayedQuote();
                renderTourSummaryDetails();
                scheduleAutomaticPriceRefresh();
            });
            destinationList.addEventListener('click', event => {
                const moveBtn = event.target.closest('[data-move-to-active-day]');
                if (!moveBtn || !activeEditDay) return;
                const placeId = moveBtn.dataset.moveToActiveDay;
                assignStopToDay(`destination:${placeId}`, activeEditDay);
                syncStopDaySelectors();
                applyDestinationCategoryFilter();
                renderSelectedTourMap();
                invalidateDisplayedQuote();
                renderTourSummaryDetails();
                scheduleAutomaticPriceRefresh();
            });
            destinationList.addEventListener('change', event => {
                if (!event.target.matches('.tour-destination, .tour-activity')) return;
                if (activeEditDay && event.target.checked) assignStopToDay(`${event.target.matches('.tour-destination') ? 'destination' : 'activity'}:${event.target.value}`, activeEditDay);
                syncStopSearchSelection();
                syncStopDaySelectors();
                applyDestinationCategoryFilter();
                renderSelectedTourMap();
                renderTourItinerarySummary();
                renderTourSummaryDetails();
                invalidateDisplayedQuote();
                scheduleAutomaticPriceRefresh();
            });
            function syncStopSearchSelection() {
                const selected = [
                    ...[...destinationList.querySelectorAll('.tour-destination:checked')].map(input => `destination:${input.value}`),
                    ...[...destinationList.querySelectorAll('.tour-activity:checked')].map(input => `activity:${input.value}`),
                ];
                knownStopSelection = new Set(selected);
            }
            function assignStopToDay(stopId, day) {
                const separator = stopId.indexOf(':');
                const kind = stopId.slice(0, separator);
                const id = stopId.slice(separator + 1);
                if (kind === 'destination') {
                    const input = [...destinationList.querySelectorAll('.tour-destination')].find(item => item.value === id);
                    const select = input && document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`);
                    if (select) select.value = String(day);
                } else if (kind === 'activity') {
                    const input = [...destinationList.querySelectorAll('.tour-activity')].find(item => item.value === id);
                    const parent = input?.closest('.tour-highlight-item')?.querySelector('.tour-destination');
                    const select = parent && document.getElementById(`tour-place-day-${parent.id.replace('tour-destination-', '')}`);
                    if (select) select.value = String(day);
                }
                syncActivityDaysFromDestinations();
            }
            function renderDestinationCategoryFilters(destinations) {
                const counts = new Map();
                destinations.forEach(item => {
                    const category = item.category || 'Other';
                    counts.set(category, (counts.get(category) || 0) + 1);
                });
                if (!['route', 'selected', 'all'].includes(activeDestinationCategory) && !counts.has(activeDestinationCategory)) activeDestinationCategory = 'route';
                categoryFilters.innerHTML = `<button type="button" class="btn btn-sm btn-outline-secondary tour-category-chip ${activeDestinationCategory === 'route' ? 'active' : ''}" data-category-filter="route">Near your route</button><button type="button" class="btn btn-sm btn-outline-secondary tour-category-chip ${activeDestinationCategory === 'selected' ? 'active' : ''}" data-category-filter="selected">Selected (0)</button><button type="button" class="btn btn-sm btn-outline-secondary tour-category-chip ${activeDestinationCategory === 'all' ? 'active' : ''}" data-category-filter="all">All places (${destinations.length})</button>${[...counts].map(([category, count]) => `<button type="button" class="btn btn-sm btn-outline-secondary tour-category-chip ${activeDestinationCategory === category ? 'active' : ''}" data-category-filter="${escapeHtml(category)}">${escapeHtml(category)} (${count})</button>`).join('')}`;
                categoryFilters.querySelectorAll('[data-category-filter]').forEach(button => button.addEventListener('click', () => {
                    activeDestinationCategory = button.dataset.categoryFilter;
                    categoryFilters.querySelectorAll('[data-category-filter]').forEach(option => option.classList.toggle('active', option === button));
                    applyDestinationCategoryFilter();
                }));
                applyDestinationCategoryFilter();
            }
            function destinationsNearSelectedRoute() {
                const destinations = catalogue?.destinations || [];
                const byId = new Map(destinations.map(item => [String(item.id), item]));
                const routeIds = [startSelect.value, ...[...daySettings.querySelectorAll('.tour-day-end')].map(select => select.value)].filter(Boolean).map(String);
                const anchors = routeIds.map(id => tourLocationPoint(byId.get(id)?.location)).filter(Boolean);
                const nearby = new Set(routeIds);
                const corridorKm = 55;
                const distanceToSegmentKm = (point, start, end) => {
                    const radians = Math.PI / 180;
                    const scaleX = 111.32 * Math.cos(point[0] * radians);
                    const ax = (start[1] - point[1]) * scaleX;
                    const ay = (start[0] - point[0]) * 110.574;
                    const bx = (end[1] - point[1]) * scaleX;
                    const by = (end[0] - point[0]) * 110.574;
                    const dx = bx - ax;
                    const dy = by - ay;
                    const lengthSquared = dx * dx + dy * dy;
                    const progress = lengthSquared ? Math.max(0, Math.min(1, -((ax * dx) + (ay * dy)) / lengthSquared)) : 0;
                    return Math.hypot(ax + progress * dx, ay + progress * dy);
                };
                destinations.forEach(destination => {
                    if (nearby.has(String(destination.id))) return;
                    const points = [destination.location, ...(destination.activities || []).map(activity => activity.location)]
                        .map(tourLocationPoint).filter(Boolean);
                    const closeToRoute = points.some(point => anchors.length === 1
                        ? distanceToSegmentKm(point, anchors[0], anchors[0]) <= corridorKm
                        : anchors.slice(1).some((anchor, index) => distanceToSegmentKm(point, anchors[index], anchor) <= corridorKm));
                    if (closeToRoute) nearby.add(String(destination.id));
                });
                return nearby;
            }
            function haversineKm(a, b) {
                if (!a || !b) return 0;
                const rad = Math.PI / 180;
                const dLat = (b[0] - a[0]) * rad;
                const dLon = (b[1] - a[1]) * rad;
                const s1 = Math.sin(dLat / 2);
                const s2 = Math.sin(dLon / 2);
                const h = s1 * s1 + Math.cos(a[0] * rad) * Math.cos(b[0] * rad) * s2 * s2;
                return 6371 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
            }
            function estimateDayMetrics(startPoint, stopPoints, endPoint) {
                const pts = [startPoint, ...stopPoints, endPoint].filter(Boolean);
                if (pts.length < 2) return { km: 0, driveHours: 0, detourKm: 0 };
                let straightKm = 0;
                for (let i = 1; i < pts.length; i++) straightKm += haversineKm(pts[i - 1], pts[i]);
                const directKm = (startPoint && endPoint) ? haversineKm(startPoint, endPoint) : straightKm;
                const roadKm = Math.round(straightKm * 1.32);
                const detourKm = Math.round(Math.max(0, straightKm - directKm) * 1.32);
                const driveHours = roadKm / 45;
                return { km: roadKm, driveHours, detourKm };
            }
            function formatDurationHours(hours) {
                if (!hours || hours <= 0) return '';
                const totalMins = Math.round(hours * 60);
                const h = Math.floor(totalMins / 60);
                const m = totalMins % 60;
                return h ? (m ? `${h}h ${m}m` : `${h}h`) : `${m}m`;
            }
            function updateDayEditorStripAndBadges() {
                const strip = document.getElementById('tour-day-selected-strip');
                const summaryEl = document.getElementById('tour-day-editor-summary');
                if (!catalogue) return;
                const destinations = new Map((catalogue.destinations || []).map(item => [String(item.id), item]));
                const activities = new Map((catalogue.destinations || []).flatMap(item => (item.activities || []).map(activity => [String(activity.id), activity])));
                const day = activeEditDay || 1;
                const dayDest = [...destinationList.querySelectorAll('.tour-destination:checked')]
                    .filter(input => Number(document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`)?.value) === day)
                    .map(input => ({ kind: 'destination', id: input.value, name: destinations.get(String(input.value))?.name }));
                const dayAct = [...destinationList.querySelectorAll('.tour-activity:checked')]
                    .filter(input => Number(document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(input.value)}"]`)?.value) === day)
                    .map(input => {
                        const act = activities.get(String(input.value));
                        return { kind: 'activity', id: input.value, name: act?.name, mins: Number(act?.visit_duration_minutes || 0) };
                    });
                const items = [...dayDest, ...dayAct].filter(item => item.name);
                if (strip) {
                    strip.innerHTML = items.length
                        ? `<span class="small fw-semibold tour-muted">Day ${day}:</span>` + items.map(item => `<button type="button" class="tour-stop-chip" data-remove-day-stop="${item.kind}" data-stop-id="${escapeHtml(item.id)}">${escapeHtml(item.name)} ×</button>`).join('')
                        : '';
                    strip.hidden = items.length === 0;
                }
                const totalMins = dayAct.reduce((sum, a) => sum + a.mins, 0);
                if (summaryEl) {
                    const parts = [`${dayDest.length} ${dayDest.length === 1 ? 'place' : 'places'}`];
                    if (dayAct.length) parts.push(`${dayAct.length} ${dayAct.length === 1 ? 'activity' : 'activities'}`);
                    if (totalMins > 0) parts.push(`~${formatDurationHours(totalMins / 60)} sightseeing`);
                    summaryEl.textContent = `Day ${day}: ${parts.join(' • ')}`;
                }
                destinationList.querySelectorAll('.tour-highlight-item').forEach(card => {
                    const input = card.querySelector('.tour-destination');
                    if (!input) return;
                    const idx = input.id.replace('tour-destination-', '');
                    const daySelect = document.getElementById(`tour-place-day-${idx}`);
                    const assignedDay = Number(daySelect?.value || 0);
                    const hasActivity = [...card.querySelectorAll('.tour-activity')].some(a => a.checked);
                    const isSelected = input.checked || hasActivity;
                    const badgeWrap = card.querySelector('.tour-card-day-status');
                    if (!badgeWrap) return;
                    if (!isSelected || !assignedDay) {
                        badgeWrap.innerHTML = '';
                    } else if (activeEditDay && assignedDay === activeEditDay) {
                        badgeWrap.innerHTML = `<span class="tour-stop-chip">✓ On Day ${activeEditDay}</span>`;
                    } else if (activeEditDay) {
                        badgeWrap.innerHTML = `<span class="tour-other-day-badge">On Day ${assignedDay}</span> <button type="button" class="btn btn-link btn-sm p-0 ms-1 fw-semibold text-decoration-none" data-move-to-active-day="${escapeHtml(input.value)}">Move to Day ${activeEditDay}</button>`;
                    } else {
                        badgeWrap.innerHTML = `<span class="tour-other-day-badge">Day ${assignedDay}</span>`;
                    }
                });
            }
            function applyDestinationCategoryFilter() {
                const cards = [...destinationList.querySelectorAll('.tour-highlight-item')];
                const selectedIds = new Set([...destinationList.querySelectorAll('.tour-destination:checked')].map(input => input.value));
                destinationList.querySelectorAll('.tour-activity:checked').forEach(input => {
                    const parent = input.closest('.tour-highlight-item')?.querySelector('.tour-destination');
                    if (parent) selectedIds.add(parent.value);
                });
                const selectedButton = categoryFilters.querySelector('[data-category-filter="selected"]');
                if (selectedButton) selectedButton.textContent = `Selected (${selectedIds.size})`;
                const routeIds = destinationsNearSelectedRoute();
                const query = (stopTextSearch?.value || '').trim().toLowerCase();
                let visible = 0;
                cards.forEach(card => {
                    const placeId = card.querySelector('.tour-destination')?.value;
                    const matchesCategory = activeDestinationCategory === 'all'
                        || (activeDestinationCategory === 'route' && routeIds.has(String(placeId)))
                        || (activeDestinationCategory === 'selected' && selectedIds.has(placeId))
                        || (activeDestinationCategory !== 'route' && activeDestinationCategory !== 'selected' && activeDestinationCategory !== 'all' && card.dataset.category === activeDestinationCategory);
                    const matchesQuery = !query || (card.dataset.searchText || '').includes(query);
                    const show = matchesCategory && matchesQuery;
                    card.hidden = !show;
                    if (show) visible++;
                });
                categoryFilters.querySelectorAll('[data-category-filter]').forEach(button => button.classList.toggle('active', button.dataset.categoryFilter === activeDestinationCategory));
                const empty = document.getElementById('tour-destination-empty');
                if (query && visible === 0) {
                    highlightCount.textContent = '0 matches';
                    empty.textContent = `No places or activities match "${stopTextSearch.value.trim()}". Try clearing the search or switching to "All places".`;
                    empty.hidden = false;
                } else if (activeDestinationCategory === 'route') {
                    highlightCount.textContent = `${visible} ${visible === 1 ? 'place' : 'places'} near your trip`;
                    empty.textContent = 'No mapped places are near this trip yet. Choose All places to browse every destination.';
                    empty.hidden = visible > 0;
                } else if (activeDestinationCategory === 'selected') {
                    highlightCount.textContent = `${visible} selected ${visible === 1 ? 'place' : 'places'}`;
                    empty.textContent = 'No places selected yet. Search or browse places below to add them.';
                    empty.hidden = visible > 0;
                } else {
                    highlightCount.textContent = `${visible} ${visible === 1 ? 'place' : 'places'}`;
                    empty.hidden = visible > 0;
                    empty.textContent = 'No places in this category.';
                }
                updateDayEditorStripAndBadges();
            }
            function renderTourItinerarySummary() {
                if (!catalogue || !itineraryDaysSummary) return;
                const destinations = new Map((catalogue.destinations || []).map(item => [String(item.id), item]));
                const activities = new Map((catalogue.destinations || []).flatMap(item => (item.activities || []).map(activity => [String(activity.id), activity])));
                const totalDays = Math.max(1, Number(document.getElementById('tour-days').value) || 1);
                const stopCount = destinationList.querySelectorAll('.tour-destination:checked, .tour-activity:checked').length;
                document.getElementById('tour-stop-count').textContent = `${stopCount} ${stopCount === 1 ? 'stop' : 'stops'} added`;
                const optActions = document.getElementById('tour-optimize-actions');
                if (optActions) optActions.hidden = destinationList.querySelectorAll('.tour-destination:checked').length < 2;
                itineraryDaysSummary.innerHTML = Array.from({ length: totalDays }, (_, index) => {
                    const day = index + 1;
                    const startId = day === 1 ? startSelect.value : document.querySelector(`.tour-day-end[data-day="${day - 1}"]`)?.value;
                    const endId = document.querySelector(`.tour-day-end[data-day="${day}"]`)?.value;
                    const startItem = destinations.get(String(startId));
                    const endItem = destinations.get(String(endId));
                    const startName = startItem?.name || 'Choose a start place';
                    const endName = endItem?.name || (day === totalDays ? 'Choose a finish place' : 'Choose an overnight place');
                    const destStops = [...destinationList.querySelectorAll('.tour-destination:checked')]
                        .filter(input => Number(document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`)?.value) === day)
                        .map(input => ({ kind: 'destination', id: input.value, item: destinations.get(String(input.value)) }))
                        .filter(s => s.item);
                    const actStops = [...destinationList.querySelectorAll('.tour-activity:checked')]
                        .filter(input => Number(document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(input.value)}"]`)?.value) === day)
                        .map(input => ({ kind: 'activity', id: input.value, item: activities.get(String(input.value)) }))
                        .filter(s => s.item);
                    const stops = [
                        ...destStops.map(s => ({ kind: 'destination', id: s.id, name: s.item.name })),
                        ...actStops.map(s => ({ kind: 'activity', id: s.id, name: s.item.name })),
                    ];
                    const stopPoints = [
                        ...destStops.map(s => tourLocationPoint(s.item.location)),
                        ...actStops.map(s => tourLocationPoint(s.item.location)),
                    ].filter(Boolean);
                    const metrics = estimateDayMetrics(tourLocationPoint(startItem?.location), stopPoints, tourLocationPoint(endItem?.location));
                    const actMins = actStops.reduce((sum, s) => sum + Number(s.item.visit_duration_minutes || 0), 0);
                    const totalHours = metrics.driveHours + (actMins / 60);
                    const metaParts = [];
                    if (metrics.km > 0) metaParts.push(`~${metrics.km} km • ~${formatDurationHours(metrics.driveHours)} drive`);
                    if (actMins > 0) metaParts.push(`+${formatDurationHours(actMins / 60)} activities`);
                    let warningHtml = '';
                    if (metrics.driveHours > 6.5 || totalHours > 10) {
                        warningHtml = `<div class="tour-itinerary-warning"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span>Heavy day (~${formatDurationHours(totalHours)} total) — consider splitting or moving a stop.</span></div>`;
                    } else if (metrics.detourKm > 85) {
                        warningHtml = `<div class="tour-itinerary-warning"><i class="bi bi-signpost-split-fill" aria-hidden="true"></i><span>Includes a ~${metrics.detourKm} km detour off the direct route.</span></div>`;
                    }
                    const routeLine = [startName, endName].join(' → ');
                    return `<div class="tour-itinerary-day"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><div class="d-flex flex-wrap align-items-center gap-2"><span class="tour-day-badge">Day ${day}</span><span class="tour-itinerary-route">${escapeHtml(routeLine)}</span></div><span class="small tour-muted">${day === totalDays ? 'Finish' : `Overnight: ${escapeHtml(endName)}`}</span></div>${metaParts.length ? `<div class="tour-itinerary-meta mt-1"><i class="bi bi-clock" aria-hidden="true"></i><span>${escapeHtml(metaParts.join(' · '))}</span></div>` : ''}${warningHtml}${stops.length ? `<div class="d-flex flex-wrap gap-2 mt-2">${stops.map(stop => `<button type="button" class="tour-stop-chip" data-remove-stop="${stop.kind}" data-stop-id="${escapeHtml(stop.id)}" aria-label="Remove ${escapeHtml(stop.name)} from day ${day}">${escapeHtml(stop.name)} ×</button>`).join('')}</div>` : ''}<div class="d-flex flex-wrap gap-2 mt-2"><button type="button" class="tour-itinerary-actions" data-add-stop-day="${day}"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add places to Day ${day}</button><button type="button" class="tour-itinerary-actions" data-edit-day="${day}"><i class="bi bi-pencil" aria-hidden="true"></i> Edit Day ${day}</button></div></div>`;
                }).join('');
            }
            itineraryDaysSummary.addEventListener('click', event => {
                const addStop = event.target.closest('[data-add-stop-day]');
                if (addStop) { openTourDayEditor(Number(addStop.dataset.addStopDay), true); return; }
                const editDay = event.target.closest('[data-edit-day]');
                if (editDay) { openTourDayEditor(Number(editDay.dataset.editDay)); return; }
                const remove = event.target.closest('[data-remove-stop]');
                if (!remove) return;
                const selector = remove.dataset.removeStop === 'destination' ? '.tour-destination' : '.tour-activity';
                const input = [...destinationList.querySelectorAll(selector)].find(item => item.value === remove.dataset.stopId);
                if (!input) return;
                input.checked = false;
                syncStopSearchSelection();
                syncStopDaySelectors();
                renderSelectedTourMap();
                invalidateDisplayedQuote();
                renderTourSummaryDetails();
                scheduleAutomaticPriceRefresh();
            });
            function minimumVisitDay(destinationId, locationId) {
                const destinations = new Map((catalogue?.destinations || []).map(item => [String(item.id), item]));
                const assignedDays = [...daySettings.querySelectorAll('.tour-day-end')]
                    .filter(select => {
                        const endpoint = destinations.get(String(select.value));
                        return String(select.value) === String(destinationId)
                            || (locationId && String(endpoint?.location?.id) === String(locationId));
                    })
                    .map(select => Number(select.dataset.day))
                    .filter(Number.isFinite);
                return assignedDays.length ? Math.max(1, Math.min(...assignedDays)) : 1;
            }
            function refreshVisitDayOptions() {
                const tripDays = Math.max(1, Number(document.getElementById('tour-days').value) || 1);
                const update = (select, minimumDay, checked) => {
                    const current = Number(select.value);
                    const nextDay = current >= minimumDay && current <= tripDays ? current : (checked ? minimumDay : 0);
                    select.innerHTML = `<option value="">Choose a day</option>${Array.from({ length: Math.max(0, tripDays - minimumDay + 1) }, (_, index) => {
                        const day = minimumDay + index;
                        return `<option value="${day}" ${nextDay === day ? 'selected' : ''}>Day ${day}</option>`;
                    }).join('')}`;
                    select.value = nextDay ? String(nextDay) : '';
                };
                destinationList.querySelectorAll('.tour-destination').forEach(input => {
                    const select = document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`);
                    const destination = catalogue?.destinations?.find(item => String(item.id) === String(input.value));
                    const hasSelectedActivity = [...input.closest('.tour-highlight-item').querySelectorAll('.tour-activity')].some(activity => activity.checked);
                    if (select && destination) update(select, minimumVisitDay(destination.id, destination.location?.id), input.checked || hasSelectedActivity);
                });
                syncActivityDaysFromDestinations();
            }
            function syncActivityDaysFromDestinations() {
                destinationList.querySelectorAll('.tour-activity-day').forEach(input => {
                    input.value = document.getElementById(`tour-place-day-${input.dataset.destinationIndex}`)?.value || '';
                });
            }
            function syncStopDaySelectors() {
                refreshVisitDayOptions();
                destinationList.querySelectorAll('.tour-destination').forEach(input => {
                    const daySelect = document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`);
                    const control = daySelect?.closest('.tour-stop-day-control');
                    if (!daySelect || !control) return;
                    const hasSelectedActivity = [...input.closest('.tour-highlight-item').querySelectorAll('.tour-activity')].some(activity => activity.checked);
                    const selectedForDay = input.checked || hasSelectedActivity;
                    control.hidden = !selectedForDay;
                    daySelect.disabled = !selectedForDay;
                    if (!selectedForDay) daySelect.value = '';
                });
                syncActivityDaysFromDestinations();
                renderTourItinerarySummary();
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
            function currentService() { return selectedService; }
            function renderTourActivity(activity, destinationIndex, destinationId, selected, visitDay) {
                const name = `<strong>${escapeHtml(activity.name)}</strong>${activity.visit_duration_minutes ? ` <span class="tour-muted">(${escapeHtml(activity.visit_duration_minutes)} min)</span>` : ''}${activity.description ? `<div class="small tour-muted ms-4">${escapeHtml(activity.description)}</div>` : ''}`;
                if (!activity.is_selectable) return `<div class="mb-2 pb-2 border-bottom border-light small">${name}</div>`;
                return `<div class="mb-2 pb-2 border-bottom border-light"><label class="form-check small mb-1" style="cursor: pointer;"><input class="form-check-input tour-activity" type="checkbox" value="${escapeHtml(activity.id)}" id="tour-activity-${destinationIndex}-${escapeHtml(activity.id)}" ${selected ? 'checked' : ''}><span class="form-check-label">${name}</span></label><input type="hidden" class="tour-activity-day" data-activity="${escapeHtml(activity.id)}" data-destination-id="${escapeHtml(destinationId)}" data-destination-index="${destinationIndex}" value="${escapeHtml(visitDay || '')}"></div>`;
            }
            function fillOptions() {
                const service = currentService();
                const packages = service?.packages || [];
                classSelect.innerHTML = '<option value="">Choose a vehicle type</option>' + (service?.classes || []).map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.display_name || item.name)}${item.max_passengers ? ` — up to ${escapeHtml(item.max_passengers)} passengers` : ''}</option>`).join('');
                packageSelect.innerHTML = '<option value="">Choose later</option>' + packages.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
                if (packages.length === 1) packageSelect.value = packages[0].id;
                const packageField = document.getElementById('tour-package-field');
                packageField.hidden = packages.length <= 1;
                packageField.classList.toggle('col-md-12', packages.length > 1);
                packageField.classList.toggle('col-md-6', packages.length <= 1);
                document.getElementById('tour-ai-helper').hidden = !service?.ai_itinerary_helper_enabled;
                document.getElementById('tour-ai-preview').hidden = true;
                document.getElementById('tour-ai-status').textContent = '';
                limitPackageClasses();
                renderCustomFields();
            }
            function renderCustomFields() {
                const fields = (currentService()?.form_fields || []).filter(field => !['contact_whatsapp', 'special_requests'].includes(field.name));
                document.getElementById('tour-custom-fields').innerHTML = fields.map(field => {
                    const id = `tour-custom-${field.name}`; const required = field.required ? 'required' : ''; const label = escapeHtml(field.label);
                    let control = '';
                    if (field.type === 'textarea') control = `<textarea class="form-control" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required} placeholder="${escapeHtml(field.placeholder || '')}"></textarea>`;
                    else if (field.type === 'select') control = `<select class="form-select no-nice" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required}><option value="">Choose</option>${(field.options || []).map(option => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`).join('')}</select>`;
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
                let fittingCount = 0;
                cards.innerHTML = (currentService()?.classes || []).map((item, index) => {
                    const packageAllowed = !allowed || allowed.has(item.id);
                    const capacityFits = partySize > 0 && partySize <= Number(item.max_passengers) && luggage <= Number(item.max_luggage);
                    if (packageAllowed && capacityFits) fittingCount++;
                    const disabled = !packageAllowed || !capacityFits;
                    if (classSelect.value === item.id && disabled) classSelect.value = '';
                    const selected = classSelect.value === item.id;
                    const reason = !packageAllowed ? 'Not offered with this package' : !capacityFits ? 'Does not fit the selected travellers or luggage' : '';
                    const estimate = classPriceEstimates.get(String(item.id));
                    const estimatePrice = estimate?.status === 'priced' && Number.isFinite(Number(estimate.total_amount))
                        ? `<span class="tour-class-estimate"><small>${escapeHtml(classPriceRouteLabel || 'ESTIMATED TOTAL')}</small><strong>${escapeHtml(estimate.currency || 'LKR')} ${Number(estimate.total_amount).toLocaleString(undefined, { maximumFractionDigits: 0 })}</strong><small>for this Tour</small></span>`
                        : `<span class="tour-class-estimate"><small>${estimate?.status === 'quotation_required' ? 'PRICE' : 'ESTIMATED PRICE'}</small><strong>${estimate?.status === 'quotation_required' ? 'Team review' : 'After route plan'}</strong></span>`;
                    return `<div class="col-md-6"><label class="tour-option tour-class-option d-flex flex-column position-relative ${disabled ? 'opacity-50' : ''}" for="tour-class-choice-${index}"><input class="form-check-input position-absolute top-0 end-0 m-2 tour-class-choice" type="radio" name="tour_class_choice" id="tour-class-choice-${index}" value="${escapeHtml(item.id)}" ${selected ? 'checked' : ''} ${disabled ? 'disabled' : ''} required><div class="d-flex align-items-start justify-content-between gap-2 pe-4"><strong class="d-block text-dark">${escapeHtml(item.display_name || item.name)}</strong>${estimatePrice}</div>${item.description ? `<span class="d-block small tour-muted mt-1">${escapeHtml(item.description)}</span>` : ''}<div class="tour-class-meta small tour-muted mt-auto d-flex flex-wrap"><span>Up to ${escapeHtml(item.max_passengers)} travellers</span><span>${escapeHtml(item.max_luggage)} luggage items</span></div>${item.is_inquiry_only ? '<span class="d-block small text-warning fw-semibold mt-2">Availability confirmed by our team</span>' : ''}${reason ? `<span class="d-block small text-danger mt-2">${escapeHtml(reason)}</span>` : ''}</label></div>`;
                }).join('');
                const capWarn = document.getElementById('tour-capacity-warning');
                if (capWarn) {
                    if (partySize <= 0) {
                        capWarn.textContent = 'Add at least 1 traveller to see matching vehicles.';
                        capWarn.hidden = false;
                    } else if (fittingCount === 0 && (currentService()?.classes || []).length > 0) {
                        capWarn.textContent = `No single vehicle fits ${partySize} travellers and ${luggage} luggage items. Reduce luggage or contact us on WhatsApp for a multi-vehicle quote.`;
                        capWarn.hidden = false;
                    } else {
                        capWarn.hidden = true;
                    }
                }
                cards.querySelectorAll('.tour-class-choice').forEach(input => input.addEventListener('change', () => {
                    if (input.checked) {
                        classSelect.value = input.value;
                        results.hidden = true;
                        updateMobileSummaryBar();
                        trackTourEvent('tour_vehicle_selected', { vehicle_class_id: input.value });
                    }
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
                    const current = Number(select.value);
                    select.innerHTML = `<option value="">Choose a day</option>${Array.from({ length: count }, (_, day) => `<option value="${day + 1}" ${current === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('')}`;
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
                                const placeDay = activityDay && document.getElementById(`tour-place-day-${activityDay.dataset.destinationIndex}`);
                                if (placeDay) placeDay.value = String(day.day_number);
                            }
                        }
                    }
                }
                syncStopSearchSelection();
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
                    return `<select id="tour-day-end-${day}" class="tour-day-end" data-day="${day}" aria-hidden="true" tabindex="-1"><option value="${escapeHtml(startSelect.value)}">Return to start</option>${destinations.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('')}</select>`;
                }).join('');
                daySettings.querySelectorAll('.tour-day-end').forEach(select => { select.value = selectedEndForDay(priorEnds, Number(select.dataset.day), startSelect.value); });
                applyDestinationCategoryFilter();
                refreshVisitDayOptions();
                renderTourItinerarySummary();
            }
            function selectedEndForDay(priorEnds, day, startId) {
                const selected = priorEnds.get(day);
                return [...daySettings.querySelectorAll(`#tour-day-end-${day} option`)].some(option => option.value === selected) ? selected : startId;
            }
            function openTourDayEditor(day, focusSearch = false) {
                activeEditDay = day;
                focusStopSearchOnModalOpen = focusSearch;
                const endSelect = daySettings.querySelector(`.tour-day-end[data-day="${day}"]`);
                if (!endSelect) return;
                destroyTourSearch(dayEditorEnd);
                dayEditorEnd.innerHTML = endSelect.innerHTML;
                dayEditorEnd.value = endSelect.value;
                const isFinalDay = day === Number(document.getElementById('tour-days').value);
                document.getElementById('tour-day-editor-title').textContent = `Edit Day ${day}`;
                document.getElementById('tour-day-editor-end-label').textContent = `Day ${day} ${isFinalDay ? 'finish' : 'overnight'} location`;
                document.getElementById('tour-day-editor-start-wrap').hidden = day !== 1;
                const doneBtn = document.getElementById('tour-day-editor-done');
                if (doneBtn) doneBtn.textContent = `Save Day ${day}`;
                if (stopTextSearch) stopTextSearch.value = '';
                applyDestinationCategoryFilter();
                enhanceTourSearch(dayEditorEnd, 'Search listed places');
                trackTourEvent('tour_day_editor_open', { day });
                bootstrap.Modal.getOrCreateInstance(dayEditor).show();
            }
            window.jQuery(dayEditorEnd).on('change.tourDayEditor', () => {
                if (!activeEditDay) return;
                const endSelect = daySettings.querySelector(`.tour-day-end[data-day="${activeEditDay}"]`);
                if (!endSelect) return;
                endSelect.value = dayEditorEnd.value;
                refreshVisitDayOptions();
                applyDestinationCategoryFilter();
                renderSelectedTourMap();
                renderTourItinerarySummary();
                renderTourSummaryDetails();
                invalidateDisplayedQuote();
                scheduleAutomaticPriceRefresh();
            });
            dayEditor.addEventListener('shown.bs.modal', () => {
                applyDestinationCategoryFilter();
                if (!focusStopSearchOnModalOpen) return;
                focusStopSearchOnModalOpen = false;
                stopTextSearch?.focus();
            });
            dayEditor.addEventListener('hidden.bs.modal', () => {
                activeEditDay = null;
                if (stopTextSearch) stopTextSearch.value = '';
                applyDestinationCategoryFilter();
            });
            async function loadCatalogue() {
                const sameService = Boolean(catalogue);
                const previousStart = sameService ? startSelect.value : '';
                const previousDestinations = new Set(sameService ? [...document.querySelectorAll('.tour-destination:checked')].map(box => box.value) : []);
                const previousActivities = new Set(sameService ? [...document.querySelectorAll('.tour-activity:checked')].map(box => box.value) : []);
                const previousDays = new Map(sameService ? [...document.querySelectorAll('.tour-place-day')].map(select => [select.id.replace('tour-place-day-', ''), select.value]) : []);
                destroyTourSearch(startSelect);
                daySettings.querySelectorAll('.tour-day-end').forEach(destroyTourSearch);
                clearError(); results.hidden = true; destinationList.innerHTML = ''; daySettings.innerHTML = ''; startSelect.innerHTML = '<option value="">Choose a listed place</option>'; stopSearchSelect.innerHTML = '';
                const data = await request(`${api}/catalogue`);
                catalogue = data;
                const destinations = data.destinations || [];
                startSelect.innerHTML += destinations.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
                if (destinations.some(item => item.id === previousStart)) startSelect.value = previousStart;
                else {
                    const colombo = destinations.find(item => String(item.code || '').toLowerCase() === 'colombo'
                        || String(item.name || '').trim().toLowerCase() === 'colombo');
                    if (colombo) startSelect.value = colombo.id;
                }
                lastTourStart = startSelect.value;
                enhanceTourSearch(startSelect, 'Search a listed place');
                destinationList.innerHTML = destinations.map((item, index) => {
                    const searchBlob = [item.name, item.category, item.description, item.location?.city, ...(item.activities || []).map(a => a.name)].filter(Boolean).join(' ').toLowerCase();
                    const cat = (item.category || '').toLowerCase();
                    const seasonHint = cat.includes('east') ? 'Best May–Sep' : cat.includes('south') || cat.includes('west') ? 'Best Dec–Apr' : '';
                    return `<div class="col-xl-4 col-md-6 tour-highlight-item" data-category="${escapeHtml(item.category || 'Other')}" data-search-text="${escapeHtml(searchBlob)}"><div class="tour-option d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2"><div class="d-flex flex-wrap align-items-center gap-1"><span class="tour-highlight-category">${escapeHtml(item.category || 'Explore')}</span>${seasonHint ? `<span class="badge rounded-pill text-bg-light border tour-muted" style="font-size: 0.62rem;">${seasonHint}</span>` : ''}</div><span class="tour-card-day-status"></span></div>
                        <label class="form-check-label d-flex gap-2 align-items-start" style="cursor: pointer;"><input class="form-check-input mt-1 tour-destination" type="checkbox" value="${escapeHtml(item.id)}" id="tour-destination-${index}" ${previousDestinations.has(item.id) ? 'checked' : ''}><span><strong>Visit ${escapeHtml(item.name)}</strong><br><span class="small tour-muted tour-place-desc">${escapeHtml(item.description || item.location?.city || item.location?.address || '')}</span></span></label>
                        <div class="mt-2 tour-stop-day-control" hidden><label class="form-label mb-1" for="tour-place-day-${index}">Visit on</label><select class="form-select form-select-sm tour-place-day no-nice" id="tour-place-day-${index}" required disabled><option value="">Choose a day</option>${Array.from({ length: Number(document.getElementById('tour-days').value) || 1 }, (_, day) => `<option value="${day + 1}" ${Number(previousDays.get(String(index))) === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('')}</select></div>
                        ${(item.activities || []).length ? `<div class="border-top mt-3 pt-2 tour-destination-activities"><p class="small fw-bold text-uppercase tour-muted mb-2" style="letter-spacing: 0.04em; font-size: 0.68rem;">Activities at this place</p>${item.activities.map(activity => renderTourActivity(activity, index, item.id, previousActivities.has(activity.id), previousDays.get(String(index)))).join('')}</div>` : ''}
                    </div></div>`;
                }).join('');
                renderDestinationCategoryFilters(destinations);
                syncStopSearchSelection();
                syncStopDaySelectors();
                renderDaySettings();
                renderQuickTemplates();
            }
            function renderAiSuggestion(suggestion) {
                const destinations = new Map((catalogue?.destinations || []).map(item => [item.id, item]));
                const activities = new Map((catalogue?.destinations || []).flatMap(item => (item.activities || []).map(activity => [activity.id, { ...activity, destinationName: item.name }])));
                document.getElementById('tour-ai-preview').innerHTML = `<p class="small tour-muted mb-2">Review these ideas before adding them. You can change your choices afterward.</p>${suggestion.days.map(day => `<div class="mb-2"><strong>Day ${escapeHtml(day.day_number)}</strong><ul class="small mb-0">${day.stops.map(stop => { const place = stop.kind === 'activity' ? activities.get(stop.catalogue_id) : destinations.get(stop.catalogue_id); return `<li>${escapeHtml(place?.name || 'Place')}${stop.kind === 'activity' && place?.destinationName ? ` <span class="tour-muted">at ${escapeHtml(place.destinationName)}</span>` : ''}</li>`; }).join('') || '<li>Choose places yourself for this day.</li>'}</ul></div>`).join('')}<div class="d-flex flex-wrap gap-2 mt-3"><button id="tour-ai-apply" class="btn tour-btn-primary btn-sm" type="button">Add these ideas to my trip plan</button><button id="tour-ai-discard" class="btn btn-outline-secondary btn-sm" type="button">Discard ideas</button></div>`;
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
                                const activityDay = document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(activity.value)}"]`);
                                const placeDay = activityDay && document.getElementById(`tour-place-day-${activityDay.dataset.destinationIndex}`);
                                if (placeDay) placeDay.value = String(day.day_number);
                            }
                        }
                    }
                    selectedTemplateDays = [];
                    syncStopSearchSelection();
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
                if (selected.some(input => !Number(document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`).value))
                    || selectedActivities.some(input => !Number(document.querySelector(`.tour-activity-day[data-activity="${CSS.escape(input.value)}"]`)?.value))) {
                    throw new Error('Choose a day for every selected place and activity.');
                }
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
                    : data.changed
                        ? 'We found a shorter trip plan. Compare both options; visits may move between days while overnight and finish places stay fixed.'
                        : data.status === 'review_required'
                            ? 'This schedule has timing conflicts. Ask our team to review it before a transport price can be confirmed.'
                            : 'We did not find a shorter route to suggest. You can keep your selected route or ask our team to review it.';
                const formatRouteStops = schedule => schedule.map(stop => {
                    const endpoint = stop.stop_id?.startsWith('start-') ? 'Start: '
                        : stop.stop_id?.startsWith('end-') ? 'Finish: ' : '';
                    return `${endpoint}${escapeHtml(stop.stop_name)}`;
                }).join(' → ');
                const renderAggregate = (route, label) => {
                    const canQuote = route.quote?.status === 'priced' && !unavailable;
                    const canApplySuggestion = label === 'Suggested route' && data.changed && data.status !== 'unavailable';
                    const disabledLabel = route.quote?.status === 'quotation_required' || unavailable ? 'Our team needs to review this route' : 'Our team needs to check this price';
                    const canUse = canQuote || canApplySuggestion;
                    const buttonLabel = canQuote ? 'Use this route and review price' : canApplySuggestion ? 'Use suggested route (team review may be needed)' : disabledLabel;
                    return `<div class="col-md-6"><div class="tour-option d-flex flex-column h-100"><h3 class="h6 fw-bold mb-2">${escapeHtml(label)}</h3><p class="small mb-2"><strong>${route.distance_km == null ? 'Distance unavailable' : `${escapeHtml(route.distance_km)} km total`}</strong>${route.duration_seconds != null ? ` · ${Math.round(route.duration_seconds / 3600 * 10) / 10} hr driving` : ''}</p>${route.quote?.status === 'priced' ? `<p class="h5 fw-bold mb-3" style="color: var(--tour-primary);">${escapeHtml(route.quote.currency)} ${Number(route.quote.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>` : `<p class="small text-warning fw-semibold mb-3">${escapeHtml(route.quote?.reason || 'Our team needs to check this price.')}</p>`}<div class="mb-3">${(route.days || []).map(day => `<div class="tour-route-stop small"><span class="fw-bold">Day ${day.day_number}:</span> ${formatRouteStops(day.schedule || [])}</div>`).join('')}</div><button type="button" class="btn tour-btn-outline mt-auto tour-use-route" data-route="${label === 'Suggested route' ? 'suggested' : 'original'}" ${canUse ? '' : 'disabled'}>${buttonLabel}</button></div></div>`;
                };
                return `<div class="alert ${unavailable ? 'alert-warning' : 'alert-info'}">${routeMessage}</div><div class="row g-3">${renderAggregate(data.original, data.changed ? 'Your selected order' : 'Your route')}${data.changed ? renderAggregate(data.suggested, 'Suggested route') : ''}</div><div class="small tour-muted mt-3">${(data.issues || []).map(issue => `<p class="mb-1">Day ${issue.day_number}: ${escapeHtml(issue.message)}</p>`).join('')}<p class="mb-0">Activity visits are route stops only. Entry tickets and reservations are arranged separately.</p></div>`;
            }
            form.addEventListener('submit', async event => {
                event.preventDefault(); clearError(); invalidateDisplayedQuote();
                const button = document.getElementById('tour-plan'); button.disabled = true; document.getElementById('tour-working').hidden = false;
                const busyControls = [...form.querySelectorAll('input,select,textarea,button')].filter(control => control !== button);
                const priorDisabled = busyControls.map(control => control.disabled);
                busyControls.forEach(control => { control.disabled = true; });
                try {
                    const mobileNumber = mobileInput.value.trim();
                    const whatsappNumber = whatsappInput.value.trim();
                    if (mobilePhoneInput) {
                        await mobilePhoneInput.promise;
                        if (!mobileNumber || !mobilePhoneInput.isValidNumber()) throw new Error('Enter a valid mobile number, including its country code.');
                    } else if (!mobileNumber) {
                        throw new Error('Enter your mobile number.');
                    }
                    if (whatsappNumber && whatsappPhoneInput) {
                        await whatsappPhoneInput.promise;
                        if (!whatsappPhoneInput.isValidNumber()) throw new Error('Enter a valid WhatsApp number, including its country code.');
                    }
                    const payload = {
                        first_name: document.getElementById('tour-first-name').value,
                        last_name: document.getElementById('tour-last-name').value,
                        email: document.getElementById('tour-email').value,
                        phone: mobilePhoneInput ? mobilePhoneInput.getNumber() : mobileNumber,
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
                    if (whatsappNumber) payload.custom_answers.contact_whatsapp = whatsappPhoneInput ? whatsappPhoneInput.getNumber() : whatsappNumber;
                    const gear = selectedGearList();
                    if (gear.length) {
                        const existingNote = (payload.custom_answers.special_requests || '').trim();
                        const gearNote = `Requested extras: ${gear.join(', ')}`;
                        payload.custom_answers.special_requests = existingNote ? `${existingNote}\n${gearNote}` : gearNote;
                    }
                    if (!payload.vehicle_class_id) throw new Error('Choose a vehicle type.');
                    trackTourEvent('tour_quote_requested', { duration_days: payload.duration_days, vehicle_class_id: payload.vehicle_class_id });
                    const created = await request('/tours/drafts', { method: 'POST', body: JSON.stringify(payload) });
                    savedItinerary = created.itinerary;
                    const routes = created.route_options;
                    const hadPriorRouteOptions = !!currentRouteOptions;
                    currentRouteOptions = routes;
                    if (!routes.changed) {
                        preferredRouteChoice = 'original';
                    } else if (!hadPriorRouteOptions || routes[preferredRouteChoice]?.quote?.status !== 'priced') {
                        preferredRouteChoice = routes.suggested?.quote?.status === 'priced' ? 'suggested' : 'original';
                    }
                    document.getElementById('tour-summary-request-quote').hidden = false;
                    button.textContent = 'Update route and price';
                    results.innerHTML = renderRoute(routes) + `<div id="tour-quote" class="mt-4"></div>`;
                    results.hidden = false;
                    renderComparedTourMap(routes);
                    setClassPriceEstimates(routes, preferredRouteChoice);
                    showEstimatedSummary(routes, preferredRouteChoice);
                    results.querySelectorAll('.tour-use-route').forEach(button => button.addEventListener('click', async () => {
                        preferredRouteChoice = button.dataset.route;
                        currentTourQuote = null;
                        document.getElementById('tour-summary-checkout').hidden = true;
                        document.getElementById('tour-summary-terms').hidden = true;
                        setClassPriceEstimates(routes, button.dataset.route);
                        showEstimatedSummary(routes, preferredRouteChoice);
                        renderTourSummaryDetails(routes, preferredRouteChoice);
                        drawComparedTourMap(preferredRouteChoice);
                        button.disabled = true;
                        results.querySelectorAll('.tour-use-route').forEach(option => { option.disabled = true; });
                        try {
                            if (button.dataset.route === 'suggested' && routes.changed) {
                                const routeDays = (routes.days || []).map(day => ({ day_number: day.day_number, stop_ids: day.suggested_stop_ids || [] }));
                                const applied = await request(`/tours/drafts/${savedItinerary.id}/route`, { method: 'POST', body: JSON.stringify({ expected_revision: savedItinerary.revision, days: routeDays }) });
                                savedItinerary = applied;
                                currentRouteOptions = { ...routes, changed: false };
                            }
                            if (routes.status !== 'ready' || routes[button.dataset.route]?.quote?.status !== 'priced') {
                                showEstimatedSummary(routes, preferredRouteChoice);
                                document.getElementById('tour-quote').innerHTML = '<div class="alert alert-warning border small mb-0">This route has been saved. Our team will need to review it before confirming a price.</div>';
                                return;
                            }
                            const quote = await request(`/tours/drafts/${savedItinerary.id}/quote`, { method: 'POST', body: JSON.stringify({ expected_revision: savedItinerary.revision }) });
                            const q = quote;
                            const amount = Number(q.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            showQuoteSummary(q);
                            renderTourSummaryDetails(routes, preferredRouteChoice);
                            document.getElementById('tour-quote').innerHTML = `<div class="alert alert-success border small mb-0">${escapeHtml(q.currency)} ${amount} is held for 30 minutes. Your summary is ready to continue to checkout.</div>`;
                        } catch (exception) {
                            showError(exception.message || 'We could not prepare the quote. Please review your route and try again.');
                } finally { results.querySelectorAll('.tour-use-route').forEach(option => { const canApply = option.dataset.route === 'suggested' && currentRouteOptions?.changed && currentRouteOptions?.status !== 'unavailable'; option.disabled = !canApply && (currentRouteOptions?.[option.dataset.route]?.quote?.status !== 'priced' || currentRouteOptions?.status !== 'ready'); }); }
                    }));
                } catch (exception) { showError(exception.message || 'We could not plan this route. Please check your choices and try again.'); }
                finally { busyControls.forEach((control, index) => { control.disabled = priorDisabled[index]; }); button.disabled = false; document.getElementById('tour-working').hidden = true; }
            });
            packageSelect.addEventListener('change', () => { clearAiSuggestion(); limitPackageClasses(); applyPackageTemplate(); });
            function isPricingField(input) {
                return input.matches('#tour-date,#tour-days,#tour-time,#tour-adults,#tour-children,#tour-child-seats,#tour-luggage,#tour-package,#tour-class,#tour-start,.tour-class-choice,.tour-day-end,.tour-place-day,.tour-destination,.tour-activity,.tour-activity-day')
                    || (input.matches('[data-custom-name]') && !!input.closest('#tour-custom-fields'));
            }
            function handleTripFormChange(event) {
                const input = event.target;
                if (results.contains(input) || input.closest('#tour-ai-helper') || input.type === 'search') return;
                if (event.type === 'input' && !isPricingField(input)) return;
                if (event.type === 'change' && input.matches('.tour-day-end')) refreshVisitDayOptions();
                if (input.matches('.tour-place-day')) syncActivityDaysFromDestinations();
                if (input.closest('#tour-step-2')) {
                    applyDestinationCategoryFilter();
                    renderSelectedTourMap();
                    renderTourItinerarySummary();
                }
                renderTourSummaryDetails();
                if (isPricingField(input)) {
                    invalidateDisplayedQuote();
                    scheduleAutomaticPriceRefresh();
                }
            }
            form.addEventListener('input', handleTripFormChange);
            form.addEventListener('change', handleTripFormChange);
            document.getElementById('tour-map-route-options').addEventListener('click', event => {
                const button = event.target.closest('[data-map-route]');
                if (button) drawComparedTourMap(button.dataset.mapRoute);
            });
            ['tour-adults', 'tour-children', 'tour-luggage'].forEach(id => document.getElementById(id).addEventListener('input', () => { renderClassCards(); results.hidden = true; }));
            document.getElementById('tour-date').addEventListener('change', clearAiSuggestion);
            document.getElementById('tour-interests').addEventListener('input', clearAiSuggestion);
            document.getElementById('tour-days').addEventListener('change', () => {
                clearAiSuggestion();
                const count = Math.max(1, Math.min(60, Number(document.getElementById('tour-days').value) || 1));
                document.querySelectorAll('.tour-place-day').forEach(select => {
                    const selected = Number(select.value);
                    select.innerHTML = `<option value="">Choose a day</option>${Array.from({ length: count }, (_, day) => `<option value="${day + 1}" ${selected === day + 1 ? 'selected' : ''}>Day ${day + 1}</option>`).join('')}`;
                });
                syncStopDaySelectors();
                renderDaySettings(); invalidateDisplayedQuote(); renderSelectedTourMap();
            });
            window.jQuery(startSelect).off('.tourStart').on('change.tourStart', () => {
                const previousStart = lastTourStart;
                lastTourStart = startSelect.value;
                if (previousStart) daySettings.querySelectorAll('.tour-day-end').forEach(select => {
                    if (select.value === previousStart) select.value = lastTourStart;
                });
                renderDaySettings();
                applyDestinationCategoryFilter();
                renderSelectedTourMap();
                renderTourSummaryDetails();
                invalidateDisplayedQuote();
                scheduleAutomaticPriceRefresh();
            });
            document.getElementById('tour-send-code')?.addEventListener('click', async () => {
                const email = document.getElementById('tour-email').value.trim();
                if (!email) { showError('Enter your email first.'); return; }
                const status = document.getElementById('tour-code-status');
                try { const response = await request('/checkout/customer-email/send-code', { method: 'POST', body: JSON.stringify({ channel: 'email', value: email }) }); status.textContent = response.message || 'If these details match a previous booking, check your email for a code.'; document.getElementById('tour-code-panel').hidden = false; }
                catch (exception) { status.textContent = exception.message; }
            });
            document.getElementById('tour-verify-code')?.addEventListener('click', async () => {
                const status = document.getElementById('tour-code-status');
                try { const customer = await request('/checkout/customer-email/verify-code', { method: 'POST', body: JSON.stringify({ channel: 'email', value: document.getElementById('tour-email').value.trim(), code: document.getElementById('tour-code').value.trim() }) }); for (const [field, id] of [['first_name', 'tour-first-name'], ['last_name', 'tour-last-name'], ['email', 'tour-email']]) if (customer[field]) document.getElementById(id).value = customer[field]; if (customer.phone) mobilePhoneInput ? mobilePhoneInput.setNumber(customer.phone) : mobileInput.value = customer.phone; status.textContent = 'Your details are verified.'; }
                catch (exception) { status.textContent = exception.message; }
            });
            document.getElementById('tour-ai-suggest').addEventListener('click', async event => {
                const button = event.currentTarget;
                const status = document.getElementById('tour-ai-status');
                const requestData = {
                    duration_days: Number(document.getElementById('tour-days').value),
                    start_date: document.getElementById('tour-date').value || null,
                    interests: document.getElementById('tour-interests').value.trim(),
                };
                if (requestData.duration_days > 14) {
                    status.textContent = 'Trip ideas are available for trips up to 14 days. You can still plan longer trips manually.';
                    return;
                }
                const requestKey = JSON.stringify(requestData);
                button.disabled = true;
                status.textContent = 'Preparing trip ideas…';
                try {
                    const response = await request('/api/public/tours/suggestions', { method: 'POST', body: JSON.stringify(requestData) });
                    if (JSON.stringify({ duration_days: Number(document.getElementById('tour-days').value), start_date: document.getElementById('tour-date').value || null, interests: document.getElementById('tour-interests').value.trim() }) !== requestKey) {
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
            document.getElementById('tour-summary-terms').addEventListener('change', () => {
                const terms = [...document.querySelectorAll('#tour-summary-terms .tour-term')];
                document.getElementById('tour-summary-checkout').disabled = terms.some(term => !term.checked);
            });
            document.getElementById('tour-summary-checkout').addEventListener('click', async event => {
                const button = event.currentTarget;
                if (!currentTourQuote || !savedItinerary) return;
                const terms = [...document.querySelectorAll('#tour-summary-terms .tour-term')];
                if (terms.some(term => !term.checked)) { showError('Please accept each booking term before continuing.'); return; }
                button.disabled = true;
                try {
                    await request(`/tours/drafts/${savedItinerary.id}/quotes/${currentTourQuote.id}/accept`, { method: 'POST', body: JSON.stringify({ expected_revision: savedItinerary.revision, accept_terms: true }) });
                    const checkout = await request(`/tours/drafts/${savedItinerary.id}/booking`, { method: 'POST', body: JSON.stringify({ quote_id: currentTourQuote.id, expected_revision: savedItinerary.revision }) });
                    window.location.assign(checkout.checkout_url);
                } catch (exception) {
                    showError(exception.message || 'We could not submit the Tour booking. Please request an updated price and try again.');
                    button.disabled = false;
                }
            });
            document.getElementById('tour-summary-request-quote').addEventListener('click', async event => {
                const button = event.currentTarget;
                if (!savedItinerary) return;
                button.disabled = true;
                try {
                    const review = await request(`/tours/drafts/${savedItinerary.id}/request-review`, { method: 'POST', body: JSON.stringify({}) });
                    window.location.assign(review.confirmation_url);
                } catch (exception) {
                    showError(exception.message || 'We could not send your Tour quotation request. Please try again.');
                    button.disabled = false;
                }
            });
            document.getElementById('tour-mobile-review').addEventListener('click', () => {
                if (!document.getElementById('tour-summary-checkout').hidden) {
                    document.getElementById('tour-summary-checkout').scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else if (!results.hidden) {
                    results.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    document.getElementById('tour-plan').scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
            document.getElementById('tour-summary-goto-contact')?.addEventListener('click', () => {
                const contactValid = document.getElementById('tour-first-name').value.trim()
                    && document.getElementById('tour-email').value.trim()
                    && document.getElementById('tour-phone').value.trim();
                const target = contactValid ? document.getElementById('tour-plan') : document.getElementById('tour-contact-section');
                target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
            document.querySelectorAll('.tour-stepper-btn').forEach(button => button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.stepTarget);
                if (!input) return;
                const min = Number(input.min || 0);
                const max = Number(input.max || 100);
                const current = Number(input.value || 0);
                const next = Math.max(min, Math.min(max, current + Number(button.dataset.stepDelta || 0)));
                if (next !== current) {
                    input.value = next;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }));
            document.getElementById('tour-map-toggle')?.addEventListener('click', event => {
                const container = document.getElementById('tour-map-container');
                const btn = event.currentTarget;
                if (!container) return;
                const isHidden = container.hidden;
                container.hidden = !isHidden;
                btn.textContent = isHidden ? 'Hide Map' : 'Show Map';
                btn.setAttribute('aria-expanded', String(isHidden));
                if (isHidden && window.google?.maps && tourMap) {
                    google.maps.event.trigger(tourMap, 'resize');
                }
            });
            document.getElementById('tour-mobile-drawer')?.addEventListener('show.bs.offcanvas', () => {
                const body = document.getElementById('tour-mobile-drawer-body');
                const sidebar = document.querySelector('.tour-sidebar-card');
                if (!body || !sidebar) return;
                body.innerHTML = sidebar.innerHTML;
                body.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
            });

            const today = new Date();
            const todayValue = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
            document.getElementById('tour-date').min = todayValue;
            document.getElementById('tour-date').value = todayValue;
            const DRAFT_STORAGE_KEY = 'thetaxi_tour_draft_v1';
            function serializeTourPlan() {
                const days = Math.max(1, Number(document.getElementById('tour-days').value) || 1);
                const ends = Array.from({ length: days }, (_, i) => document.querySelector(`.tour-day-end[data-day="${i + 1}"]`)?.value || '');
                const destinations = [...destinationList.querySelectorAll('.tour-destination:checked')].map(input => ({
                    id: input.value,
                    day: Number(document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`)?.value || 1),
                }));
                const activities = [...destinationList.querySelectorAll('.tour-activity:checked')].map(input => input.value);
                return {
                    date: document.getElementById('tour-date').value,
                    days,
                    time: document.getElementById('tour-time').value,
                    adults: Number(document.getElementById('tour-adults').value || 2),
                    children: Number(document.getElementById('tour-children').value || 0),
                    childSeats: Number(document.getElementById('tour-child-seats').value || 0),
                    luggage: Number(document.getElementById('tour-luggage').value || 0),
                    vehicleClass: classSelect.value,
                    start: startSelect.value,
                    ends,
                    destinations,
                    activities,
                    savedAt: Date.now(),
                };
            }
            function applySerializedPlan(plan) {
                if (!plan || typeof plan !== 'object') return;
                if (plan.date && plan.date >= document.getElementById('tour-date').min) document.getElementById('tour-date').value = plan.date;
                if (plan.days) document.getElementById('tour-days').value = Math.max(1, Math.min(60, Number(plan.days)));
                if (plan.time) document.getElementById('tour-time').value = plan.time;
                if (plan.adults != null) document.getElementById('tour-adults').value = plan.adults;
                if (plan.children != null) document.getElementById('tour-children').value = plan.children;
                if (plan.childSeats != null) document.getElementById('tour-child-seats').value = plan.childSeats;
                if (plan.luggage != null) document.getElementById('tour-luggage').value = plan.luggage;
                if (plan.start) window.jQuery(startSelect).val(plan.start).trigger('change');
                renderDaySettings();
                (plan.ends || []).forEach((endId, idx) => {
                    const sel = document.getElementById(`tour-day-end-${idx + 1}`);
                    if (sel && endId) sel.value = endId;
                });
                destinationList.querySelectorAll('.tour-destination, .tour-activity').forEach(i => { i.checked = false; });
                (plan.destinations || []).forEach(entry => {
                    const input = [...destinationList.querySelectorAll('.tour-destination')].find(i => i.value === String(entry.id));
                    if (input) {
                        input.checked = true;
                        const daySel = document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`);
                        if (daySel && entry.day) daySel.value = String(entry.day);
                    }
                });
                (plan.activities || []).forEach(actId => {
                    const input = [...destinationList.querySelectorAll('.tour-activity')].find(i => i.value === String(actId));
                    if (input) input.checked = true;
                });
                if (plan.vehicleClass) classSelect.value = plan.vehicleClass;
                renderClassCards();
                syncStopSearchSelection();
                syncStopDaySelectors();
                renderSelectedTourMap();
                renderTourSummaryDetails();
            }
            function saveLocalDraft() {
                try {
                    const plan = serializeTourPlan();
                    if (plan.destinations.length || plan.activities.length) {
                        localStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify(plan));
                    }
                } catch (_) {}
            }
            function initDraftAndShareRestore() {
                const hash = window.location.hash || '';
                if (hash.startsWith('#plan=')) {
                    try {
                        const decoded = JSON.parse(decodeURIComponent(escape(atob(hash.slice(6)))));
                        applySerializedPlan(decoded);
                        return;
                    } catch (_) {}
                }
                try {
                    const raw = localStorage.getItem(DRAFT_STORAGE_KEY);
                    if (!raw) return;
                    const draft = JSON.parse(raw);
                    if (!draft?.destinations?.length && !draft?.activities?.length) return;
                    const banner = document.getElementById('tour-restore-banner');
                    const meta = document.getElementById('tour-restore-meta');
                    if (meta) meta.textContent = `(${draft.days || 3} days • ${draft.destinations?.length || 0} places)`;
                    if (banner) banner.hidden = false;
                    document.getElementById('tour-restore-apply')?.addEventListener('click', () => {
                        applySerializedPlan(draft);
                        banner.hidden = true;
                    });
                    document.getElementById('tour-restore-dismiss')?.addEventListener('click', () => {
                        localStorage.removeItem(DRAFT_STORAGE_KEY);
                        banner.hidden = true;
                    });
                } catch (_) {}
            }
            function renderQuickTemplates() {
                const container = document.getElementById('tour-quick-templates');
                if (!container || !catalogue) return;
                const packages = (currentService()?.packages || []).filter(p => p.tour_itinerary_template?.days?.length);
                if (packages.length) {
                    container.innerHTML = `<div class="d-flex flex-wrap align-items-center gap-2"><span class="small fw-bold tour-muted">Quick-start:</span>${packages.map(p => `<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-quick-package="${escapeHtml(p.id)}">${escapeHtml(p.name)} (${p.tour_itinerary_template.days.length}D)</button>`).join('')}</div>`;
                    container.hidden = false;
                    return;
                }
                const byName = name => (catalogue.destinations || []).find(d => d.name.toLowerCase().includes(name.toLowerCase()));
                const presets = [
                    { label: 'Cultural & Tea (4D)', days: 4, names: ['Sigiriya', 'Kandy', 'Nuwara Eliya', 'Ella'] },
                    { label: 'East Coast Beaches (3D)', days: 3, names: ['Kalkudah', 'Batticaloa', 'Arugam Bay'] },
                    { label: 'South Coast & Safari (3D)', days: 3, names: ['Galle', 'Mirissa', 'Yala'] },
                ].map(p => ({ ...p, items: p.names.map(byName).filter(Boolean) })).filter(p => p.items.length >= 2);
                if (!presets.length) { container.hidden = true; return; }
                container.innerHTML = `<div class="d-flex flex-wrap align-items-center gap-2"><span class="small fw-bold tour-muted">Quick-start ideas:</span>${presets.map((p, i) => `<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-quick-preset="${i}">${escapeHtml(p.label)}</button>`).join('')}</div>`;
                container.hidden = false;
                container._presets = presets;
            }
            document.getElementById('tour-quick-templates')?.addEventListener('click', event => {
                const pkgBtn = event.target.closest('[data-quick-package]');
                if (pkgBtn) {
                    packageSelect.value = pkgBtn.dataset.quickPackage;
                    packageSelect.dispatchEvent(new Event('change'));
                    return;
                }
                const presetBtn = event.target.closest('[data-quick-preset]');
                if (!presetBtn) return;
                const preset = document.getElementById('tour-quick-templates')._presets?.[Number(presetBtn.dataset.quickPreset)];
                if (!preset) return;
                document.getElementById('tour-days').value = preset.days;
                renderDaySettings();
                destinationList.querySelectorAll('.tour-destination, .tour-activity').forEach(i => { i.checked = false; });
                preset.items.forEach((dest, idx) => {
                    const day = Math.min(preset.days, idx + 1);
                    const endSel = document.getElementById(`tour-day-end-${day}`);
                    if (endSel) endSel.value = dest.id;
                    const input = [...destinationList.querySelectorAll('.tour-destination')].find(i => i.value === String(dest.id));
                    if (input) {
                        input.checked = true;
                        const daySel = document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`);
                        if (daySel) daySel.value = String(day);
                    }
                });
                syncStopSearchSelection();
                syncStopDaySelectors();
                renderSelectedTourMap();
                renderTourSummaryDetails();
                invalidateDisplayedQuote();
                saveLocalDraft();
            });
            function orderDestinationsNearest(startPt, items) {
                const remaining = [...items];
                const ordered = [];
                let currentPt = startPt;
                while (remaining.length) {
                    let bestIdx = 0;
                    let bestDist = Infinity;
                    remaining.forEach((entry, idx) => {
                        if (!currentPt || !entry.pt) return;
                        const d = haversineKm(currentPt, entry.pt);
                        if (d < bestDist) { bestDist = d; bestIdx = idx; }
                    });
                    const [picked] = remaining.splice(bestIdx, 1);
                    ordered.push(picked);
                    if (picked.pt) currentPt = picked.pt;
                }
                return ordered;
            }
            function reorderDomStopsForDay(day, startPt) {
                const checked = [...destinationList.querySelectorAll('.tour-destination:checked')]
                    .filter(input => Number(document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`)?.value) === day);
                if (checked.length < 2) return;
                const destinations = new Map((catalogue?.destinations || []).map(item => [String(item.id), item]));
                const ordered = orderDestinationsNearest(startPt, checked.map(input => ({
                    input,
                    card: input.closest('.tour-highlight-item'),
                    pt: tourLocationPoint(destinations.get(String(input.value))?.location),
                })));
                ordered.forEach(entry => { if (entry.card) destinationList.appendChild(entry.card); });
            }
            document.getElementById('tour-optimize-route')?.addEventListener('click', () => {
                if (!catalogue) return;
                const destinations = new Map((catalogue.destinations || []).map(item => [String(item.id), item]));
                const days = Math.max(1, Number(document.getElementById('tour-days').value) || 1);
                const dayAnchors = Array.from({ length: days }, (_, idx) => {
                    const day = idx + 1;
                    const startId = day === 1 ? startSelect.value : document.querySelector(`.tour-day-end[data-day="${day - 1}"]`)?.value;
                    const endId = document.querySelector(`.tour-day-end[data-day="${day}"]`)?.value;
                    return {
                        day,
                        startPt: tourLocationPoint(destinations.get(String(startId))?.location),
                        endPt: tourLocationPoint(destinations.get(String(endId))?.location),
                    };
                });
                destinationList.querySelectorAll('.tour-destination:checked').forEach(input => {
                    const dest = destinations.get(String(input.value));
                    const pt = tourLocationPoint(dest?.location);
                    if (!pt) return;
                    let bestDay = 1;
                    let bestCost = Infinity;
                    dayAnchors.forEach(a => {
                        const direct = (a.startPt && a.endPt) ? haversineKm(a.startPt, a.endPt) : 0;
                        const via = (a.startPt ? haversineKm(a.startPt, pt) : 0) + (a.endPt ? haversineKm(pt, a.endPt) : 0);
                        const detour = Math.max(0, via - direct);
                        if (detour < bestCost) { bestCost = detour; bestDay = a.day; }
                    });
                    const daySel = document.getElementById(`tour-place-day-${input.id.replace('tour-destination-', '')}`);
                    if (daySel) daySel.value = String(bestDay);
                });
                dayAnchors.forEach(a => reorderDomStopsForDay(a.day, a.startPt));
                syncActivityDaysFromDestinations();
                syncStopDaySelectors();
                renderSelectedTourMap();
                renderTourSummaryDetails();
                invalidateDisplayedQuote();
                saveLocalDraft();
                trackTourEvent('tour_route_optimized', { days, mode: 'stop_order' });
            });
            document.getElementById('tour-optimize-plan')?.addEventListener('click', () => {
                if (!catalogue) return;
                const destinations = new Map((catalogue.destinations || []).map(item => [String(item.id), item]));
                const days = Math.max(1, Number(document.getElementById('tour-days').value) || 1);
                const startPt = tourLocationPoint(destinations.get(String(startSelect.value))?.location);
                const checked = [...destinationList.querySelectorAll('.tour-destination:checked')];
                if (checked.length < 2) return;
                const ordered = orderDestinationsNearest(startPt, checked.map(input => ({
                    input,
                    card: input.closest('.tour-highlight-item'),
                    id: input.value,
                    pt: tourLocationPoint(destinations.get(String(input.value))?.location),
                })));
                // Reorder cards in DOM so itineraryDays(), map, and chips follow the optimal order
                ordered.forEach(entry => { if (entry.card) destinationList.appendChild(entry.card); });
                // Distribute evenly across days and update overnight locations to each day's last stop
                const perDay = Math.ceil(ordered.length / days);
                for (let d = 1; d <= days; d++) {
                    const slice = ordered.slice((d - 1) * perDay, d * perDay);
                    slice.forEach(entry => {
                        const daySel = document.getElementById(`tour-place-day-${entry.input.id.replace('tour-destination-', '')}`);
                        if (daySel) daySel.value = String(d);
                    });
                    const endSel = document.getElementById(`tour-day-end-${d}`);
                    if (endSel && slice.length) {
                        endSel.value = slice[slice.length - 1].id;
                    }
                }
                syncActivityDaysFromDestinations();
                syncStopDaySelectors();
                renderSelectedTourMap();
                renderTourSummaryDetails();
                invalidateDisplayedQuote();
                saveLocalDraft();
                trackTourEvent('tour_route_optimized', { days, mode: 'entire_plan' });
            });
            document.getElementById('tour-whatsapp-share')?.addEventListener('click', () => {
                trackTourEvent('tour_whatsapp_share');
            });
            document.getElementById('tour-copy-link')?.addEventListener('click', async event => {
                trackTourEvent('tour_link_copied');
                const btn = event.currentTarget;
                const encoded = btoa(unescape(encodeURIComponent(JSON.stringify(serializeTourPlan()))));
                const url = `${window.location.origin}${window.location.pathname}#plan=${encoded}`;
                try {
                    await navigator.clipboard.writeText(url);
                    const original = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i> Link copied!';
                    setTimeout(() => { btn.innerHTML = original; }, 2200);
                } catch (_) {
                    window.location.hash = `plan=${encoded}`;
                }
            });
            form.addEventListener('change', saveLocalDraft);
            request(`${api}/services`).then(data => {
                services = data.services || [];
                if (!services.length) throw new Error('Tours are not available at the moment.');
                selectedService = services[0]; fillOptions(); return loadCatalogue();
            }).then(() => { if (packageSelect.value) applyPackageTemplate(); initDraftAndShareRestore(); renderTourSummaryDetails(); loading.hidden = true; form.hidden = false; }).catch(exception => { loading.hidden = true; showError(exception.message); });
        })();
    </script>
@endpush
