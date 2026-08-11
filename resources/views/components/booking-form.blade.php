@php

    // Get booking form tabs from database (cached)
    $bookingTabs = Cache::remember('booking_form_tabs', 3600, function () {
        return \App\Models\BookingFormTab::getOrderedTabs();
    });
    $bookingTabsByCode = $bookingTabs->keyBy('code');
    $defaultTabCode = $bookingTabs->first(fn ($tab) => (bool) data_get($tab->metadata, 'is_default', false))?->code
        ?? $bookingTabs->first()?->code
        ?? 'airport_transfers';
    $hasSearchContext = isset($search) || session()->hasOldInput();

    $knownFormCodes = ['airport_transfers', 'ride_now', 'day_rental', 'corporate', 'wedding_hire', 'self_drive', 'with_driver'];
    $getFormServiceCodeForTab = function ($tab) use ($knownFormCodes) {
        $tabCode = $tab->code;
        if (in_array($tabCode, $knownFormCodes, true)) {
            return $tabCode;
        }
        $serviceTypeCode = $tab->service_type_code ?: null;
        if ($serviceTypeCode && in_array($serviceTypeCode, $knownFormCodes, true)) {
            return $serviceTypeCode;
        }
        return $tabCode;
    };

    // Ensure $search exists
    $search = $search ?? null;

    // Helper function to safely get search property
    $getSearchProp = function ($prop, $default = null) use ($search) {
        $oldValue = old($prop);
        if ($oldValue !== null) {
            return $oldValue;
        }
        if (!isset($search)) {
            return $default;
        }
        if (is_object($search) && property_exists($search, $prop)) {
            return $search->$prop;
        }
        if (is_array($search) && isset($search[$prop])) {
            return $search[$prop];
        }
        return $default;
    };

    // Resolve current tab code
    $currentTabCodeRaw = (string) $getSearchProp('service_type', $defaultTabCode);
    $currentTabCode = $currentTabCodeRaw;

    if (!$bookingTabsByCode->has($currentTabCode)) {
        $mappedTab = $bookingTabs->first(function ($tab) use ($currentTabCodeRaw) {
            return ($tab->service_type_code ?: '') === $currentTabCodeRaw;
        });
        if ($mappedTab) {
            $currentTabCode = $mappedTab->code;
        }
    }

    if (!$bookingTabsByCode->has($currentTabCode)) {
        $currentTabCode = $defaultTabCode;
    }

    $currentTab = $bookingTabsByCode->get($currentTabCode);
    $currentFormServiceType = $currentTab ? $getFormServiceCodeForTab($currentTab) : $currentTabCode;

    // For truly unknown service codes, keep as-is (dynamic forms handle them)
    if (!in_array($currentFormServiceType, $knownFormCodes, true)) {
        // Check if it's a valid tab code — if so, use it directly for dynamic rendering
        if (!$bookingTabsByCode->has($currentFormServiceType)) {
            $currentFormServiceType = $defaultTabCode;
        }
    }

    // Normalize pickup/dropoff locations: support string OR object/array with latitude/longitude
    $normalizeLocation = function ($search, $key) {
        $result = ['address' => null, 'lat' => null, 'lng' => null];
        if (!isset($search)) {
            return $result;
        }
        $latKey = $key . '_latitude';
        $lngKey = $key . '_longitude';
        if (isset($search->$latKey) || (is_array($search) && isset($search[$latKey]))) {
            $result['lat'] = is_object($search) ? $search->$latKey : $search[$latKey];
        }
        if (isset($search->$lngKey) || (is_array($search) && isset($search[$lngKey]))) {
            $result['lng'] = is_object($search) ? $search->$lngKey : $search[$lngKey];
        }
        $loc = null;
        if (is_object($search) && property_exists($search, $key)) {
            $loc = $search->$key;
        }
        if (is_array($search) && isset($search[$key])) {
            $loc = $search[$key];
        }
        if ($loc) {
            if (is_array($loc)) {
                $result['address'] = $loc['address'] ?? ($loc['name'] ?? null);
                $result['lat'] = $result['lat'] ?? ($loc['latitude'] ?? ($loc['lat'] ?? null));
                $result['lng'] = $result['lng'] ?? ($loc['longitude'] ?? ($loc['lng'] ?? null));
            } elseif (is_object($loc)) {
                $result['address'] = $loc->address ?? ($loc->name ?? null);
                $result['lat'] = $result['lat'] ?? ($loc->latitude ?? ($loc->lat ?? null));
                $result['lng'] = $result['lng'] ?? ($loc->longitude ?? ($loc->lng ?? null));
            } else {
                $result['address'] = $loc;
            }
        }
        return $result;
    };

    $pickup = $normalizeLocation($search ?? null, 'pickup_location');
    $dropoff = $normalizeLocation($search ?? null, 'dropoff_location');

    // Cross-service location & date intelligence
    $searchPickupDate = $getSearchProp('pickup_date') ?? $getSearchProp('from_date');
    $searchDropoffDate = $getSearchProp('dropoff_date') ?? $getSearchProp('to_date');
    $searchPickupTime = $getSearchProp('pickup_time') ?? $getSearchProp('from_time');
    $searchDropoffTime = $getSearchProp('dropoff_time') ?? $getSearchProp('to_time');

    $hasDropoffLocation = !empty($dropoff['address']) || (!empty($dropoff['lat']) && !empty($dropoff['lng']));
    $otherServicesPickupLocation = $hasDropoffLocation ? $dropoff : $pickup;
    $otherServicesPickupDate = $searchDropoffDate ?? $searchPickupDate;
    $otherServicesPickupTime = $searchDropoffTime ?? $searchPickupTime;
    $otherServicesDropoffDate = null;
    if ($otherServicesPickupDate) {
        try {
            $otherServicesDropoffDate = (new DateTime($otherServicesPickupDate))->format('Y-m-d');
        } catch (Exception $e) {}
    }

    $getLocationForService = function ($serviceType, $isPickup = true) use (
        $currentFormServiceType, $pickup, $dropoff, $otherServicesPickupLocation,
    ) {
        if ($serviceType === $currentFormServiceType) {
            return $isPickup ? $pickup : $dropoff;
        }
        if ($isPickup) {
            return $otherServicesPickupLocation;
        }
        return ['address' => null, 'lat' => null, 'lng' => null];
    };

    $getDateForService = function ($serviceType, $isPickup = true) use (
        $currentFormServiceType, $searchPickupDate, $searchDropoffDate,
        $otherServicesPickupDate, $otherServicesDropoffDate,
    ) {
        if ($serviceType === $currentFormServiceType) {
            return $isPickup ? $searchPickupDate : $searchDropoffDate;
        }
        return $isPickup ? $otherServicesPickupDate : $otherServicesDropoffDate;
    };

    $getTimeForService = function ($serviceType, $isPickup = true) use (
        $currentFormServiceType, $searchPickupTime, $searchDropoffTime, $otherServicesPickupTime,
    ) {
        if ($serviceType === $currentFormServiceType) {
            return $isPickup ? $searchPickupTime : $searchDropoffTime;
        }
        return $isPickup ? $otherServicesPickupTime : null;
    };

    // Airport transfer type
    $airportTransferType = $getSearchProp('transfer_type', 'from-airport');
    $airportTransferType = old('transfer_type', $airportTransferType);

    // Utility for safe old() fallback
    $safeOldOr = function ($field, $value) {
        $oldValue = old($field);
        return $oldValue !== null ? $oldValue : $value;
    };

    // Load service type models/configs once
    $tabServiceCodeMap = $bookingTabsByCode->mapWithKeys(function ($tab) {
        return [$tab->code => $tab->service_type_code ?: $tab->code];
    });

    $serviceTypeCodesForConfig = collect($knownFormCodes)
        ->merge($tabServiceCodeMap->values())
        ->filter()
        ->unique()
        ->values();

    try {
        $serviceTypeConfigs = \App\Models\Service\ServiceType::query()
            ->publicContext()
            ->whereIn('code', $serviceTypeCodesForConfig)
            ->where('is_active', true)
            ->orderBy('updated_at')
            ->get()
            ->keyBy('code');
    } catch (Exception $e) {
        $serviceTypeConfigs = collect();
    }

    $resolveConfigCode = function ($serviceCode) use ($serviceTypeConfigs, $tabServiceCodeMap) {
        if (!$serviceCode) return null;
        if ($serviceTypeConfigs->has($serviceCode)) return $serviceCode;
        $mappedCode = $tabServiceCodeMap->get($serviceCode);
        if ($mappedCode && $serviceTypeConfigs->has($mappedCode)) return $mappedCode;
        return null;
    };

    $getServiceTypeConfig = function ($serviceCode, $fallbackCode = null) use ($serviceTypeConfigs, $resolveConfigCode) {
        $resolvedCode = $resolveConfigCode($serviceCode);
        if ($resolvedCode) return $serviceTypeConfigs->get($resolvedCode);
        $fallbackResolvedCode = $resolveConfigCode($fallbackCode);
        if ($fallbackResolvedCode) return $serviceTypeConfigs->get($fallbackResolvedCode);
        return null;
    };

    $extractConfigFields = function ($serviceTypeModel, $serviceCode = null) {
        if (!$serviceTypeModel || !$serviceCode) {
            return [];
        }

        $resolved = app(\App\Services\DynamicServiceConfigurationService::class)
            ->getServiceFormConfiguration($serviceTypeModel->code ?: $serviceCode);

        return is_array($resolved['fields'] ?? null) ? $resolved['fields'] : [];
    };

    // Airport options
    try {
        $airportOptions = \App\Models\Airport::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['name', 'code', 'city', 'latitude', 'longitude']);
    } catch (Exception $e) {
        $airportOptions = collect();
    }
    if ($airportOptions->isEmpty()) {
        $airportOptions = collect([
            ['name' => 'Colombo BIA Airport', 'code' => 'BIA', 'city' => 'Colombo', 'latitude' => 7.1808, 'longitude' => 79.8841],
            ['name' => 'Mattala Rajapaksa Airport', 'code' => 'HRI', 'city' => 'Hambantota', 'latitude' => 6.2847, 'longitude' => 81.1242],
            ['name' => 'Jaffna International Airport', 'code' => 'JAF', 'city' => 'Jaffna', 'latitude' => 9.7923, 'longitude' => 80.0701],
        ]);
    }

    // Booking advance hours
    try {
        $bookingSettings = app(\App\Services\WebsiteSettingsService::class)->getBookingSettings();
        $bookingAdvanceHours = (int) ($bookingSettings['booking_advance_hours'] ?? 4);
        $bookingNoticeHtml = trim((string) ($bookingSettings['booking_notice_html'] ?? ''));
    } catch (Exception $e) {
        $bookingAdvanceHours = 0;
        $bookingNoticeHtml = '';
    }

    if ($bookingNoticeHtml === '' && $bookingAdvanceHours > 0) {
        $hourLabel = $bookingAdvanceHours . ' hour' . ($bookingAdvanceHours > 1 ? 's' : '');
        $bookingNoticeHtml = 'Booking Notice: Bookings must be made at least <strong>' . e($hourLabel) . '</strong> in advance.';
    }

    $formatBookingNotice = function (string $html): string {
        $allowedTags = '<strong><b><em><i><br><p><span><a>';
        $clean = strip_tags($html, $allowedTags);
        $clean = preg_replace('/<(?!a\b)([a-z][a-z0-9]*)\b[^>]*>/i', '<$1>', $clean);
        $clean = preg_replace_callback('/<a\b([^>]*)>/i', function ($matches) {
            $attrs = $matches[1] ?? '';
            if (preg_match('/href\s*=\s*([\'"])(.*?)\1/i', $attrs, $hrefMatch)) {
                $href = trim($hrefMatch[2]);
                if (str_starts_with(strtolower($href), 'tel:')) {
                    return '<a href="' . e($href) . '">';
                }
            }
            return '<a>';
        }, $clean);
        $clean = preg_replace_callback('/(?<![\\w"=:+>])(\+?\d[\d\s().-]{6,}\d)(?![^<]*>)/', function ($matches) {
            $display = $matches[1];
            $tel = preg_replace('/[^\d+]/', '', $display);
            if (strlen(preg_replace('/\D/', '', $tel)) < 7) {
                return e($display);
            }
            return '<a href="tel:' . e($tel) . '">' . e($display) . '</a>';
        }, $clean);
        return nl2br($clean, false);
    };

    // Predefined locations
    try {
        $predefinedLocations = \App\Models\PredefinedLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'address', 'latitude', 'longitude']);
    } catch (Exception $e) {
        $predefinedLocations = collect();
    }
@endphp

<div class="filter-wrapper {{ is_theme('theme-02') ? 't2-filter-wrapper' : theme_class('filter-wrapper') }}">
    <ul class="filter-item-list">
        @foreach ($bookingTabs as $tab)
            <li class="single-item {{ $currentTabCode === $tab->code ? 'active' : '' }}"
                data-service="{{ $tab->code }}"
                data-form-service="{{ $getFormServiceCodeForTab($tab) }}">
                <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    {!! $tab->icon_data !!}
                </svg>
                <span>{{ $tab->label }}</span>
            </li>
        @endforeach
    </ul>

    <div class="filter-input-wrap">
        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Please correct the following errors:</strong>
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- All forms rendered dynamically from form_config --}}
        @foreach($bookingTabs as $tab)
            @php
                $tabFormCode = $getFormServiceCodeForTab($tab);
                $tabServiceModel = $getServiceTypeConfig($tabFormCode);
                $tabFields = $extractConfigFields($tabServiceModel, $tabFormCode);
                $tabFormId = $tabFormCode . '-form';
                $tabIsActive = ($currentFormServiceType === $tabFormCode);
            @endphp

            @if(!empty($tabFields))
                @include('components.dynamic-booking-form', [
                    'tab' => $tab,
                    'serviceTypeModel' => $tabServiceModel,
                    'fields' => $tabFields,
                    'formId' => $tabFormId,
                    'isActive' => $tabIsActive,
                    'serviceCode' => $tabFormCode,
                    'getLocationForService' => $getLocationForService,
                    'getDateForService' => $getDateForService,
                    'getTimeForService' => $getTimeForService,
                    'safeOldOr' => $safeOldOr,
                    'predefinedLocations' => $predefinedLocations ?? collect(),
                    'airportOptions' => $airportOptions ?? collect(),
                    'airportTransferType' => $airportTransferType ?? null,
                    'hasSearchContext' => $hasSearchContext,
                ])
            @endif
        @endforeach

        @if ($bookingNoticeHtml !== '')
            <div class="booking-advance-note alert alert-warning d-flex align-items-center mt-3" role="note" style="margin-bottom:12px;">
                <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-right:8px;flex-shrink:0;">
                    <path d="M8 1.333c-3.683 0-6.667 2.984-6.667 6.667S4.317 14.667 8 14.667 14.667 11.683 14.667 8 11.683 1.333 8 1.333zm0 9.334a.667.667 0 110 1.334.667.667 0 010-1.334zM7.333 4.667h1.334V9.33H7.333V4.667z" fill="#856404" />
                </svg>
                <div style="color:#856404;">
                    {!! $formatBookingNotice($bookingNoticeHtml) !!}
                </div>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize package loading for all service types
        if (typeof initializeServicePackages === 'function') {
            initializeServicePackages();
        }

        // ============================================================================
        // DROPOFF LOCATION SYNC (for predefined_or_custom location mode)
        // ============================================================================
        function setupDropoffSync(formId, prefix) {
            var form = document.getElementById(formId);
            if (!form) return;

            var dropoffWrapper = document.getElementById(prefix + '_dropoff_wrapper');
            var dropoffInput = document.getElementById(prefix + '_dropoff_input');
            var dropoffLat = document.getElementById(prefix + '_dropoff_lat');
            var dropoffLng = document.getElementById(prefix + '_dropoff_lng');
            var dropoffHidden = document.getElementById(prefix + '_dropoff_hidden');
            var dropoffLatHidden = document.getElementById(prefix + '_dropoff_lat_hidden');
            var dropoffLngHidden = document.getElementById(prefix + '_dropoff_lng_hidden');
            var dropoffPredefined = document.getElementById(prefix + '_dropoff_predefined');

            function applyPredefined(address, lat, lng, code) {
                if (dropoffWrapper) dropoffWrapper.style.display = 'none';
                if (dropoffInput) { dropoffInput.disabled = true; dropoffInput.required = false; dropoffInput.value = ''; }
                if (dropoffLat) dropoffLat.disabled = true;
                if (dropoffLng) dropoffLng.disabled = true;
                if (dropoffHidden) { dropoffHidden.value = address; dropoffHidden.disabled = false; }
                if (dropoffLatHidden) { dropoffLatHidden.value = lat; dropoffLatHidden.disabled = false; }
                if (dropoffLngHidden) { dropoffLngHidden.value = lng; dropoffLngHidden.disabled = false; }
                if (dropoffPredefined) { dropoffPredefined.value = code; dropoffPredefined.disabled = false; }
            }

            function applyCustom() {
                if (dropoffWrapper) dropoffWrapper.style.display = 'block';
                if (dropoffInput) { dropoffInput.disabled = false; dropoffInput.required = true; }
                if (dropoffLat) dropoffLat.disabled = false;
                if (dropoffLng) dropoffLng.disabled = false;
                if (dropoffHidden) dropoffHidden.disabled = true;
                if (dropoffLatHidden) dropoffLatHidden.disabled = true;
                if (dropoffLngHidden) dropoffLngHidden.disabled = true;
                if (dropoffPredefined) { dropoffPredefined.value = ''; dropoffPredefined.disabled = true; }
            }

            function applyNone() {
                if (dropoffWrapper) dropoffWrapper.style.display = 'none';
                if (dropoffInput) { dropoffInput.disabled = true; dropoffInput.required = false; dropoffInput.value = ''; }
                if (dropoffLat) dropoffLat.disabled = true;
                if (dropoffLng) dropoffLng.disabled = true;
                if (dropoffHidden) { dropoffHidden.value = ''; dropoffHidden.disabled = true; }
                if (dropoffLatHidden) { dropoffLatHidden.value = ''; dropoffLatHidden.disabled = true; }
                if (dropoffLngHidden) { dropoffLngHidden.value = ''; dropoffLngHidden.disabled = true; }
                if (dropoffPredefined) { dropoffPredefined.value = ''; dropoffPredefined.disabled = true; }
            }

            form.addEventListener('pls-selection', function(e) {
                var detail = e.detail;
                if (detail.fieldName !== 'pickup') return;
                if (detail.type === 'predefined') {
                    applyPredefined(detail.address, detail.lat, detail.lng, detail.value);
                } else if (detail.type === 'custom') {
                    applyCustom();
                } else {
                    applyNone();
                }
            });

            // Restore dropoff state on page load
            (function initDropoffState() {
                var pickupDropdown = form.querySelector('.pls-dropdown[data-field-name="pickup"]');
                if (!pickupDropdown) return;
                var selectedOption = pickupDropdown.querySelector('.pls-dropdown-option.pls-selected');
                if (!selectedOption) return;
                var selType = selectedOption.dataset.type;
                var selValue = selectedOption.dataset.value;
                if (selType === 'predefined' && selValue) {
                    applyPredefined(selectedOption.dataset.address || '', selectedOption.dataset.lat || '', selectedOption.dataset.lng || '', selValue);
                } else if (selType === 'custom') {
                    applyCustom();
                } else {
                    applyNone();
                }
            })();

            // Initialize Google Places autocomplete on dropoff input
            function initDropoffAutocomplete() {
                if (!dropoffInput || dropoffInput.getAttribute('data-autocomplete-initialized') === 'true') return;
                if (window.google && google.maps && google.maps.places) {
                    var autocomplete = new google.maps.places.Autocomplete(dropoffInput, {
                        componentRestrictions: { country: 'lk' },
                        fields: ['place_id', 'geometry', 'name', 'formatted_address']
                    });
                    autocomplete.addListener('place_changed', function() {
                        var place = autocomplete.getPlace();
                        if (place && place.geometry) {
                            if (dropoffLat) dropoffLat.value = place.geometry.location.lat();
                            if (dropoffLng) dropoffLng.value = place.geometry.location.lng();
                        }
                    });
                    dropoffInput.setAttribute('data-autocomplete-initialized', 'true');
                }
            }
            initDropoffAutocomplete();
            setTimeout(initDropoffAutocomplete, 2000);
            setTimeout(initDropoffAutocomplete, 5000);
        }

        // ============================================================================
        // AUTO-INITIALIZE ALL FORMS
        // ============================================================================
        document.querySelectorAll('.filter-input').forEach(function(form) {
            var formId = form.id;
            var serviceCode = form.getAttribute('data-service') || '';
            var prefixMap = {
                'airport_transfers': 'at', 'ride_now': 'rn', 'day_rental': 'dr',
                'self_drive': 'sd', 'with_driver': 'wd', 'wedding_hire': 'wh', 'corporate': 'co'
            };
            var prefix = prefixMap[serviceCode] || serviceCode.replace(/[^a-z]/g, '').substring(0, 3);

            // Ensure all radio groups have a checked option (fallback to first option)
            form.querySelectorAll('.transfer-type-toggle, .radio-options').forEach(function(radioGroup) {
                var radios = radioGroup.querySelectorAll('input[type="radio"]');
                if (radios.length > 0) {
                    var hasChecked = Array.from(radios).some(function(r) { return r.checked; });
                    if (!hasChecked) {
                        radios[0].checked = true;
                        console.log('[RADIO FIX] Auto-checked first radio:', radios[0].name, '=', radios[0].value);
                    }
                }
            });

            // Initialize dropoff sync if form has a dropoff wrapper
            var dropoffWrapper = document.getElementById(prefix + '_dropoff_wrapper');
            if (dropoffWrapper) {
                setupDropoffSync(formId, prefix);
            }

            // Initialize conditional location fields (e.g., airport transfers)
            var conditionalLocations = form.querySelectorAll('.dynamic-conditional-location');
            conditionalLocations.forEach(function(container) {
                var condField = container.dataset.conditionField;
                var fieldName = container.dataset.fieldName;
                var variants = container.querySelectorAll('.conditional-variant');
                var sharedLat = container.querySelector('.conditional-lat');
                var sharedLng = container.querySelector('.conditional-lng');

                function updateConditionalVisibility() {
                    var checkedRadio = form.querySelector('input[name="' + condField + '"]:checked');
                    var currentValue = checkedRadio ? checkedRadio.value : '';
                    var normalizedCurrentValue = currentValue.toLowerCase().replace(/-/g, '_');
                    variants.forEach(function(variant) {
                        var condValue = variant.dataset.conditionValue;
                        var isCurrentVariant = condValue === currentValue
                            || condValue.toLowerCase().replace(/-/g, '_') === normalizedCurrentValue;
                        if (isCurrentVariant) {
                            variant.style.display = 'block';
                            variant.querySelectorAll('input, select').forEach(function(inp) { inp.disabled = false; });
                        } else {
                            variant.style.display = 'none';
                            variant.querySelectorAll('input, select').forEach(function(inp) {
                                if (inp.type !== 'hidden') inp.disabled = true;
                            });
                        }
                    });
                }

                var radios = form.querySelectorAll('input[name="' + condField + '"]');
                radios.forEach(function(radio) {
                    radio.addEventListener('change', updateConditionalVisibility);
                });
                updateConditionalVisibility();

                // Sync airport select changes to shared lat/lng hidden inputs
                // Only sync from the ACTIVE (visible) variant to avoid hidden variants overwriting
                container.querySelectorAll('select.airport-select').forEach(function(airportSelect) {
                    airportSelect.addEventListener('change', function() {
                        var parentVariant = this.closest('.conditional-variant');
                        if (parentVariant && parentVariant.style.display === 'none') return;
                        var opt = this.options[this.selectedIndex];
                        if (opt && opt.dataset.lat && opt.dataset.lng && sharedLat && sharedLng) {
                            sharedLat.value = opt.dataset.lat;
                            sharedLng.value = opt.dataset.lng;
                        }
                    });
                });

                // Initial sync: find the ACTIVE variant and sync its coordinates
                (function syncActiveVariantCoords() {
                    var checkedRadio = form.querySelector('input[name="' + condField + '"]:checked');
                    var activeValue = checkedRadio ? checkedRadio.value : '';
                    if (!activeValue) return;
                    var activeVariant = container.querySelector('.conditional-variant[data-condition-value="' + activeValue + '"]');
                    if (!activeVariant) {
                        var normalizedActiveValue = activeValue.toLowerCase().replace(/-/g, '_');
                        activeVariant = Array.from(variants).find(function(variant) {
                            return variant.dataset.conditionValue.toLowerCase().replace(/-/g, '_') === normalizedActiveValue;
                        });
                    }
                    if (!activeVariant) return;

                    // Check if active variant has an airport select with a value
                    var airportSelect = activeVariant.querySelector('select.airport-select');
                    if (airportSelect && airportSelect.value) {
                        var opt = airportSelect.options[airportSelect.selectedIndex];
                        if (opt && opt.dataset.lat && opt.dataset.lng && sharedLat && sharedLng) {
                            sharedLat.value = opt.dataset.lat;
                            sharedLng.value = opt.dataset.lng;
                        }
                    }
                    // If active variant is autocomplete, shared lat/lng are already set from server-side defaults
                })();

                // When transfer type changes, sync the newly active variant's coordinates
                radios.forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        var newValue = this.value;
                        var newVariant = container.querySelector('.conditional-variant[data-condition-value="' + newValue + '"]');
                        if (!newVariant) {
                            var normalizedNewValue = newValue.toLowerCase().replace(/-/g, '_');
                            newVariant = Array.from(variants).find(function(variant) {
                                return variant.dataset.conditionValue.toLowerCase().replace(/-/g, '_') === normalizedNewValue;
                            });
                        }
                        if (!newVariant) return;
                        var airportSelect = newVariant.querySelector('select.airport-select');
                        if (airportSelect && airportSelect.value) {
                            var opt = airportSelect.options[airportSelect.selectedIndex];
                            if (opt && opt.dataset.lat && opt.dataset.lng && sharedLat && sharedLng) {
                                sharedLat.value = opt.dataset.lat;
                                sharedLng.value = opt.dataset.lng;
                            }
                        } else {
                            // Autocomplete variant: read data-default-lat/lng from the input
                            var locInput = newVariant.querySelector('input.location-search');
                            if (locInput) {
                                var defLat = locInput.getAttribute('data-default-lat') || '';
                                var defLng = locInput.getAttribute('data-default-lng') || '';
                                if (defLat && defLng && sharedLat && sharedLng) {
                                    sharedLat.value = defLat;
                                    sharedLng.value = defLng;
                                }
                            }
                        }
                    });
                });
            });
        });

        // ============================================================================
        // AIRPORT PLS DROPDOWN INITIALIZATION
        // ============================================================================
        (function initAirportPlsDropdowns() {
            document.querySelectorAll('.airport-pls-dropdown').forEach(function(dropdown) {
                var trigger = dropdown.querySelector('.pls-dropdown-trigger');
                var list = dropdown.querySelector('.pls-dropdown-list');
                var currentLabel = dropdown.querySelector('.pls-dropdown-current');
                var selectId = dropdown.dataset.selectId;
                var hiddenSelect = document.getElementById(selectId);
                if (!trigger || !list || !hiddenSelect) return;

                trigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var isOpen = dropdown.classList.contains('pls-open');
                    document.querySelectorAll('.pls-dropdown.pls-open').forEach(function(d) {
                        d.classList.remove('pls-open');
                        var l = d.querySelector('.pls-dropdown-list');
                        if (l) l.style.display = 'none';
                        var t = d.querySelector('.pls-dropdown-trigger');
                        if (t) t.setAttribute('aria-expanded', 'false');
                    });
                    if (!isOpen) {
                        dropdown.classList.add('pls-open');
                        list.style.display = 'block';
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                });

                trigger.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); trigger.click(); }
                });

                list.addEventListener('click', function(e) {
                    var option = e.target.closest('.pls-dropdown-option');
                    if (!option) return;
                    list.querySelectorAll('.pls-dropdown-option').forEach(function(o) { o.classList.remove('pls-selected'); });
                    option.classList.add('pls-selected');
                    var value = option.dataset.value;
                    var lat = option.dataset.lat;
                    var lng = option.dataset.lng;
                    
                    if (value) {
                        currentLabel.textContent = option.textContent.trim();
                        currentLabel.classList.remove('pls-placeholder');
                    } else {
                        currentLabel.textContent = hiddenSelect.querySelector('option[value=""]')?.textContent?.trim() || 'Select Airport';
                        currentLabel.classList.add('pls-placeholder');
                    }
                    hiddenSelect.value = value;
                    
                    // Set coordinates in the shared conditional location hidden inputs
                    // Find the parent conditional location wrapper
                    var conditionalWrapper = dropdown.closest('.dynamic-conditional-location');
                    if (conditionalWrapper) {
                        var latInput = conditionalWrapper.querySelector('.conditional-lat');
                        var lngInput = conditionalWrapper.querySelector('.conditional-lng');
                        if (latInput && lat) latInput.value = lat;
                        if (lngInput && lng) lngInput.value = lng;
                    } else {
                        // Fallback: try to find coordinate inputs by name pattern
                        // Extract field name from selectId (e.g., 'at_pickup_from-airport' -> 'pickup')
                        var form = dropdown.closest('form');
                        if (form) {
                            // Try to determine the field name from the hidden select name
                            var fieldName = hiddenSelect.name; // e.g., 'pickup' or 'dropoff'
                            var latInput = form.querySelector('input[name="' + fieldName + '_lat"]');
                            var lngInput = form.querySelector('input[name="' + fieldName + '_lng"]');
                            if (latInput && lat) latInput.value = lat;
                            if (lngInput && lng) lngInput.value = lng;
                        }
                    }
                    
                    hiddenSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    dropdown.classList.remove('pls-open');
                    list.style.display = 'none';
                    trigger.setAttribute('aria-expanded', 'false');
                });

                hiddenSelect.addEventListener('airport-pls-sync', function() {
                    var val = hiddenSelect.value;
                    var matchedOption = list.querySelector('.pls-dropdown-option[data-value="' + val + '"]');
                    list.querySelectorAll('.pls-dropdown-option').forEach(function(o) { o.classList.remove('pls-selected'); });
                    if (matchedOption) {
                        matchedOption.classList.add('pls-selected');
                        currentLabel.textContent = matchedOption.textContent.trim();
                        currentLabel.classList.remove('pls-placeholder');
                        
                        // Also set coordinates when syncing
                        var lat = matchedOption.dataset.lat;
                        var lng = matchedOption.dataset.lng;
                        var conditionalWrapper = dropdown.closest('.dynamic-conditional-location');
                        if (conditionalWrapper) {
                            var latInput = conditionalWrapper.querySelector('.conditional-lat');
                            var lngInput = conditionalWrapper.querySelector('.conditional-lng');
                            if (latInput && lat) latInput.value = lat;
                            if (lngInput && lng) lngInput.value = lng;
                        }
                    } else {
                        currentLabel.textContent = hiddenSelect.querySelector('option[value=""]')?.textContent?.trim() || 'Select Airport';
                        currentLabel.classList.add('pls-placeholder');
                    }
                });
                
                // Initialize coordinates on page load if airport is pre-selected
                if (hiddenSelect.value) {
                    var selectedOption = list.querySelector('.pls-dropdown-option[data-value="' + hiddenSelect.value + '"]');
                    if (selectedOption) {
                        var lat = selectedOption.dataset.lat;
                        var lng = selectedOption.dataset.lng;
                        var conditionalWrapper = dropdown.closest('.dynamic-conditional-location');
                        if (conditionalWrapper && lat && lng) {
                            var latInput = conditionalWrapper.querySelector('.conditional-lat');
                            var lngInput = conditionalWrapper.querySelector('.conditional-lng');
                            if (latInput) latInput.value = lat;
                            if (lngInput) lngInput.value = lng;
                        }
                    }
                }
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.pls-dropdown')) {
                    document.querySelectorAll('.airport-pls-dropdown.pls-open').forEach(function(d) {
                        d.classList.remove('pls-open');
                        var l = d.querySelector('.pls-dropdown-list');
                        if (l) l.style.display = 'none';
                        var t = d.querySelector('.pls-dropdown-trigger');
                        if (t) t.setAttribute('aria-expanded', 'false');
                    });
                }
            });
        })();

        // ============================================================================
        // TAB SWITCHING
        // ============================================================================
        const filterItems = document.querySelectorAll('.filter-item-list .single-item');
        const filterInputs = document.querySelectorAll('.filter-input');

        filterItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const service = this.getAttribute('data-service');
                const formService = this.getAttribute('data-form-service') || service;
                const redirectUrl = this.getAttribute('data-redirect');

                if (redirectUrl) {
                    window.location.href = redirectUrl;
                    return;
                }

                filterItems.forEach(fi => fi.classList.remove('active'));
                this.classList.add('active');

                filterInputs.forEach(input => input.classList.remove('show'));

                const targetForm = document.querySelector(`.filter-input[data-service="${formService}"]`);
                if (targetForm) {
                    targetForm.classList.add('show');
                    // Re-initialize conditional fields when switching tabs
                    targetForm.querySelectorAll('.dynamic-conditional-location').forEach(function(container) {
                        var condField = container.dataset.conditionField;
                        var checkedRadio = targetForm.querySelector('input[name="' + condField + '"]:checked');
                        if (checkedRadio) {
                            checkedRadio.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                }
            });
        });

        // Ensure correct form is visible on page load
        (function() {
            const activeItem = document.querySelector('.filter-item-list .single-item.active');
            if (!activeItem) return;
            const formService = activeItem.getAttribute('data-form-service') || activeItem.getAttribute('data-service');
            filterInputs.forEach(form => form.classList.remove('show'));
            const targetForm = document.querySelector(`.filter-input[data-service="${formService}"]`);
            if (targetForm) targetForm.classList.add('show');
        })();

        // ============================================================================
        // SUBMIT BUTTON RESTORE (handles browser back/forward cache)
        // ============================================================================
        function restoreSubmitButton(form) {
            var submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) return;
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            var btnText = submitBtn.querySelector('span');
            if (btnText && submitBtn._originalText) {
                btnText.textContent = submitBtn._originalText;
            }
        }

        document.querySelectorAll('.filter-input').forEach(function(form) {
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                var btnText = submitBtn.querySelector('span');
                if (btnText) submitBtn._originalText = btnText.textContent;
            }
        });

        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                document.querySelectorAll('.filter-input').forEach(restoreSubmitButton);
            }
        });

        // ============================================================================
        // GOOGLE PLACES AUTOCOMPLETE DROPDOWN POSITIONING
        // ============================================================================
        if (typeof $ !== 'undefined') {
            $(document).ready(function() {
                function adjustPacForInput(input) {
                    setTimeout(function() {
                        var pac = document.querySelector('.pac-container');
                        if (!pac) return;
                        var container = document.querySelector('.filter-wrapper') || document.querySelector('.container');
                        var targetWidth = Math.min(container ? container.offsetWidth - 40 : window.innerWidth - 24, 900);
                        if (window.innerWidth <= 576) {
                            pac.style.left = '12px';
                            pac.style.width = 'calc(100% - 24px)';
                            pac.style.maxWidth = 'none';
                        } else {
                            if (container) {
                                pac.style.left = (container.getBoundingClientRect().left + 20) + 'px';
                            } else {
                                pac.style.left = (input.getBoundingClientRect().left) + 'px';
                            }
                            pac.style.width = targetWidth + 'px';
                            pac.style.maxWidth = '900px';
                        }
                        pac.style.boxSizing = 'border-box';
                        pac.style.zIndex = 99999;
                    }, 80);
                }

                document.querySelectorAll('input.location-search').forEach(function(el) {
                    el.addEventListener('focus', function() { adjustPacForInput(el); });
                    el.addEventListener('input', function() { adjustPacForInput(el); });
                    window.addEventListener('resize', function() {
                        if (document.activeElement === el) adjustPacForInput(el);
                    });
                });
            });
        }
    });
</script>
@endpush
