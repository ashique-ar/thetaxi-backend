<section class="t4-fleet featured-vehicles-section" aria-labelledby="t4-fleet-title">
    <div class="container">
        <header class="t4-section-heading">
            <span class="t4-kicker">Featured fleet</span>
            <h2 id="t4-fleet-title">{{ $settings['featured_vehicles_title'] ?? 'Featured Rental Vehicles' }}</h2>
            <p>{{ $settings['featured_vehicles_description'] ?? 'Choose from our premium selection of vehicles for your rental needs. All vehicles come with flexible rental options and competitive pricing.' }}</p>
        </header>

        <div class="swiper featured-vehicles-slider t4-fleet__slider">
            <div class="swiper-wrapper">
                @foreach (array_chunk($featuredVehicles['data'], 4) as $vehicleChunk)
                    <div class="swiper-slide">
                        <div class="row g-4">
                            @foreach ($vehicleChunk as $vehicle)
                                @php
                                    $pricing = $vehicle['pricing_info'] ?? ['base_amount' => 0, 'currency' => 'LKR'];
                                    $enhancedPricing = $vehicle['enhanced_pricing'] ?? [];
                                    $serviceFeatures = $vehicle['service_features'] ?? [];
                                    $availability = ['available' => $vehicle['available_count'] ?? 0, 'total' => $vehicle['total_count'] ?? 0];
                                    $isRecommended = $vehicle['recommended'] ?? false;
                                @endphp
                                <div class="t4-fleet__card col-lg-3 col-md-6 col-sm-6">
                                    <x-vehicle-card
                                        :vehicle="$vehicle"
                                        :pricing="$pricing"
                                        :enhancedPricing="$enhancedPricing"
                                        :serviceFeatures="$serviceFeatures"
                                        :availability="$availability"
                                        :searchId="$featuredVehicleSearch['id']"
                                        :isRecommended="$isRecommended"
                                        :showBookNow="true"
                                        :showViewDetails="false" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <button type="button" class="featured-vehicles-prev featured-vehicles-nav" aria-label="Previous featured vehicles"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <button type="button" class="featured-vehicles-next featured-vehicles-nav" aria-label="Next featured vehicles"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </div>
        <div class="featured-vehicles-pagination swiper-pagination2 paginations" aria-label="Featured vehicle pages"></div>
        <div class="t4-fleet__all"><a href="{{ route('cms.index', ['contentType' => 'ride_now']) }}" class="primary-btn1">{{ $settings['vehicles_view_all_text'] ?? 'View All Rental Vehicles' }}</a></div>
    </div>
</section>
