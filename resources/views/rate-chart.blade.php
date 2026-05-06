@extends('layouts.app')

@section('title', 'Vehicle Rate Chart - Daily & Monthly Rental Rates')

@php
    $quotationCountries = $countries ?? \App\Models\Country::orderBy('name')->get(['id', 'name', 'code', 'callcode']);
@endphp

@push('meta')
<meta name="description" content="View our comprehensive vehicle rental rate chart with daily and monthly pricing for all vehicle categories in Sri Lanka.">
@endpush

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    #requestQuotationModal .iti {
        width: 100%;
    }

    .rate-chart-hero {
        background: linear-gradient(135deg, #BF2629 0%, #a02123 100%);
        padding: 60px 0;
        color: white;
        text-align: center;
    }

    .rate-chart-hero h1 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 15px;
    }

    .rate-chart-hero p {
        font-size: 1.1rem;
        opacity: 0.95;
        max-width: 700px;
        margin: 0 auto;
    }

    .rate-chart-container {
        padding: 50px 0;
        background: #f8f9fa;
    }

    .rate-table-wrapper {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        overflow: hidden;
    }

    .rate-table {
        width: 100%;
        margin: 0;
    }

    .rate-table thead {
        background: linear-gradient(135deg, #BF2629 0%, #a02123 100%);
        color: white;
    }

    .rate-table thead th {
        padding: 18px 15px;
        font-weight: 600;
        text-align: left;
        font-size: 0.95rem;
        border: none;
    }

    .rate-table tbody tr {
        border-bottom: 1px solid #e9ecef;
        transition: all 0.3s ease;
    }

    .rate-table tbody tr:hover {
        background: #f8f9fa;
        transform: scale(1.01);
    }

    .rate-table tbody td {
        padding: 20px 15px;
        vertical-align: middle;
    }

    .vehicle-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .vehicle-thumbnail {
        width: 80px;
        height: 60px;
        object-fit: cover;
        border-radius: 8px;
        border: 2px solid #e9ecef;
    }

    .zoom-trigger {
        position: relative;
        display: inline-flex;
        border: none;
        padding: 0;
        background: transparent;
        cursor: zoom-in;
    }

    .zoom-trigger:focus-visible {
        outline: 2px solid #BF2629;
        outline-offset: 2px;
        border-radius: 10px;
    }

    .zoom-hover-popup {
        position: fixed;
        z-index: 1200;
        width: 260px;
        height: 190px;
        border-radius: 10px;
        border: 2px solid #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        pointer-events: none;
        opacity: 0;
        transform: scale(0.95);
        transition: opacity 0.15s ease, transform 0.15s ease;
        background: #fff;
    }

    .zoom-hover-popup.show {
        opacity: 1;
        transform: scale(1);
    }

    .zoom-hover-popup img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .zoom-modal {
        position: fixed;
        inset: 0;
        z-index: 1250;
        background: rgba(20, 24, 31, 0.78);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }

    .zoom-modal.show {
        display: flex;
    }

    .zoom-modal-content {
        max-width: min(92vw, 900px);
        max-height: 86vh;
        border-radius: 14px;
        overflow: hidden;
        border: 2px solid #fff;
        box-shadow: 0 14px 40px rgba(0, 0, 0, 0.35);
    }

    .zoom-modal-content img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #fff;
        display: block;
    }

    .zoom-modal-close {
        position: absolute;
        top: 14px;
        right: 14px;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        border: none;
        background: #fff;
        color: #111827;
        font-size: 20px;
        font-weight: 700;
        line-height: 1;
        cursor: pointer;
    }

    .vehicle-thumbnail-placeholder {
        width: 80px;
        height: 60px;
        background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6c757d;
        font-size: 24px;
    }

    .vehicle-details h5 {
        margin: 0 0 5px 0;
        font-size: 1.1rem;
        font-weight: 600;
        color: #2c3e50;
    }

    .vehicle-specs {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        font-size: 0.85rem;
        color: #6c757d;
    }

    .vehicle-specs span {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .vehicle-specs i {
        font-size: 0.9rem;
    }

    .rate-amount {
        font-size: 1.3rem;
        font-weight: 700;
        color: #BF2629;
    }

    .rate-link {
        text-decoration: none;
        display: inline-block;
    }

    .rate-link .rate-amount {
        text-decoration: underline;
        text-decoration-style: dotted;
        text-underline-offset: 3px;
    }

    .rate-link:hover .rate-amount {
        color: #a02123;
    }

    .rate-per-day {
        font-size: 0.85rem;
        color: #6c757d;
        margin-top: 3px;
    }

    .rate-unavailable {
        color: #BF2629;
        font-weight: 500;
        font-size: 0.95rem;
    }

    .rate-quotation-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        border-radius: 999px;
        background: #fff7ed;
        color: #b45309;
        font-weight: 700;
        font-size: 0.9rem;
        text-decoration: none;
        border: 1px solid #fed7aa;
        cursor: pointer;
    }

    .rate-quotation-link:hover {
        background: #ffedd5;
        color: #92400e;
    }

    .info-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        background: #fef5f5;
        color: #BF2629;
    }

    .last-updated {
        text-align: center;
        color: #6c757d;
        font-size: 0.9rem;
        margin-top: 30px;
        padding: 15px;
        background: white;
        border-radius: 8px;
    }

    .error-message {
        background: #fff3cd;
        border: 1px solid #ffc107;
        color: #856404;
        padding: 20px;
        border-radius: 8px;
        text-align: center;
        margin: 30px 0;
    }

    @media (max-width: 768px) {
        .rate-chart-hero h1 {
            font-size: 1.8rem;
        }

        .rate-chart-hero p {
            font-size: 0.95rem;
        }

        /* Hide table on mobile, show cards instead */
        .rate-table-wrapper {
            display: none;
        }

        .mobile-rate-cards {
            display: block !important;
        }
    }

    @media (hover: none), (pointer: coarse) {
        .zoom-hover-popup {
            display: none !important;
        }
    }

    /* Mobile card layout */
    .mobile-rate-cards {
        display: none;
    }

    .mobile-rate-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .mobile-rate-card .vehicle-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }

    .mobile-rate-card .vehicle-thumbnail {
        width: 80px;
        height: 60px;
        object-fit: cover;
        border-radius: 8px;
        border: 2px solid #e9ecef;
    }

    .mobile-rate-card .vehicle-thumbnail-placeholder {
        width: 80px;
        height: 60px;
        background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6c757d;
        font-size: 24px;
    }

    .mobile-rate-card .vehicle-name {
        flex: 1;
    }

    .mobile-rate-card .vehicle-name h5 {
        margin: 0 0 5px 0;
        font-size: 1.1rem;
        font-weight: 600;
        color: #2c3e50;
    }

    .mobile-rate-card .vehicle-category {
        font-size: 0.85rem;
        color: #6c757d;
    }

    .mobile-rate-card .specs-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }

    .mobile-rate-card .spec-item {
        font-size: 0.85rem;
        color: #495057;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .mobile-rate-card .spec-item i {
        color: #BF2629;
    }

    .mobile-rate-card .rates-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }

    .mobile-rate-card .rate-box {
        text-align: center;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
    }

    .mobile-rate-card .rate-label {
        font-size: 0.8rem;
        color: #6c757d;
        /* margin-bottom: 5px; */
        font-weight: 500;
    }

    .mobile-rate-card .rate-amount {
        font-size: 1.2rem;
        font-weight: 700;
        color: #BF2629;
    }

    .mobile-rate-card .rate-per-day {
        font-size: 0.75rem;
        color: #6c757d;
        /* margin-top: 3px; */
    }

    .mobile-rate-card .rate-unavailable {
        color: #dc3545;
        font-weight: 500;
        font-size: 0.9rem;
    }

    .mobile-rate-card .view-btn {
        width: 100%;
        padding: 12px;
        background: #BF2629;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        text-decoration: none;
        display: block;
        text-align: center;
    }
</style>
@endpush

@section('content')

<!-- Hero Section -->
<div class="rate-chart-hero">
    <div class="container">
        <h1 class="text-white">Sri Lanka Airport Taxi Rates</h1>
        @php
            $selectedCurrency = getSelectedCurrency();
            $currencyData = \App\Models\Currency::where('code', $selectedCurrency)->first();
            $currencyName = $currencyData ? $currencyData->name : $selectedCurrency;
        @endphp
        <p>Affordable Fixed Price</p>
        {{-- <p>Compare daily and monthly taxi rental rates for all our vehicles. All prices are shown in {{ $currencyName }} ({{ getCurrencySymbol() }}) and calculated based on calendar days.</p> --}}
    </div>
</div>

<!-- Rate Chart Section -->
<div class="rate-chart-container">
    <div class="container">
        
        @if(isset($error))
            <div class="error-message">
                <i class="bi bi-exclamation-triangle" style="font-size: 1.5rem; margin-bottom: 10px;"></i>
                <p style="margin: 0;">{{ $error }}</p>
            </div>
        @elseif(empty($vehicleGroups))
            <div class="error-message">
                <i class="bi bi-info-circle" style="font-size: 1.5rem; margin-bottom: 10px;"></i>
                <p style="margin: 0;">No vehicles available at the moment. Please check back later.</p>
            </div>
        @else
            <div class="rate-table-wrapper">
                <table class="rate-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Vehicle</th>
                            {{-- <th style="width: 20%;">Specifications</th> --}}
                            <th style="width: 18%; text-align: center;">Daily Rate <small>(100km)</small></th>
                            <th style="width: 22%; text-align: center;">Monthly Rate <small>(30 Days - 3000km)</small></th>
                            <th style="width: 20%; text-align: center;">Extra KM Rate</th>
                            {{-- <th style="width: 5%; text-align: center;">Action</th> --}}
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($vehicleGroups as $vehicle)
                        <tr>
                            <!-- Vehicle Info -->
                            <td>
                                <div class="vehicle-info">
                                    @php
                                        $thumbnailPath = $vehicle['thumbnail'];
                                        $imageUrl = null;
                                        
                                        if ($thumbnailPath) {
                                            $imageUrl = s3_asset($thumbnailPath);
                                        }
                                    @endphp
                                    
                                    @if($imageUrl)
                                        <button type="button" class="zoom-trigger"
                                            data-zoom-src="{{ $imageUrl }}"
                                            data-zoom-alt="{{ $vehicle['name'] }}">
                                            <img src="{{ $imageUrl }}" 
                                                alt="{{ $vehicle['name'] }}" 
                                                class="vehicle-thumbnail"
                                                onerror="this.closest('.zoom-trigger').style.display='none'; this.closest('.zoom-trigger').nextElementSibling.style.display='flex';">
                                        </button>
                                        <div class="vehicle-thumbnail-placeholder" style="display: none;">
                                            <i class="bi bi-car-front"></i>
                                        </div>
                                    @else
                                        <div class="vehicle-thumbnail-placeholder">
                                            <i class="bi bi-car-front"></i>
                                        </div>
                                    @endif
                                    
                                    <div class="vehicle-details">
                                        <h5>{{ $vehicle['name'] }}</h5>
                                        <div class="vehicle-specs">
                                            <span><i class="bi bi-tag"></i> {{ $vehicle['category'] }}</span>
                                            @if($vehicle['transmission'] !== 'N/A')
                                                <span><i class="bi bi-gear"></i> {{ $vehicle['transmission'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Specifications -->
                            {{-- <td>
                                <div style="font-size: 0.9rem; color: #495057;">
                                    @if($vehicle['passengers_count'])
                                        <div>
                                            <i class="bi bi-people"></i> {{ $vehicle['passengers_count'] }} Passengers
                                        </div>
                                    @endif
                                    @if($vehicle['fuel_type'] !== 'N/A')
                                        <div>
                                            <i class="bi bi-fuel-pump"></i> {{ $vehicle['fuel_type'] }}
                                        </div>
                                    @endif
                                    @if($vehicle['air_conditioning'])
                                        <div>
                                            <i class="bi bi-snow"></i> A/C
                                        </div>
                                    @endif
                                </div>
                            </td> --}}

                            <!-- Daily Rate -->
                            <td style="text-align: center;">
                                @if(($vehicle['daily_rate']['amount'] ?? 0) > 0 && !($vehicle['is_inquiry_only'] ?? false))
                                    <a href="{{ route('vehicle.details', ['id' => $vehicle['id'], 'preset' => 'daily']) }}" class="rate-link" title="Open vehicle with 1-day rental preset">
                                        <div class="rate-amount">
                                            {{ getCurrencySymbol() }} {{ number_format($vehicle['daily_rate']['amount'], 2) }}
                                        </div>
                                    </a>
                                    <div class="rate-per-day">per day</div>
                                @else
                                    <div class="rate-unavailable">
                                        <button type="button" class="rate-quotation-link request-quotation-btn"
                                            data-group-id="{{ $vehicle['id'] }}"
                                            data-group-name="{{ $vehicle['name'] }}"
                                            data-service-type="day_rental"
                                            data-bs-toggle="modal"
                                            data-bs-target="#requestQuotationModal">
                                            <i class="bi bi-calculator"></i> Request Quotation
                                        </button>
                                    </div>
                                @endif
                            </td>

                            <!-- Monthly Rate -->
                            <td style="text-align: center;">
                                @if(($vehicle['monthly_rate']['amount'] ?? 0) > 0 && !($vehicle['is_inquiry_only'] ?? false))
                                    <a href="{{ route('vehicle.details', ['id' => $vehicle['id'], 'preset' => 'monthly']) }}" class="rate-link" title="Open vehicle with 30-day rental preset">
                                        <div class="rate-amount">
                                            {{ getCurrencySymbol() }} {{ number_format($vehicle['monthly_rate']['amount'], 2) }}
                                        </div>
                                    </a>
                                    <div class="rate-per-day">
                                        {{ getCurrencySymbol() }} {{ number_format($vehicle['monthly_rate']['per_day'], 2) }}/day
                                    </div>
                                @else
                                    <div class="rate-unavailable">
                                        <button type="button" class="rate-quotation-link request-quotation-btn"
                                            data-group-id="{{ $vehicle['id'] }}"
                                            data-group-name="{{ $vehicle['name'] }}"
                                            data-service-type="day_rental"
                                            data-bs-toggle="modal"
                                            data-bs-target="#requestQuotationModal">
                                            <i class="bi bi-calculator"></i> Request Quotation
                                        </button>
                                    </div>
                                @endif
                            </td>

                            <!-- Extra KM Rate -->
                            <td style="text-align: center;">
                                @if(isset($vehicle['extra_km_rate']) && $vehicle['extra_km_rate'] > 0 && !($vehicle['is_inquiry_only'] ?? false))
                                    <div class="rate-amount">
                                        {{ getCurrencySymbol() }} {{ number_format($vehicle['extra_km_rate'], 2) }}
                                    </div>
                                    <div class="rate-per-day">per km</div>
                                @else
                                    <div class="rate-unavailable">
                                        <button type="button" class="rate-quotation-link request-quotation-btn"
                                            data-group-id="{{ $vehicle['id'] }}"
                                            data-group-name="{{ $vehicle['name'] }}"
                                            data-service-type="day_rental"
                                            data-bs-toggle="modal"
                                            data-bs-target="#requestQuotationModal">
                                            <i class="bi bi-calculator"></i> Request Quotation
                                        </button>
                                    </div>
                                @endif
                            </td>

                            <!-- Action -->
                            {{-- <td style="text-align: center;">
                                <a href="{{ route('vehicle.details', $vehicle['id']) }}" 
                                   class="btn btn-sm btn-primary"
                                   style="padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td> --}}
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card Layout (visible on mobile only) -->
            <div class="mobile-rate-cards">
                @foreach($vehicleGroups as $vehicle)
                <div class="mobile-rate-card">
                    <!-- Vehicle Header -->
                    <div class="vehicle-header">
                        @php
                            $thumbnailPath = $vehicle['thumbnail'];
                            $imageUrl = null;
                            
                            if ($thumbnailPath) {
                                $imageUrl = s3_asset($thumbnailPath);
                            }
                        @endphp
                        
                        @if($imageUrl)
                            <button type="button" class="zoom-trigger"
                                data-zoom-src="{{ $imageUrl }}"
                                data-zoom-alt="{{ $vehicle['name'] }}">
                                <img src="{{ $imageUrl }}" 
                                    alt="{{ $vehicle['name'] }}" 
                                    class="vehicle-thumbnail"
                                    onerror="this.closest('.zoom-trigger').style.display='none'; this.closest('.zoom-trigger').nextElementSibling.style.display='flex';">
                            </button>
                            <div class="vehicle-thumbnail-placeholder" style="display: none;">
                                <i class="bi bi-car-front"></i>
                            </div>
                        @else
                            <div class="vehicle-thumbnail-placeholder">
                                <i class="bi bi-car-front"></i>
                            </div>
                        @endif
                        
                        <div class="vehicle-name">
                            <h5>{{ $vehicle['name'] }}</h5>
                            <div class="vehicle-category">{{ $vehicle['category'] }}</div>
                        </div>
                    </div>

                    <!-- Specifications -->
                    {{-- <div class="specs-grid">
                        @if($vehicle['passengers_count'])
                            <div class="spec-item">
                                <i class="bi bi-people"></i>
                                {{ $vehicle['passengers_count'] }} Passengers
                            </div>
                        @endif
                        @if($vehicle['fuel_type'] !== 'N/A')
                            <div class="spec-item">
                                <i class="bi bi-fuel-pump"></i>
                                {{ $vehicle['fuel_type'] }}
                            </div>
                        @endif
                        @if($vehicle['transmission'] !== 'N/A')
                            <div class="spec-item">
                                <i class="bi bi-gear"></i>
                                {{ $vehicle['transmission'] }}
                            </div>
                        @endif
                        @if($vehicle['air_conditioning'])
                            <div class="spec-item">
                                <i class="bi bi-snow"></i>
                                A/C
                            </div>
                        @endif
                    </div> --}}

                    <!-- Rates -->
                    <div class="rates-section">
                        <div class="rate-box">
                            <div class="rate-label">Daily Rate</div>
                            @if(($vehicle['daily_rate']['amount'] ?? 0) > 0 && !($vehicle['is_inquiry_only'] ?? false))
                                <a href="{{ route('vehicle.details', ['id' => $vehicle['id'], 'preset' => 'daily']) }}" class="rate-link" title="Open vehicle with 1-day rental preset">
                                    <div class="rate-amount">
                                        {{ getCurrencySymbol() }} {{ number_format($vehicle['daily_rate']['amount'], 2) }}
                                    </div>
                                </a>
                                <div class="rate-per-day">per day</div>
                            @else
                                <div class="rate-unavailable">
                                    <button type="button" class="rate-quotation-link request-quotation-btn"
                                        data-group-id="{{ $vehicle['id'] }}"
                                        data-group-name="{{ $vehicle['name'] }}"
                                        data-service-type="day_rental"
                                        data-bs-toggle="modal"
                                        data-bs-target="#requestQuotationModal">
                                        <i class="bi bi-calculator"></i> Request Quotation
                                    </button>
                                </div>
                            @endif
                        </div>

                        <div class="rate-box">
                            <div class="rate-label">Monthly Rate</div>
                            @if(($vehicle['monthly_rate']['amount'] ?? 0) > 0 && !($vehicle['is_inquiry_only'] ?? false))
                                <a href="{{ route('vehicle.details', ['id' => $vehicle['id'], 'preset' => 'monthly']) }}" class="rate-link" title="Open vehicle with 30-day rental preset">
                                    <div class="rate-amount">
                                        {{ getCurrencySymbol() }} {{ number_format($vehicle['monthly_rate']['amount'], 2) }}
                                    </div>
                                </a>
                                <div class="rate-per-day">
                                    {{ getCurrencySymbol() }} {{ number_format($vehicle['monthly_rate']['per_day'], 2) }}/day
                                </div>
                            @else
                                <div class="rate-unavailable">
                                    <button type="button" class="rate-quotation-link request-quotation-btn"
                                        data-group-id="{{ $vehicle['id'] }}"
                                        data-group-name="{{ $vehicle['name'] }}"
                                        data-service-type="day_rental"
                                        data-bs-toggle="modal"
                                        data-bs-target="#requestQuotationModal">
                                        <i class="bi bi-calculator"></i> Request Quotation
                                    </button>
                                </div>
                            @endif
                        </div>

                        <div class="rate-box">
                            <div class="rate-label">Extra KM Rate</div>
                            @if(isset($vehicle['extra_km_rate']) && $vehicle['extra_km_rate'] > 0 && !($vehicle['is_inquiry_only'] ?? false))
                                <div class="rate-amount">
                                    {{ getCurrencySymbol() }} {{ number_format($vehicle['extra_km_rate'], 2) }}
                                </div>
                                <div class="rate-per-day">per km</div>
                            @else
                                <div class="rate-unavailable">
                                    <button type="button" class="rate-quotation-link request-quotation-btn"
                                        data-group-id="{{ $vehicle['id'] }}"
                                        data-group-name="{{ $vehicle['name'] }}"
                                        data-service-type="day_rental"
                                        data-bs-toggle="modal"
                                        data-bs-target="#requestQuotationModal">
                                        <i class="bi bi-calculator"></i> Request Quotation
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- View Button -->
                    {{-- <a href="{{ route('vehicle.details', $vehicle['id']) }}" class="view-btn">
                        <i class="bi bi-eye"></i> View Details
                    </a> --}}
                </div>
                @endforeach
            </div>

            @if(isset($lastUpdated))
            <div class="last-updated">
                <i class="bi bi-clock-history"></i> Last updated: {{ $lastUpdated }}
            </div>
            @endif

            <!-- Info Section -->
            <div style="margin-top: 40px; padding: 25px; background: white; border-radius: 12px; border-left: 4px solid #BF2629;">
                <h5 style="margin-bottom: 15px; color: #2c3e50;">
                    <i class="bi bi-info-circle"></i> Important Information
                </h5>
                @php
                    $selectedCurrency = getSelectedCurrency();
                    $currencyData = \App\Models\Currency::where('code', $selectedCurrency)->first();
                    $currencyName = $currencyData ? $currencyData->name : $selectedCurrency;
                @endphp
                <ul style="margin: 0; padding-left: 20px; color: #6c757d; line-height: 1.8;">
                    <li>All rates are in {{ $currencyName }} ({{ getCurrencySymbol() }}) and subject to change without notice</li>
                    <li>Rates are calculated based on calendar days (not 24-hour periods) - today counts as day 1</li>
                    <li>Monthly rates are calculated for 30 calendar days and offer better value</li>
                    <li>Rates include standard insurance and basic maintenance</li>
                    <li>Additional charges may apply for extra kilometers, fuel, and optional add-ons</li>
                    <li>Driver charges are separate and can be added during booking</li>
                    <li>For custom packages or long-term rentals, please contact us for special rates</li>
                    @if($selectedCurrency !== 'LKR')
                    <li>Currency conversion rates are updated regularly and may vary at time of booking</li>
                    @endif
                </ul>
            </div>

            <!-- CTA Section -->
            <div style="margin-top: 30px; text-align: center; padding: 40px; background: linear-gradient(135deg, #BF2629 0%, #a02123 100%); border-radius: 12px; color: white;">
                <h4 style="margin-bottom: 15px;">Ready to Book Your Vehicle?</h4>
                <p style="margin-bottom: 25px; opacity: 0.95;">Get the best rates and enjoy a seamless booking experience</p>
                <a href="{{ route('home') }}#search-section" class="btn btn-light btn-lg" style="padding: 12px 40px; border-radius: 8px; font-weight: 600;">
                    <i class="bi bi-search"></i> Search & Book Now
                </a>
            </div>
        @endif

    </div>
</div>

<div id="zoom-hover-popup" class="zoom-hover-popup" aria-hidden="true">
    <img id="zoom-hover-image" src="" alt="">
</div>

<div id="zoom-modal" class="zoom-modal" aria-hidden="true" role="dialog" aria-label="Vehicle image preview">
    <button type="button" class="zoom-modal-close" id="zoom-modal-close" aria-label="Close image preview">&times;</button>
    <div class="zoom-modal-content">
        <img id="zoom-modal-image" src="" alt="">
    </div>
</div>

<!-- Request Quotation Modal -->
<div class="modal fade" id="requestQuotationModal" tabindex="-1" aria-labelledby="requestQuotationModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="requestQuotationModalLabel">
                    <i class="bi bi-calculator"></i> Request Quotation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quotationRequestForm" method="POST" action="{{ route('quotation.request') }}">
                @csrf
                <div class="modal-body">
                    <input type="hidden" name="vehicle_group_id" id="quotation_vehicle_group_id">
                    <input type="hidden" name="service_type" id="quotation_service_type" value="day_rental">
                    <input type="hidden" name="pickup_location" id="quotation_pickup_location">
                    <input type="hidden" name="dropoff_location" id="quotation_dropoff_location">
                    <input type="hidden" name="travel_date" id="quotation_travel_date">
                    <input type="hidden" name="travel_time" id="quotation_travel_time">
                    <input type="hidden" name="return_date" id="quotation_return_date">
                    <input type="hidden" name="return_time" id="quotation_return_time">

                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle"></i>
                        <strong>Vehicle:</strong> <span id="quotation_vehicle_name"></span>
                        <br>
                        <small class="text-muted">This vehicle requires a quotation request. Our team will contact you with pricing details.</small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country *</label>
                            <select class="form-select no-nice quotation-phone-country-select" name="phone_country" required>
                                <option value="">Select Country</option>
                                @foreach ($quotationCountries as $country)
                                    <option value="{{ strtolower($country->code ?? '') }}"
                                        {{ strtolower($country->code ?? '') === 'lk' ? 'selected' : '' }}>
                                        {{ $country->name }}
                                        @if ($country->callcode)
                                            (+{{ $country->callcode }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control quotation-phone-input" name="phone" required>
                            <input type="hidden" name="phone_country_code" class="quotation-phone-country-code">
                            <input type="hidden" name="phone_international" class="quotation-phone-international">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Additional Requirements</label>
                            <textarea class="form-control" name="requirements" rows="3"
                                placeholder="Please describe preferred dates, rental duration, pickup location, or other requirements..."></textarea>
                        </div>
                    </div>

                    <div class="mt-4 p-3 bg-light rounded">
                        <h6 class="mb-2"><i class="bi bi-calendar-event"></i> Request Details</h6>
                        <p class="mb-0 text-muted small">
                            Rate chart quotation request for day rental. Add your preferred dates and requirements above.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-send"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    (function () {
        function showRateChartNotification(type, message) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';
            const alert = $(`
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 1300; min-width: 300px;">
                    <i class="bi ${icon} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `);
            $('body').append(alert);
            setTimeout(() => alert.alert('close'), type === 'success' ? 4000 : 5000);
        }

        $(document).on('click', '.request-quotation-btn', function () {
            const $btn = $(this);
            $('#quotation_vehicle_group_id').val($btn.data('group-id'));
            $('#quotation_vehicle_name').text($btn.data('group-name'));
            $('#quotation_service_type').val($btn.data('service-type') || 'day_rental');
            $('#quotation_pickup_location').val('');
            $('#quotation_dropoff_location').val('');
            $('#quotation_travel_date').val('');
            $('#quotation_travel_time').val('');
            $('#quotation_return_date').val('');
            $('#quotation_return_time').val('');
        });

        const quotationPhoneInput = document.querySelector('#quotationRequestForm .quotation-phone-input');
        const quotationPhoneCountrySelect = document.querySelector('#quotationRequestForm .quotation-phone-country-select');
        let quotationPhoneIti = null;

        if (quotationPhoneCountrySelect && $.fn.select2) {
            $(quotationPhoneCountrySelect).next('.nice-select').remove();
            $(quotationPhoneCountrySelect).select2({
                placeholder: 'Select Country',
                allowClear: true,
                width: '100%',
                dropdownParent: $('#requestQuotationModal')
            });
        }

        if (quotationPhoneInput && typeof window.intlTelInput === 'function') {
            quotationPhoneIti = window.intlTelInput(quotationPhoneInput, {
                initialCountry: quotationPhoneCountrySelect ? quotationPhoneCountrySelect.value : 'lk',
                preferredCountries: ['lk', 'in', 'us', 'gb', 'ca', 'au'],
                separateDialCode: true,
                formatAsYouType: true,
                utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
            });

            quotationPhoneInput.addEventListener('countrychange', function () {
                const countryData = quotationPhoneIti.getSelectedCountryData();
                $('#quotationRequestForm .quotation-phone-country-code').val(countryData.dialCode || '');
                if (quotationPhoneCountrySelect && countryData.iso2) {
                    quotationPhoneCountrySelect.value = countryData.iso2;
                }
            });

            if (quotationPhoneCountrySelect) {
                $(quotationPhoneCountrySelect).on('change', function () {
                    quotationPhoneIti.setCountry(this.value);
                });
            }
        }

        $(document).on('submit', '#quotationRequestForm', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $submitBtn = $form.find('button[type="submit"]');
            const originalBtnText = $submitBtn.html();

            if (quotationPhoneIti) {
                const countryData = quotationPhoneIti.getSelectedCountryData();
                $form.find('.quotation-phone-country-code').val(countryData.dialCode || '');
                $form.find('.quotation-phone-international').val(quotationPhoneIti.getNumber() || '');
            }

            $submitBtn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...'
            );

            $.ajax({
                url: $form.attr('action'),
                method: 'POST',
                data: $form.serialize(),
                headers: {
                    Accept: 'application/json',
                },
                success: function (response) {
                    if (response.success) {
                        $('#requestQuotationModal').modal('hide');
                        showRateChartNotification('success', response.message || 'Quotation request submitted successfully! Our team will contact you shortly.');
                        $form[0].reset();
                        $('#quotation_service_type').val('day_rental');
                    } else {
                        showRateChartNotification('error', response.message || 'Failed to submit quotation request. Please try again.');
                    }
                },
                error: function (xhr) {
                    const errors = xhr.responseJSON && xhr.responseJSON.errors ? xhr.responseJSON.errors : null;
                    const firstError = errors ? Object.values(errors).flat()[0] : null;
                    showRateChartNotification('error', firstError || xhr.responseJSON?.message || 'An error occurred. Please try again.');
                },
                complete: function () {
                    $submitBtn.prop('disabled', false).html(originalBtnText);
                },
            });
        });

        const triggers = document.querySelectorAll('.zoom-trigger');
        const hoverPopup = document.getElementById('zoom-hover-popup');
        const hoverImage = document.getElementById('zoom-hover-image');
        const modal = document.getElementById('zoom-modal');
        const modalImage = document.getElementById('zoom-modal-image');
        const closeBtn = document.getElementById('zoom-modal-close');
        const prefersHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        function positionHoverPopup(event) {
            const offset = 16;
            const popupWidth = 260;
            const popupHeight = 190;
            let x = event.clientX + offset;
            let y = event.clientY + offset;

            if (x + popupWidth > window.innerWidth - 8) x = event.clientX - popupWidth - offset;
            if (y + popupHeight > window.innerHeight - 8) y = event.clientY - popupHeight - offset;

            hoverPopup.style.left = `${Math.max(8, x)}px`;
            hoverPopup.style.top = `${Math.max(8, y)}px`;
        }

        function openModal(src, alt) {
            modalImage.src = src;
            modalImage.alt = alt || 'Vehicle image';
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            modalImage.src = '';
            document.body.style.overflow = '';
        }

        triggers.forEach((el) => {
            const src = el.dataset.zoomSrc;
            const alt = el.dataset.zoomAlt || 'Vehicle image';
            if (!src) return;

            el.addEventListener('click', () => openModal(src, alt));

            if (prefersHover) {
                el.addEventListener('mouseenter', (event) => {
                    hoverImage.src = src;
                    hoverImage.alt = alt;
                    positionHoverPopup(event);
                    hoverPopup.classList.add('show');
                    hoverPopup.setAttribute('aria-hidden', 'false');
                });

                el.addEventListener('mousemove', positionHoverPopup);

                el.addEventListener('mouseleave', () => {
                    hoverPopup.classList.remove('show');
                    hoverPopup.setAttribute('aria-hidden', 'true');
                });
            }
        });

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('show')) closeModal();
        });
    })();
</script>
@endpush
