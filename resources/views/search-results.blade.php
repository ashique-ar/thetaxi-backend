@extends('layouts.app')

@section('title', 'Search Results - TheTaxi')

@section('content')

    <!-- Breadcrumb section Start-->
    <div class="breadcrumb-section three"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg6.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Available Vehicles</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>Search Results</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Search Summary & Filter Section -->
    <div class="filter-wrapper hotel mb-40">
        <div class="container">
            <!-- Search Summary Card -->
            {{-- <div class="search-summary-card">
                <div class="row align-items-center">
                    <div class="col-lg-10">
                        <div class="search-info">
                            <h5>Your Search</h5>
                            <div class="search-details">
                                <div class="detail-item">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                                    </svg>
                                    <span><strong>Pickup:</strong> {{ $search->pickup_date->format('d M Y, H:i') }}</span>
                                </div>
                                @if($search->dropoff_date)
                                <div class="detail-item">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                                    </svg>
                                    <span><strong>Dropoff:</strong> {{ $search->dropoff_date->format('d M Y, H:i') }}</span>
                                </div>
                                @endif
                                <div class="detail-item">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <g>
                                            <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z"/>
                                            <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z"/>
                                        </g>
                                    </svg>
                                    <span>{{ $search->pickup_location ?? 'N/A' }} → {{ $search->dropoff_location ?? 'N/A' }}</span>
                                </div>
                                @if($search->duration_days)
                                <div class="detail-item">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                                    </svg>
                                    <span><strong>Duration:</strong> {{ $search->duration_days }} day(s), {{ $search->duration_hours % 24 }} hour(s)</span>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 text-end">
                        <a href="{{ route('home') }}" class="btn btn-outline-primary">
                            <i class="bi bi-pencil"></i> Modify Search
                        </a>
                    </div>
                </div>
            </div> --}}

            <!-- Embedded Booking Form for modifications -->
            @include('components.booking-form')
        </div>
    </div>

    <!-- Vehicle Results Section -->
    <div class="package-standard-wrapper pt-110 mb-110">
        <div class="container">
            <div class="row">
                <!-- Filters Sidebar (Optional for future) -->
                <!-- <div class="col-lg-3">
                    Filter sidebar content
                </div> -->

                <!-- Vehicle Listings -->
                <div class="col-lg-12">
                    <div class="row mb-4">
                        <div class="col-lg-8">
                            <h4>{{ count($results['data']) }} Vehicle Groups Available</h4>
                            <p class="text-muted">Choose from our premium selection of vehicles</p>
                        </div>
                        <div class="col-lg-4">
                            <select class="form-select" id="sortResults">
                                <option value="price_low">Price: Low to High</option>
                                <option value="price_high">Price: High to Low</option>
                                <option value="name">Name: A to Z</option>
                            </select>
                        </div>
                    </div>
                    @if(isset($results['data']) && count($results['data']) > 0)
                        <div class="vehicle-results-list">
                            @foreach($results['data'] as $result)
                                @php
                                    // Use the direct structure from BookingFlowService
                                    $pricing = $result['pricing_info'] ?? ['base_amount' => 0, 'currency' => 'USD'];
                                    $availability = [
                                        'available' => $result['available_count'] ?? 0,
                                        'total' => $result['total_count'] ?? 0
                                    ];
                                    $images = [];
                                    $mainImage = asset('assets/img/default-vehicle.jpg');
                                @endphp

                                <div class="vehicle-result-card" data-price="{{ $pricing['base_amount'] ?? 0 }}" data-name="{{ $result['name'] ?? 'Unknown Vehicle' }}">
                                    <div class="row">
                                        <!-- Vehicle Image -->
                                        <div class="col-lg-4">
                                            <div class="vehicle-image-wrapper">
                                                <img src="{{ $mainImage }}" alt="{{ $result['name'] ?? 'Unknown Vehicle' }}" class="img-fluid">
                                                @if($availability['available'] > 0)
                                                    <span class="availability-badge available">
                                                        <i class="bi bi-check-circle"></i> Available
                                                    </span>
                                                @else
                                                    <span class="availability-badge unavailable">
                                                        <i class="bi bi-x-circle"></i> Not Available
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Vehicle Details -->
                                        <div class="col-lg-5">
                                            <div class="vehicle-details">
                                                <!-- Vehicle Name & Grade -->
                                                <div class="vehicle-header">
                                                    <h4>{{ $result['name'] ?? 'Unknown Vehicle' }}</h4>
                                                    @if(isset($result['category']['name']))
                                                        <span class="grade-badge">
                                                            {{ $result['category']['name'] }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <!-- Vehicle Info -->
                                                <div class="vehicle-info-grid">
                                                    @if(isset($result['description']))
                                                        <div class="info-item">
                                                            <i class="bi bi-info-circle"></i>
                                                            <span>{{ $result['description'] }}</span>
                                                        </div>
                                                    @endif

                                                    @if(isset($result['category']['name']))
                                                    <div class="info-item">
                                                        <i class="bi bi-tag"></i>
                                                        <span>{{ $result['category']['name'] }}</span>
                                                    </div>
                                                    @endif

                                                    <div class="info-item">
                                                        <i class="bi bi-car-front"></i>
                                                        <span>Available Count: {{ $result['available_count'] ?? 0 }}</span>
                                                    </div>

                                                    <div class="info-item">
                                                        <i class="bi bi-gear"></i>
                                                        <span>Total Count: {{ $result['total_count'] ?? 0 }}</span>
                                                    </div>
                                                </div>

                                                <!-- Features -->
                                                @if(isset($pricing['includes_driver']) && $pricing['includes_driver'] || isset($pricing['includes_fuel']) && $pricing['includes_fuel'])
                                                <div class="vehicle-features mt-3">
                                                    @if($pricing['includes_driver'])
                                                    <span class="feature-badge">
                                                        <i class="bi bi-person-check"></i> Driver Included
                                                    </span>
                                                    @endif
                                                    @if($pricing['includes_fuel'])
                                                    <span class="feature-badge">
                                                        <i class="bi bi-droplet-fill"></i> Fuel Included
                                                    </span>
                                                    @endif
                                                </div>
                                                @endif

                                                <!-- Description -->
                                                @if(isset($result['description']) && $result['description'])
                                                <p class="vehicle-description mt-3">
                                                    {{ Str::limit($result['description'], 150) }}
                                                </p>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Pricing & Actions -->
                                        <div class="col-lg-3">
                                            <div class="vehicle-pricing-card">
                                                <div class="pricing-header">
                                                    <span class="price-label">Starting Price</span>
                                                    <h3 class="price-amount">
                                                        {{ $pricing['currency'] ?? 'LKR' }} {{ number_format($pricing['base_amount'] ?? 0, 2) }}
                                                    </h3>
                                                    @if(isset($pricing['pricing_note']))
                                                    <span class="price-duration">
                                                        {{ $pricing['pricing_note'] }}
                                                    </span>
                                                    @endif
                                                </div>

                                                <!-- Pricing Breakdown -->
                                                <div class="pricing-breakdown">
                                                    <div class="breakdown-item">
                                                        <span>Base Rate</span>
                                                        <span>{{ $pricing['currency'] ?? 'LKR' }} {{ number_format($pricing['base_amount'] ?? 0, 2) }}</span>
                                                    </div>
                                                    @if(isset($pricing['breakdown']) && is_array($pricing['breakdown']))
                                                        @foreach($pricing['breakdown'] as $rate)
                                                        <div class="breakdown-item">
                                                            <span>{{ $rate['name'] ?? 'Additional Fee' }}</span>
                                                            <span>{{ $pricing['currency'] ?? 'LKR' }} {{ number_format($rate['amount'] ?? 0, 2) }}</span>
                                                        </div>
                                                        @endforeach
                                                    @endif
                                                </div>

                                                <!-- Action Buttons -->
                                                <div class="action-buttons mt-4">
                                                    <a href="{{ route('vehicle.details', ['id' => $result['id'], 'search' => $search->id]) }}" 
                                                       class="btn btn-primary w-100 mb-2">
                                                        <i class="bi bi-eye"></i> View Details
                                                    </a>
                                                    @if($availability['available'] > 0)
                                                    <form action="{{ route('cart.add') }}" method="POST" class="w-100">
                                                        @csrf
                                                        <input type="hidden" name="group_id" value="{{ $result['id'] }}">
                                                        <input type="hidden" name="search_id" value="{{ $search->id }}">
                                                        <input type="hidden" name="quantity" value="1">
                                                        <button type="submit" class="btn btn-success w-100">
                                                            <i class="bi bi-cart-plus"></i> Add to Cart
                                                        </button>
                                                    </form>
                                                    @else
                                                    <button type="button" class="btn btn-secondary w-100" disabled>
                                                        <i class="bi bi-exclamation-circle"></i> Not Available
                                                    </button>
                                                    @endif
                                                </div>

                                                <!-- Availability Info -->
                                                <div class="availability-info mt-3">
                                                    <small class="text-muted">
                                                        <i class="bi bi-car-front-fill"></i>
                                                        {{ $availability['available'] }} of {{ $availability['total'] }} available
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <!-- No Results Found -->
                        <div class="no-results-card">
                            <div class="text-center py-5">
                                <svg width="100" height="100" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M50 10C27.9 10 10 27.9 10 50C10 72.1 27.9 90 50 90C72.1 90 90 72.1 90 50C90 27.9 72.1 10 50 10ZM50 80C33.4 80 20 66.6 20 50C20 33.4 33.4 20 50 20C66.6 20 80 33.4 80 50C80 66.6 66.6 80 50 80Z" fill="#ddd"/>
                                    <path d="M35 45H65M35 55H55" stroke="#ddd" stroke-width="4" stroke-linecap="round"/>
                                </svg>
                                <h3 class="mt-4">No Vehicles Available</h3>
                                <p class="text-muted">Unfortunately, there are no vehicles available for your search criteria.<br>Try modifying your search dates or service type.</p>
                                <a href="{{ route('home') }}" class="btn btn-primary mt-3">
                                    <i class="bi bi-arrow-left"></i> Back to Home
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

@endsection

@push('styles')
<style>
    /* Search Summary Card */
    .search-summary-card {
        background: white;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        margin-bottom: 30px;
    }

    .search-info h5 {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 15px;
        color: #333;
    }

    .search-details {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }

    .detail-item {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #666;
    }

    .detail-item svg {
        fill: #ff8c00;
        flex-shrink: 0;
    }

    /* Vehicle Result Card */
    .vehicle-result-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
    }

    .vehicle-result-card:hover {
        box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }

    /* Vehicle Image */
    .vehicle-image-wrapper {
        position: relative;
        border-radius: 8px;
        overflow: hidden;
        height: 250px;
    }

    .vehicle-image-wrapper img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .availability-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        padding: 8px 15px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .availability-badge.available {
        background: #28a745;
        color: white;
    }

    .availability-badge.unavailable {
        background: #dc3545;
        color: white;
    }

    /* Vehicle Details */
    .vehicle-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .vehicle-header h4 {
        font-size: 22px;
        font-weight: 700;
        color: #333;
        margin: 0;
    }

    .grade-badge {
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .grade-badge.economy { background: #e3f2fd; color: #1976d2; }
    .grade-badge.standard { background: #f3e5f5; color: #7b1fa2; }
    .grade-badge.premium { background: #fff3e0; color: #f57c00; }
    .grade-badge.luxury { background: #fce4ec; color: #c2185b; }

    /* Vehicle Info Grid */
    .vehicle-info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }

    .info-item {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #666;
        font-size: 14px;
    }

    .info-item i {
        color: #ff8c00;
        font-size: 16px;
    }

    /* Features */
    .vehicle-features {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .feature-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        background: #f0f9ff;
        color: #0369a1;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
    }

    /* Pricing Card */
    .vehicle-pricing-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        padding: 25px;
        color: white;
        height: 100%;
    }

    .pricing-header {
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.2);
        padding-bottom: 20px;
        margin-bottom: 20px;
    }

    .price-label {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.9;
    }

    .price-amount {
        font-size: 32px;
        font-weight: 700;
        margin: 10px 0;
    }

    .price-duration {
        font-size: 13px;
        opacity: 0.9;
    }

    /* Pricing Breakdown */
    .pricing-breakdown {
        margin-bottom: 20px;
    }

    .breakdown-item {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        font-size: 13px;
    }

    .breakdown-item:last-child {
        border-bottom: none;
    }

    /* Action Buttons */
    .action-buttons .btn {
        font-weight: 600;
        padding: 12px;
        border-radius: 8px;
    }

    .availability-info {
        text-align: center;
        padding-top: 15px;
        border-top: 1px solid rgba(255,255,255,0.2);
    }

    .availability-info small {
        color: rgba(255,255,255,0.9);
    }

    /* No Results */
    .no-results-card {
        background: white;
        border-radius: 12px;
        padding: 60px 40px;
        text-align: center;
    }

    /* Responsive */
    @media (max-width: 991px) {
        .vehicle-image-wrapper {
            height: 200px;
            margin-bottom: 20px;
        }

        .vehicle-pricing-card {
            margin-top: 20px;
        }
    }
</style>
@endpush

@push('scripts')
<script>
    $(document).ready(function() {
        // Sort results
        $('#sortResults').on('change', function() {
            const sortBy = $(this).val();
            const $container = $('.vehicle-results-list');
            const $cards = $container.find('.vehicle-result-card');

            $cards.sort(function(a, b) {
                if (sortBy === 'price_low') {
                    return parseFloat($(a).data('price')) - parseFloat($(b).data('price'));
                } else if (sortBy === 'price_high') {
                    return parseFloat($(b).data('price')) - parseFloat($(a).data('price'));
                } else if (sortBy === 'name') {
                    return $(a).data('name').localeCompare($(b).data('name'));
                }
            });

            $container.html($cards);
        });

        // Cart forms are now handled by regular form submission
        // Success/error messages handled by Laravel session flash
    });
</script>
@endpush
