@php
/**
 * Airport Select Component
 * Custom dropdown that matches the predefined-location-selector style.
 * Replaces native <select> + nice-select with a custom PLS-style dropdown.
 *
 * Props:
 * - $selectId: The element ID for the hidden select (e.g. 'from-airport-select')
 * - $name: Form field name (pickup/dropoff)
 * - $airports: Collection/array of airport options
 * - $selectedValue: Currently selected airport name
 * - $placeholder: Placeholder text (e.g. 'Select Airport')
 * - $cssClass: Additional CSS classes for the wrapper (e.g. 'from-field hidden')
 * - $disabled: Whether the field starts disabled
 * - $required: Whether the field is required
 */

$dropdownId = $selectId . '_dropdown';
$selectedAirportName = '';
$selectedAirportCode = '';
$cleanedValue = '';

if ($selectedValue) {
    // Strip trailing " (Airport)" suffix that transformSearchParams may have appended
    $cleanedValue = preg_replace('/\s*\(Airport\)\s*$/i', '', $selectedValue);
    foreach ($airports as $airport) {
        $aName = is_array($airport) ? ($airport['name'] ?? '') : ($airport->name ?? '');
        $aCode = is_array($airport) ? ($airport['code'] ?? '') : ($airport->code ?? '');
        if ($aName === $selectedValue || $aName === $cleanedValue) {
            $selectedAirportName = $aName;
            $selectedAirportCode = $aCode;
            break;
        }
    }
    // If no match found but we have a value, select the first airport as fallback
    if (!$selectedAirportName && count($airports) > 0) {
        $firstAirport = is_array($airports) ? reset($airports) : $airports->first();
        if ($firstAirport) {
            $selectedAirportName = is_array($firstAirport) ? ($firstAirport['name'] ?? '') : ($firstAirport->name ?? '');
            $selectedAirportCode = is_array($firstAirport) ? ($firstAirport['code'] ?? '') : ($firstAirport->code ?? '');
            $selectedValue = $selectedAirportName;
            $cleanedValue = $selectedAirportName;
        }
    }
}

$isSelected = function ($airportName) use ($selectedValue, $cleanedValue) {
    return $selectedValue === $airportName || $cleanedValue === $airportName;
};

$displayText = $selectedAirportName
    ? $selectedAirportName . ($selectedAirportCode ? ' (' . $selectedAirportCode . ')' : '')
    : '';
@endphp

<div class="airport-pls-wrapper {{ $cssClass ?? '' }}" id="{{ $selectId }}_wrapper">
    {{-- Custom dropdown (PLS style) --}}
    <div class="pls-dropdown airport-pls-dropdown" id="{{ $dropdownId }}" data-select-id="{{ $selectId }}">
        <div class="pls-dropdown-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
            <span class="pls-dropdown-current {{ $displayText ? '' : 'pls-placeholder' }}">{{ $displayText ?: $placeholder }}</span>
            <span class="pls-dropdown-arrow"></span>
        </div>
        <ul class="pls-dropdown-list" role="listbox" style="display: none;">
            <li class="pls-dropdown-option {{ !$selectedValue ? 'pls-selected' : '' }}"
                data-value="" role="option">{{ $placeholder }}</li>
            @foreach($airports as $airport)
                @php
                    $aName = is_array($airport) ? ($airport['name'] ?? '') : ($airport->name ?? '');
                    $aCode = is_array($airport) ? ($airport['code'] ?? '') : ($airport->code ?? '');
                    $aLat  = is_array($airport) ? ($airport['latitude'] ?? '') : ($airport->latitude ?? '');
                    $aLng  = is_array($airport) ? ($airport['longitude'] ?? '') : ($airport->longitude ?? '');
                    $optionLabel = $aName . ($aCode ? ' (' . $aCode . ')' : '');
                @endphp
                <li class="pls-dropdown-option {{ $isSelected($aName) ? 'pls-selected' : '' }}"
                    data-value="{{ $aName }}" data-lat="{{ $aLat }}" data-lng="{{ $aLng }}"
                    role="option">{{ $optionLabel }}</li>
            @endforeach
        </ul>
    </div>

    {{-- Hidden native select for form submission & JS compatibility --}}
    <select id="{{ $selectId }}" name="{{ $name }}"
            class="no-nice airport-select {{ $cssClass ?? '' }}"
            style="display:none !important;position:absolute;opacity:0;pointer-events:none;"
            {{ ($disabled ?? false) ? 'disabled' : '' }}
            {{ ($required ?? false) ? 'required' : '' }}>
        <option value="">{{ $placeholder }}</option>
        @foreach($airports as $airport)
            @php
                $aName = is_array($airport) ? ($airport['name'] ?? '') : ($airport->name ?? '');
                $aCode = is_array($airport) ? ($airport['code'] ?? '') : ($airport->code ?? '');
                $aLat  = is_array($airport) ? ($airport['latitude'] ?? '') : ($airport->latitude ?? '');
                $aLng  = is_array($airport) ? ($airport['longitude'] ?? '') : ($airport->longitude ?? '');
            @endphp
            <option value="{{ $aName }}" data-lat="{{ $aLat }}" data-lng="{{ $aLng }}"
                    {{ $isSelected($aName) ? 'selected' : '' }}>
                {{ $aName }}{{ $aCode ? ' (' . $aCode . ')' : '' }}
            </option>
        @endforeach
    </select>
</div>
