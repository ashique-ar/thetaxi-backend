<div class="{{ !is_theme('theme-04') ? 'col-lg-3' : '' }} col-md-4 col-sm-6 col-6 vehicle-card-wrapper"
    data-vehicle-group="{{ $result['id'] }}" data-price="{{ $pricing['base_amount'] ?? 0 }}"
    data-name="{{ $result['name'] ?? 'Unknown Vehicle' }}">
    <x-vehicle-card :vehicle="$result" :pricing="$pricing" :enhancedPricing="$result['enhanced_pricing'] ?? []"
        :serviceFeatures="$result['service_features'] ?? []" :availability="$availability" :searchId="$searchId"
        :isRecommended="$result['recommended'] ?? false" :showBookNow="true" :showViewDetails="false" />
</div>