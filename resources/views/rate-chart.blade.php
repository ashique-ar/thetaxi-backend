@extends('layouts.app')

@section('title', 'Vehicle Rate Chart - Daily & Monthly Rental Rates')

@push('meta')
<meta name="description" content="View our comprehensive vehicle rental rate chart with daily and monthly pricing for all vehicle categories in Sri Lanka.">
@endpush

@push('styles')
<style>
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
        color: #dc3545;
        font-weight: 500;
        font-size: 0.95rem;
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
        <h1 class="text-white">Taxi & Tour Packages</h1>
        @php
            $selectedCurrency = getSelectedCurrency();
            $currencyData = \App\Models\Currency::where('code', $selectedCurrency)->first();
            $currencyName = $currencyData ? $currencyData->name : $selectedCurrency;
        @endphp
        <p>Compare daily and monthly rental rates for all our vehicles. All prices are shown in {{ $currencyName }} ({{ getCurrencySymbol() }}) and calculated based on calendar days.</p>
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
                                @if($vehicle['daily_rate']['amount'] > 0)
                                    <a href="{{ route('vehicle.details', ['id' => $vehicle['id'], 'preset' => 'daily']) }}" class="rate-link" title="Open vehicle with 1-day rental preset">
                                        <div class="rate-amount">
                                            {{ getCurrencySymbol() }} {{ number_format($vehicle['daily_rate']['amount'], 2) }}
                                        </div>
                                    </a>
                                    <div class="rate-per-day">per day</div>
                                @else
                                    <div class="rate-unavailable">
                                        <i class="bi bi-dash-circle"></i> Contact Us
                                    </div>
                                @endif
                            </td>

                            <!-- Monthly Rate -->
                            <td style="text-align: center;">
                                @if($vehicle['monthly_rate']['amount'] > 0)
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
                                        <i class="bi bi-dash-circle"></i> Contact Us
                                    </div>
                                @endif
                            </td>

                            <!-- Extra KM Rate -->
                            <td style="text-align: center;">
                                @if(isset($vehicle['extra_km_rate']) && $vehicle['extra_km_rate'] > 0)
                                    <div class="rate-amount">
                                        {{ getCurrencySymbol() }} {{ number_format($vehicle['extra_km_rate'], 2) }}
                                    </div>
                                    <div class="rate-per-day">per km</div>
                                @else
                                    <div class="rate-unavailable">
                                        <i class="bi bi-dash-circle"></i> Contact Us
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
                            @if($vehicle['daily_rate']['amount'] > 0)
                                <a href="{{ route('vehicle.details', ['id' => $vehicle['id'], 'preset' => 'daily']) }}" class="rate-link" title="Open vehicle with 1-day rental preset">
                                    <div class="rate-amount">
                                        {{ getCurrencySymbol() }} {{ number_format($vehicle['daily_rate']['amount'], 2) }}
                                    </div>
                                </a>
                                <div class="rate-per-day">per day</div>
                            @else
                                <div class="rate-unavailable">
                                    <i class="bi bi-dash-circle"></i> Contact Us
                                </div>
                            @endif
                        </div>

                        <div class="rate-box">
                            <div class="rate-label">Monthly Rate</div>
                            @if($vehicle['monthly_rate']['amount'] > 0)
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
                                    <i class="bi bi-dash-circle"></i> Contact Us
                                </div>
                            @endif
                        </div>

                        <div class="rate-box">
                            <div class="rate-label">Extra KM Rate</div>
                            @if(isset($vehicle['extra_km_rate']) && $vehicle['extra_km_rate'] > 0)
                                <div class="rate-amount">
                                    {{ getCurrencySymbol() }} {{ number_format($vehicle['extra_km_rate'], 2) }}
                                </div>
                                <div class="rate-per-day">per km</div>
                            @else
                                <div class="rate-unavailable">
                                    <i class="bi bi-dash-circle"></i> Contact Us
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

@endsection

@push('scripts')
<script>
    (function () {
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
