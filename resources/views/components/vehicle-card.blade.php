@props([
    'vehicle',
    'pricing' => [],
    'enhancedPricing' => [],
    'serviceFeatures' => [],
    'availability' => ['available' => 0, 'total' => 0],
    'searchId' => null,
    'isRecommended' => false,
    'showBookNow' => false,
    'showViewDetails' => true
])

@php
    $pricing = $pricing ?: ['base_amount' => 0, 'currency' => 'LKR'];
    $mainImage = asset('assets/img/default-vehicle.jpg');
@endphp

<!-- Vehicle Card -->
<div class="vehicle-card modern-card h-100 {{ $isRecommended ? 'recommended-vehicle' : '' }}" 
     data-vehicle-group="{{ $vehicle['id'] }}" 
     data-price="{{ $pricing['base_amount'] ?? 0 }}" 
     data-name="{{ $vehicle['name'] ?? 'Unknown Vehicle' }}">
    
    <!-- Vehicle Image -->
    <div class="vehicle-image-container">
        <img src="{{ $mainImage }}" alt="{{ $vehicle['name'] ?? 'Unknown Vehicle' }}" class="vehicle-img">
        
        @if($availability['available'] > 0)
            <span class="availability-badge available">
                <i class="bi bi-check-circle-fill"></i> Available
            </span>
        @else
            <span class="availability-badge unavailable">
                <i class="bi bi-x-circle-fill"></i> Not Available
            </span>
        @endif

        @if($isRecommended)
            <span class="recommended-badge">
                <i class="bi bi-star-fill"></i> Recommended
            </span>
        @endif

        <!-- Category Badge -->
        @if(isset($vehicle['category']['name']['name']))
            <span class="category-badge">{{ $vehicle['category']['name']['name'] }}</span>
        @endif
    </div>

    <!-- Vehicle Info -->
    <div class="vehicle-card-content">
        <!-- Vehicle Name -->
        <h5 class="vehicle-name">{{ $vehicle['name'] ?? 'Unknown Vehicle' }}</h5>
        
        <!-- Service Features (if any) -->
        @if(!empty($serviceFeatures))
        <div class="service-features mb-2">
            @foreach(array_slice($serviceFeatures, 0, 2) as $feature)
            <span class="feature-badge">
                <i class="bi bi-check-circle"></i> {{ $feature }}
            </span>
            @endforeach
        </div>
        @endif
        
        <!-- Vehicle Specs Grid -->
        <div class="vehicle-specs">
            @if(isset($vehicle['seating_capacity']))
            <div class="spec-item">
                <i class="bi bi-people-fill"></i>
                <span>{{ $vehicle['seating_capacity'] }} Seats</span>
            </div>
            @endif
            
            @if(isset($vehicle['transmission']['name']))
            <div class="spec-item">
                <i class="bi bi-gear-fill"></i>
                <span>{{ $vehicle['transmission']['name'] }}</span>
            </div>
            @endif
            
            @if(isset($vehicle['fuel_type']['name']))
            <div class="spec-item">
                <i class="bi bi-fuel-pump-fill"></i>
                <span>{{ $vehicle['fuel_type']['name'] }}</span>
            </div>
            @endif
            
            <div class="spec-item">
                <i class="bi bi-car-front-fill"></i>
                <span>{{ $availability['available'] }} / {{ $availability['total'] }} Available</span>
            </div>
        </div>

        <!-- Features/Inclusions -->
        @if(isset($pricing['includes_driver']) || isset($pricing['includes_fuel']))
        <div class="vehicle-inclusions">
            @if($pricing['includes_driver'] ?? false)
            <span class="inclusion-badge">
                <i class="bi bi-person-check-fill"></i> Driver
            </span>
            @endif
            @if($pricing['includes_fuel'] ?? false)
            <span class="inclusion-badge">
                <i class="bi bi-droplet-fill"></i> Fuel
            </span>
            @endif
        </div>
        @endif

        <!-- Enhanced Pricing Section -->
        <div class="vehicle-pricing mt-auto">
            <div class="price-display">
                
                @php
                    // Calculate per-day rate from total_amount and duration_days
                    $totalAmount = $pricing['base_amount'] ?? 0;
                    $durationDays = $pricing['duration_info']['days'] ?? 1;
                    $perDayRate = $durationDays > 0 ? $totalAmount / $durationDays : 0;
                    
                    // Debug: Check what we're actually getting
                    if (app()->environment('local')) {
                        \Log::info('Vehicle Card Pricing Debug', [
                            'vehicle_name' => $vehicle['name'] ?? 'Unknown',
                            'total_amount' => $totalAmount,
                            'duration_days' => $durationDays,
                            'per_day_rate' => $perDayRate,
                            'pricing_structure' => $pricing
                        ]);
                    }
                @endphp
                
                <h4 class="price-amount" 
                    data-base-price="{{ $totalAmount }}" 
                    data-per-day="{{ round($perDayRate, 2) }}"
                    data-duration="{{ $durationDays }}"
                    data-currency="{{ $pricing['currency'] ?? 'LKR' }}">
                    {{ $pricing['currency'] ?? 'LKR' }} 
                    <span class="price-value">{{ number_format($perDayRate, 0) }}</span>
                    <span class="price-unit">/day</span>
                </h4>
                
                <!-- Total Price as Secondary Info -->
                <div class="total-price-info mt-2 text-muted small">
                    <span class="total-label">Total:</span>
                    <strong>{{ $pricing['currency'] ?? 'LKR' }} {{ number_format($totalAmount, 0) }}</strong>
                    <span class="duration-label">({{ $durationDays }} day{{ $durationDays > 1 ? 's' : '' }})</span>
                </div>
                
                @if(isset($enhancedPricing['savings']) && !empty($enhancedPricing['savings']))
                <div class="savings-info mt-1">
                    <small class="text-success">
                        <i class="bi bi-tag-fill"></i> Save {{ $enhancedPricing['savings']['amount'] ?? '0' }}
                    </small>
                </div>
                @endif
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="vehicle-actions mt-3">
            @if($showViewDetails && $searchId)
                <a href="{{ route('vehicle.details', ['id' => $vehicle['id'], 'search' => $searchId]) }}" 
                   class="btn btn-outline-primary btn-sm w-100 mb-2">
                    <i class="bi bi-eye"></i> View Details
                </a>
            @endif
            
            @if($availability['available'] > 0)
                @if($showBookNow)
                    <button type="button" 
                            class="btn btn-success w-100 mb-1 book-now-btn" 
                            data-group-id="{{ $vehicle['id'] }}"
                            data-search-id="{{ $searchId }}"
                            data-group-name="{{ $vehicle['name'] ?? 'Vehicle' }}"
                            data-base-price="{{ $pricing['base_amount'] ?? 0 }}"
                            data-currency="{{ $pricing['currency'] ?? 'LKR' }}">
                        <i class="bi bi-calendar-check"></i> Book Now
                    </button>
                @endif
                
                <button type="button" 
                        class="btn btn-primary w-100 add-to-cart-btn" 
                        data-group-id="{{ $vehicle['id'] }}"
                        data-search-id="{{ $searchId }}"
                        data-group-name="{{ $vehicle['name'] ?? 'Vehicle' }}"
                        data-base-price="{{ $pricing['base_amount'] ?? 0 }}"
                        data-currency="{{ $pricing['currency'] ?? 'LKR' }}">
                    <i class="bi bi-cart-plus"></i> Add to Cart
                </button>
            @else
                <button type="button" class="btn btn-secondary w-100" disabled>
                    <i class="bi bi-exclamation-circle"></i> Not Available
                </button>
            @endif
        </div>
    </div>
</div>

@once
@push('styles')
<style>
    /* ==================== THEME COLORS (From app.blade.php) ==================== */
    :root {
        --primary-color: #BF2629;
        --black-color: #717171;
        --white-color: #ffffff;
        --border-color: #e0e0e0;
        --success-color: #28a745;
        --danger-color: #dc3545;
        --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
        --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
        --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    }

    /* ==================== VEHICLE CARD ==================== */
    .vehicle-card {
        background: var(--white-color);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
    }

    .vehicle-card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-4px);
    }

    /* Vehicle Image */
    .vehicle-image-container {
        position: relative;
        height: 200px;
        overflow: hidden;
        background: #f5f5f5;
    }

    .vehicle-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .vehicle-card:hover .vehicle-img {
        transform: scale(1.05);
    }

    /* Badges */
    .availability-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
        backdrop-filter: blur(10px);
    }

    .availability-badge.available {
        background: rgba(40, 167, 69, 0.95);
        color: white;
    }

    .availability-badge.unavailable {
        background: rgba(220, 53, 69, 0.95);
        color: white;
    }

    .category-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        background: rgba(191, 38, 41, 0.95);
        color: white;
        backdrop-filter: blur(10px);
    }

    /* Vehicle Content */
    .vehicle-card-content {
        padding: 20px;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }

    .vehicle-name {
        font-size: 18px;
        font-weight: 700;
        color: #333;
        margin-bottom: 16px;
        line-height: 1.3;
        min-height: 48px;
    }

    /* Specs Grid */
    .vehicle-specs {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }

    .spec-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        color: var(--black-color);
    }

    .spec-item i {
        color: var(--primary-color);
        font-size: 16px;
        flex-shrink: 0;
    }

    /* Inclusions */
    .vehicle-inclusions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 16px;
    }

    .inclusion-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 10px;
        background: #f0f9ff;
        color: #0369a1;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }

    /* Pricing */
    .vehicle-pricing {
        margin-top: auto;
        padding-top: 16px;
        border-top: 2px solid #f0f0f0;
    }

    .price-display {
        text-align: center;
    }

    .price-label {
        display: block;
        font-size: 12px;
        color: var(--black-color);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .price-amount {
        font-size: 28px;
        font-weight: 800;
        color: var(--primary-color);
        margin: 8px 0;
        line-height: 1;
    }

    .price-unit {
        /* display: block; */
        font-size: 12px;
        color: var(--black-color);
        margin-bottom: 8px;
    }

    /* Total Price Info (Secondary Display) */
    .total-price-info {
        padding: 8px;
        background: #f9f9f9;
        border-radius: 6px;
        font-size: 13px;
        border: 1px solid #e0e0e0;
    }

    .total-label {
        color: var(--black-color);
        font-weight: 600;
    }

    .duration-label {
        color: var(--black-color);
        font-size: 12px;
        margin-left: 4px;
    }

    /* Action Buttons */
    .vehicle-actions .btn {
        font-weight: 600;
        padding: 10px 16px;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .vehicle-actions .btn-primary {
        background: var(--primary-color);
        border-color: var(--primary-color);
    }

    .vehicle-actions .btn-primary:hover {
        background: #a01f22;
        border-color: #a01f22;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(191, 38, 41, 0.3);
    }

    .vehicle-actions .btn-success {
        background: var(--success-color);
        border-color: var(--success-color);
    }

    .vehicle-actions .btn-success:hover {
        background: #218838;
        border-color: #1e7e34;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
    }

    .vehicle-actions .btn-outline-primary {
        color: var(--primary-color);
        border-color: var(--primary-color);
    }

    .vehicle-actions .btn-outline-primary:hover {
        background: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
    }

    /* Recommended Vehicle Badge */
    .recommended-badge {
        position: absolute;
        top: 12px;
        left: 50%;
        transform: translateX(-50%);
        padding: 8px 16px;
        border-radius: 25px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        background: linear-gradient(135deg, #ffd700, #ffed4e);
        color: #333;
        border: 2px solid #ffd700;
        box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
        animation: pulse 2s infinite;
        z-index: 5;
    }

    @keyframes pulse {
        0% { transform: translateX(-50%) scale(1); }
        50% { transform: translateX(-50%) scale(1.05); }
        100% { transform: translateX(-50%) scale(1); }
    }

    /* Recommended Vehicle Card Styling */
    .recommended-vehicle {
        border: 3px solid #ffd700;
        box-shadow: 0 8px 25px rgba(255, 215, 0, 0.2);
        position: relative;
    }

    .recommended-vehicle::before {
        content: '';
        position: absolute;
        top: -3px;
        left: -3px;
        right: -3px;
        bottom: -3px;
        background: linear-gradient(45deg, #ffd700, #ffed4e, #ffd700);
        border-radius: 19px;
        z-index: -1;
        animation: glow 3s ease-in-out infinite alternate;
    }

    @keyframes glow {
        from { opacity: 0.5; }
        to { opacity: 0.8; }
    }

    /* Service Features */
    .service-features {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 12px;
    }

    .feature-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 8px;
        background: #e8f5e8;
        color: #2d5a2d;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        border: 1px solid #c3e6c3;
    }

    .feature-badge i {
        font-size: 10px;
        color: #28a745;
    }

    /* Enhanced Pricing Display */
    .savings-info {
        padding: 4px 8px;
        background: rgba(40, 167, 69, 0.1);
        border-radius: 8px;
        display: inline-block;
    }

    .savings-info small {
        font-weight: 600;
        letter-spacing: 0.3px;
    }

    /* Enhanced Hover Effects for Service-Aware Cards */
    .recommended-vehicle:hover {
        transform: translateY(-6px);
        box-shadow: 0 12px 35px rgba(255, 215, 0, 0.3);
    }

    /* Better mobile experience for service features */
    @media (max-width: 767px) {
        .service-features {
            margin-bottom: 8px;
        }
        
        .feature-badge {
            font-size: 10px;
            padding: 3px 6px;
        }
        
        .recommended-badge {
            font-size: 10px;
            padding: 6px 12px;
        }

        .vehicle-image-container {
            height: 180px;
        }

        .vehicle-name {
            min-height: auto;
            font-size: 16px;
        }

        .price-amount {
            font-size: 24px;
        }
    }

    @media (max-width: 575px) {
        .vehicle-specs {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush
@endonce