@php
/**
 * Predefined Location Selector Component
 * Custom dropdown that looks identical to the airport select fields.
 * Shows predefined locations + "My Doorstep" option.
 *
 * Props:
 * - $name: Field name (pickup/dropoff)
 * - $label: Field label
 * - $required: Whether field is required
 * - $currentValue: Current location value
 * - $currentLat: Current latitude
 * - $currentLng: Current longitude
 * - $predefinedLocations: Collection of predefined locations
 */

$prefix = isset($prefix) ? $prefix . '_' : '';
$selectId = $prefix . $name . '_location_select';
$customInputId = $prefix . $name . '_custom_input';
$predefinedCodeId = $prefix . $name . '_predefined_code';

$selectedLocationCode = '';
$selectedLocationName = '';
$isCustom = true;

if ($currentValue && isset($predefinedLocations) && !empty($predefinedLocations)) {
    foreach ($predefinedLocations as $location) {
        $lCode = is_array($location) ? $location['code'] : $location->code;
        $lName = is_array($location) ? $location['name'] : $location->name;
        $lAddr = is_array($location) ? ($location['address'] ?? $lName) : ($location->address ?? $lName);
        if ($lName === $currentValue || $lAddr === $currentValue) {
            $selectedLocationCode = $lCode;
            $selectedLocationName = $lName;
            $isCustom = false;
            break;
        }
    }
}

if ($isCustom && $currentValue) {
    $selectedLocationCode = 'custom';
    $selectedLocationName = 'My Doorstep (Enter Custom Location)';
}
@endphp

<!-- Location Selector (styled like airport select) -->
<div class="single-search-box location-search-box">
    <div class="d-flex align-items-center gap-2 py-1">
        <label class="input-label">{{ $label }}</label>
        <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
            <g>
                <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
            </g>
        </svg>
    </div>
    <div class="custom-select-dropdown">
        {{-- Custom dropdown trigger (looks like a text input) --}}
        <div class="pls-dropdown" id="{{ $selectId }}_dropdown" data-field-name="{{ $name }}">
            <div class="pls-dropdown-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                <span class="pls-dropdown-current {{ $selectedLocationName ? '' : 'pls-placeholder' }}">{{ $selectedLocationName ?: 'Select ' . $label }}</span>
                <span class="pls-dropdown-arrow"></span>
            </div>
            <ul class="pls-dropdown-list" role="listbox" style="display: none;">
                <li class="pls-dropdown-option {{ !$selectedLocationCode ? 'pls-selected' : '' }}" data-value="" data-type="none" role="option">Select {{ $label }}</li>
                @if(isset($predefinedLocations) && count($predefinedLocations) > 0)
                    @foreach($predefinedLocations as $location)
                        @php
                            $locCode = is_array($location) ? $location['code'] : $location->code;
                            $locName = is_array($location) ? $location['name'] : $location->name;
                            $locLat  = is_array($location) ? $location['latitude'] : $location->latitude;
                            $locLng  = is_array($location) ? $location['longitude'] : $location->longitude;
                            $locAddr = is_array($location) ? ($location['address'] ?? $locName) : ($location->address ?? $locName);
                        @endphp
                        <li class="pls-dropdown-option {{ $selectedLocationCode === $locCode ? 'pls-selected' : '' }}"
                            data-value="{{ $locCode }}" data-type="predefined"
                            data-lat="{{ $locLat }}" data-lng="{{ $locLng }}" data-address="{{ $locAddr }}"
                            role="option">{{ $locName }}</li>
                    @endforeach
                @endif
                <li class="pls-dropdown-option {{ $selectedLocationCode === 'custom' ? 'pls-selected' : '' }}" data-value="custom" data-type="custom" role="option">My Doorstep (Enter Custom Location)</li>
            </ul>
        </div>

        {{-- Hidden native select for form submission (kept in sync by JS, excluded from nice-select) --}}
        <select id="{{ $selectId }}" name="{{ $name }}_type" class="no-nice location-type-select"
                data-field-name="{{ $name }}"
                style="display:none !important;position:absolute;opacity:0;pointer-events:none;"
                {{ $required && !$isCustom ? 'required' : '' }}>
            <option value="">Select {{ $label }}</option>
            @if(isset($predefinedLocations) && count($predefinedLocations) > 0)
                @foreach($predefinedLocations as $location)
                    @php
                        $locCode = is_array($location) ? $location['code'] : $location->code;
                        $locName = is_array($location) ? $location['name'] : $location->name;
                        $locLat  = is_array($location) ? $location['latitude'] : $location->latitude;
                        $locLng  = is_array($location) ? $location['longitude'] : $location->longitude;
                        $locAddr = is_array($location) ? ($location['address'] ?? $locName) : ($location->address ?? $locName);
                    @endphp
                    <option value="{{ $locCode }}" data-type="predefined"
                            data-lat="{{ $locLat }}" data-lng="{{ $locLng }}" data-address="{{ $locAddr }}"
                            {{ $selectedLocationCode === $locCode ? 'selected' : '' }}>{{ $locName }}</option>
                @endforeach
            @endif
            <option value="custom" data-type="custom" {{ $selectedLocationCode === 'custom' ? 'selected' : '' }}>My Doorstep (Enter Custom Location)</option>
        </select>
    </div>
    @error($name)
        <span class="text-danger small">{{ $message }}</span>
    @enderror
</div>

<!-- Custom Location Input (shown when "My Doorstep" is selected) -->
<div class="single-search-box location-search-box custom-location-box"
     id="{{ $customInputId }}_wrapper"
     style="display: {{ $isCustom && $currentValue ? 'block' : 'none' }};">
    <div class="d-flex align-items-center gap-2 py-1">
        <label class="input-label">Enter {{ $label }}</label>
        <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
            <g>
                <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
            </g>
        </svg>
    </div>
    <div class="custom-select-dropdown">
        <input type="text" name="{{ $name }}" id="{{ $customInputId }}"
               placeholder="Enter your {{ strtolower($label) }}"
               class="location-search @error($name) is-invalid @enderror"
               value="{{ $isCustom ? $currentValue : '' }}"
               {{ $isCustom && $currentValue && $required ? 'required' : '' }}>
        <input type="hidden" name="{{ $name }}_lat" class="location-lat" value="{{ $currentLat }}">
        <input type="hidden" name="{{ $name }}_lng" class="location-lng" value="{{ $currentLng }}">
    </div>
    @error($name)
        <span class="text-danger small">{{ $message }}</span>
    @enderror
</div>

<!-- Hidden fields for predefined location data -->
<input type="hidden" name="{{ $name }}_predefined" id="{{ $predefinedCodeId }}" value="{{ !$isCustom ? $selectedLocationCode : '' }}">

<script>
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        var dropdown = document.getElementById('{{ $selectId }}_dropdown');
        if (!dropdown) return;

        var trigger = dropdown.querySelector('.pls-dropdown-trigger');
        var list = dropdown.querySelector('.pls-dropdown-list');
        var currentLabel = dropdown.querySelector('.pls-dropdown-current');
        var fieldName = dropdown.dataset.fieldName;
        var hiddenSelect = document.getElementById('{{ $selectId }}');
        var customWrapper = document.getElementById('{{ $customInputId }}_wrapper');
        var customInput = document.getElementById('{{ $customInputId }}');
        var predefinedCodeInput = document.getElementById('{{ $predefinedCodeId }}');
        var parentForm = dropdown.closest('form');

        // INITIALIZATION: If a predefined location is already selected (e.g. redirect back),
        // create the hidden input[name="fieldName"] with address so the form submits correctly
        (function initPredefinedState() {
            var selectedOption = list.querySelector('.pls-dropdown-option.pls-selected');
            if (!selectedOption) return;
            var selType = selectedOption.dataset.type;
            var selValue = selectedOption.dataset.value;
            if (selType === 'predefined' && selValue) {
                var address = selectedOption.dataset.address || '';
                var lat = selectedOption.dataset.lat || '';
                var lng = selectedOption.dataset.lng || '';
                // Hide custom input, disable it
                if (customWrapper) customWrapper.style.display = 'none';
                if (customInput) { customInput.disabled = true; customInput.required = false; }
                // Create hidden field for the location name
                var searchScope = parentForm || document;
                var hiddenField = searchScope.querySelector('input[name="' + fieldName + '"][type="hidden"]:not([id$="_hidden"])');
                if (!hiddenField) {
                    hiddenField = document.createElement('input');
                    hiddenField.type = 'hidden';
                    hiddenField.name = fieldName;
                    dropdown.parentElement.appendChild(hiddenField);
                }
                hiddenField.value = address;
                hiddenField.setAttribute('data-place-selected', 'true');
                hiddenField.setAttribute('data-is-default', 'true');
                // Ensure lat/lng are set
                if (customInput) {
                    var latInput = customInput.parentElement ? customInput.parentElement.querySelector('.location-lat') : null;
                    var lngInput = customInput.parentElement ? customInput.parentElement.querySelector('.location-lng') : null;
                    if (latInput) latInput.value = lat;
                    if (lngInput) lngInput.value = lng;
                }
            }
        })();

        // Toggle dropdown
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var isOpen = dropdown.classList.contains('pls-open');
            closeAllPlsDropdowns();
            if (!isOpen) {
                dropdown.classList.add('pls-open');
                list.style.display = 'block';
                trigger.setAttribute('aria-expanded', 'true');
            }
        });

        trigger.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                trigger.click();
            }
        });

        // Option click
        list.addEventListener('click', function(e) {
            var option = e.target.closest('.pls-dropdown-option');
            if (!option) return;

            // Update visual state
            list.querySelectorAll('.pls-dropdown-option').forEach(function(o) { o.classList.remove('pls-selected'); });
            option.classList.add('pls-selected');

            var value = option.dataset.value;
            var type = option.dataset.type;
            var text = option.textContent.trim();

            // Update trigger text
            if (value) {
                currentLabel.textContent = text;
                currentLabel.classList.remove('pls-placeholder');
            } else {
                currentLabel.textContent = 'Select {{ $label }}';
                currentLabel.classList.add('pls-placeholder');
            }

            // Sync hidden select
            if (hiddenSelect) {
                hiddenSelect.value = value;
                hiddenSelect.dispatchEvent(new Event('change'));
            }

            // Close dropdown
            dropdown.classList.remove('pls-open');
            list.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');

            // Handle selection logic
            if (type === 'predefined' && value) {
                if (customWrapper) customWrapper.style.display = 'none';
                if (customInput) { customInput.disabled = true; customInput.required = false; customInput.value = ''; }

                var address = option.dataset.address;
                var lat = option.dataset.lat;
                var lng = option.dataset.lng;

                if (customInput) {
                    var latInput = customInput.parentElement.querySelector('.location-lat');
                    var lngInput = customInput.parentElement.querySelector('.location-lng');
                    if (latInput) latInput.value = lat;
                    if (lngInput) lngInput.value = lng;
                }

                if (predefinedCodeInput) predefinedCodeInput.value = value;

                var searchScope = parentForm || document;
                var hiddenField = searchScope.querySelector('input[name="' + fieldName + '"][type="hidden"]:not([id$="_hidden"])');
                if (!hiddenField) {
                    hiddenField = document.createElement('input');
                    hiddenField.type = 'hidden';
                    hiddenField.name = fieldName;
                    dropdown.parentElement.appendChild(hiddenField);
                }
                hiddenField.value = address;
                hiddenField.setAttribute('data-place-selected', 'true');
                hiddenField.setAttribute('data-is-default', 'true');

                // Dispatch event for parent form to sync dropoff
                dropdown.dispatchEvent(new CustomEvent('pls-selection', {
                    bubbles: true,
                    detail: { type: 'predefined', value: value, address: address, lat: lat, lng: lng, fieldName: fieldName }
                }));

            } else if (type === 'custom') {
                if (customWrapper) customWrapper.style.display = 'block';
                if (customInput) {
                    customInput.disabled = false;
                    customInput.required = {{ $required ? 'true' : 'false' }};
                    setTimeout(function() { customInput.focus(); }, 100);
                }
                if (predefinedCodeInput) predefinedCodeInput.value = '';
                var hf = (parentForm || document).querySelector('input[name="' + fieldName + '"][type="hidden"]');
                if (hf) hf.remove();

                // Dispatch event for parent form to show dropoff input
                dropdown.dispatchEvent(new CustomEvent('pls-selection', {
                    bubbles: true,
                    detail: { type: 'custom', value: '', address: '', lat: '', lng: '', fieldName: fieldName }
                }));

            } else {
                if (customWrapper) customWrapper.style.display = 'none';
                if (customInput) { customInput.disabled = true; customInput.required = false; }
                if (predefinedCodeInput) predefinedCodeInput.value = '';
                var hf2 = (parentForm || document).querySelector('input[name="' + fieldName + '"][type="hidden"]');
                if (hf2) hf2.remove();

                // Dispatch event for parent form to clear dropoff
                dropdown.dispatchEvent(new CustomEvent('pls-selection', {
                    bubbles: true,
                    detail: { type: 'none', value: '', address: '', lat: '', lng: '', fieldName: fieldName }
                }));
            }
        });

        // Close on outside click
        function closeAllPlsDropdowns() {
            document.querySelectorAll('.pls-dropdown.pls-open').forEach(function(d) {
                d.classList.remove('pls-open');
                var l = d.querySelector('.pls-dropdown-list');
                if (l) l.style.display = 'none';
                var t = d.querySelector('.pls-dropdown-trigger');
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.pls-dropdown')) {
                closeAllPlsDropdowns();
            }
        });
    });
})();
</script>
