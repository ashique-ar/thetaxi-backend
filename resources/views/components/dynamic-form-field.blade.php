{{--
    Dynamic Form Field Renderer
    Renders a single form field based on its configuration from service_types.form_config

    Props:
    - $fieldName: string - The config key (e.g. 'pickup_location', 'pickup_date')
    - $field: array - Field config with type, label, required, placeholder, location_mode, submit_as, etc.
    - $prefix: string - Form prefix for unique IDs (e.g. 'at', 'rn', 'sd')
    - $formId: string - Parent form ID
    - $currentValue: mixed - Current field value (from old() or search)
    - $currentLat: string|null - Current latitude (for location fields)
    - $currentLng: string|null - Current longitude (for location fields)
    - $predefinedLocations: Collection|null - For predefined_or_custom location mode
    - $airportOptions: Collection|null - For airport location mode
    - $servicePackages: Collection|null - For package select fields
    - $transferType: string|null - For airport conditional fields
--}}

@php
    $type = $field['type'] ?? 'text';
    $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $fieldName));
    $required = (bool) ($field['required'] ?? false);
    $placeholder = $field['placeholder'] ?? '';
    $placeholderExamples = array_values(array_filter(
        is_array($field['placeholder_examples'] ?? null) ? $field['placeholder_examples'] : [],
        static fn ($example) => is_string($example) && trim($example) !== ''
    ));
    $encodedPlaceholderExamples = $placeholderExamples
        ? base64_encode(json_encode($placeholderExamples, JSON_UNESCAPED_UNICODE))
        : '';
    $datePlaceholder = $settings['booking_date_placeholder'] ?? 'DD/MM/YYYY';
    $airportSelectPlaceholder = $settings['booking_airport_select_placeholder'] ?? 'Select Airport';
    $submitAs = $field['submit_as'] ?? $fieldName;
    $locationMode = $field['location_mode'] ?? ($field['location_type'] ?? 'autocomplete');
    $defaultValue = $field['default'] ?? '';
    $width = $field['width'] ?? 'auto';
    $hint = $field['hint'] ?? '';
    $options = $field['options'] ?? [];
    $conditionField = $field['condition_field'] ?? '';
    $conditions = $field['conditions'] ?? [];
    $syncFrom = $field['sync_from'] ?? '';
    $visibleWhen = $field['visible_when'] ?? null;

    // Build unique element IDs
    $elementId = $prefix . '_' . $submitAs;
    $fieldValue = $currentValue ?? $defaultValue;
@endphp

@switch($type)
    {{-- ===== LOCATION FIELD ===== --}}
    @case('location')
        @switch($locationMode)
            {{-- Predefined locations + custom doorstep --}}
            @case('predefined_or_custom')
                @include('components.predefined-location-selector', [
                    'name' => $submitAs,
                    'prefix' => $prefix,
                    'label' => $label,
                    'required' => $required,
                    'currentValue' => $fieldValue,
                    'currentLat' => $currentLat ?? '',
                    'currentLng' => $currentLng ?? '',
                    'predefinedLocations' => $predefinedLocations ?? collect(),
                    'configuredOptions' => $options,
                ])
                @break

            {{-- Airport selector --}}
            @case('airport')
                <div class="booking-field">
                    <label class="input-label">{{ $label }}</label>
                    <div class="single-search-box location-search-box">
                        @include('components.partials.location-icon')
                        <div class="custom-select-dropdown">
                            @include('components.airport-select', [
                            'selectId' => $elementId,
                            'name' => $submitAs,
                            'airports' => $airportOptions ?? collect(),
                            'selectedValue' => $fieldValue,
                            'placeholder' => $placeholder ?: $airportSelectPlaceholder,
                            'required' => $required,
                            ])
                        </div>
                    </div>
                    @error($submitAs)
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>
                @break

            {{-- Conditional location (airport transfers: switches between airport select and autocomplete) --}}
            @case('conditional')
                @php
                    $condField = $conditionField ?: 'transfer_type';
                    $fieldDefaultLat = $field['default_lat'] ?? '';
                    $fieldDefaultLng = $field['default_lng'] ?? '';
                    $effectiveLat = ($currentLat !== null && $currentLat !== '') ? $currentLat : $fieldDefaultLat;
                    $effectiveLng = ($currentLng !== null && $currentLng !== '') ? $currentLng : $fieldDefaultLng;

                    // Determine the active condition value for server-side rendering
                    $condKeys = array_keys($conditions);
                    $activeCondValue = '';
                    $normalizeConditionValue = static fn ($value) => str_replace('-', '_', strtolower(trim((string) $value)));

                    // Try transferType prop first (from parent booking form)
                    if ($condField === 'transfer_type' && !empty($transferType)) {
                        $activeCondValue = $transferType;
                    }

                    // Older defaults use hyphens while Service Type forms may use underscores.
                    // Resolve both formats to the actual saved condition key.
                    if (!empty($activeCondValue) && !in_array($activeCondValue, $condKeys, true)) {
                        $normalizedActiveValue = $normalizeConditionValue($activeCondValue);
                        foreach ($condKeys as $condKey) {
                            if ($normalizeConditionValue($condKey) === $normalizedActiveValue) {
                                $activeCondValue = $condKey;
                                break;
                            }
                        }
                    }

                    // Validate activeCondValue is actually a valid condition key
                    // If not (e.g. DB options use different values), fall back to first key
                    if (empty($activeCondValue) || !in_array($activeCondValue, $condKeys, true)) {
                        $activeCondValue = $condKeys[0] ?? '';
                    }
                @endphp
                {{-- This renders both variants; JS toggles visibility based on condition --}}
                <div class="dynamic-conditional-location"
                     data-condition-field="{{ $condField }}"
                     data-field-name="{{ $submitAs }}"
                     data-conditions="{{ json_encode($conditions) }}">
                    {{-- Shared lat/lng hidden inputs for all variants (airport select + autocomplete both write here) --}}
                    <input type="hidden" name="{{ $submitAs }}_lat" class="location-lat conditional-lat" id="{{ $elementId }}_lat" value="{{ $effectiveLat }}">
                    <input type="hidden" name="{{ $submitAs }}_lng" class="location-lng conditional-lng" id="{{ $elementId }}_lng" value="{{ $effectiveLng }}">

                    @foreach($conditions as $condValue => $condConfig)
                        @php
                            $condType = $condConfig['type'] ?? 'any';
                            $isActiveVariant = ($condValue === $activeCondValue);
                            $conditionalAirportOptions = !empty($condConfig['options'])
                                ? collect($condConfig['options'])
                                : ($airportOptions ?? collect());
                        @endphp
                        <div class="conditional-variant" data-condition-value="{{ $condValue }}" style="display:{{ $isActiveVariant ? 'block' : 'none' }};">
                            @if($condType === 'airport')
                                <div class="booking-field">
                                    <label class="input-label">{{ $label }}</label>
                                    <div class="single-search-box location-search-box">
                                        @include('components.partials.location-icon')
                                        <div class="custom-select-dropdown">
                                            @include('components.airport-select', [
                                            'selectId' => $elementId . '_' . $condValue,
                                            'name' => $submitAs,
                                            'airports' => $conditionalAirportOptions,
                                            'selectedValue' => $isActiveVariant ? $fieldValue : '',
                                            'placeholder' => $airportSelectPlaceholder,
                                            'required' => $required,
                                            'disabled' => !$isActiveVariant,
                                            ])
                                        </div>
                                    </div>
                                    @error($submitAs)
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>
                            @else
                                <div class="booking-field">
                                    <label class="input-label">{{ $label }}</label>
                                    <div class="single-search-box location-search-box">
                                        @include('components.partials.location-icon')
                                        <div class="custom-select-dropdown">
                                            <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}_{{ $condValue }}"
                                               placeholder="{{ $placeholder ?: 'Enter ' . strtolower($label) }}"
                                               class="location-search @error($submitAs) is-invalid @enderror"
                                               @if($encodedPlaceholderExamples) data-placeholder-examples="{{ $encodedPlaceholderExamples }}" @endif
                                               data-placeholder-fallback="{{ $placeholder ?: 'Enter ' . strtolower($label) }}"
                                               value="{{ $isActiveVariant ? $fieldValue : '' }}"
                                               data-default-value="{{ $defaultValue }}"
                                               data-default-lat="{{ $fieldDefaultLat }}"
                                               data-default-lng="{{ $fieldDefaultLng }}"
                                               data-current-value="{{ $isActiveVariant ? $fieldValue : '' }}"
                                               data-current-lat="{{ $isActiveVariant ? ($currentLat ?? '') : '' }}"
                                               data-current-lng="{{ $isActiveVariant ? ($currentLng ?? '') : '' }}"
                                               {{ $required ? 'required' : '' }}
                                               {{ !$isActiveVariant ? 'disabled' : '' }}
                                                   autocomplete="off">
                                        </div>
                                    </div>
                                    @error($submitAs)
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @break

            {{-- Default: Google Places autocomplete --}}
            @default
                <div class="booking-field">
                    <label class="input-label">{{ $label }}</label>
                    <div class="single-search-box location-search-box">
                        @include('components.partials.location-icon')
                        <div class="custom-select-dropdown">
                            <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}"
                               placeholder="{{ $placeholder ?: 'Enter ' . strtolower($label) }}"
                               class="location-search @error($submitAs) is-invalid @enderror"
                               @if($encodedPlaceholderExamples) data-placeholder-examples="{{ $encodedPlaceholderExamples }}" @endif
                               data-placeholder-fallback="{{ $placeholder ?: 'Enter ' . strtolower($label) }}"
                               data-default-value="{{ $defaultValue }}"
                               data-default-lat="{{ $field['default_lat'] ?? '' }}"
                               data-default-lng="{{ $field['default_lng'] ?? '' }}"
                               data-current-value="{{ $fieldValue }}"
                               data-current-lat="{{ $currentLat ?? '' }}"
                               data-current-lng="{{ $currentLng ?? '' }}"
                               value="{{ $fieldValue }}"
                               {{ $required ? 'required' : '' }}
                               autocomplete="off">
                            <input type="hidden" name="{{ $submitAs }}_lat" class="location-lat" value="{{ ($currentLat !== null && $currentLat !== '') ? $currentLat : ($field['default_lat'] ?? '') }}">
                            <input type="hidden" name="{{ $submitAs }}_lng" class="location-lng" value="{{ ($currentLng !== null && $currentLng !== '') ? $currentLng : ($field['default_lng'] ?? '') }}">
                        </div>
                    </div>
                    @error($submitAs)
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>
        @endswitch
        @break

    {{-- ===== DATE FIELD ===== --}}
    @case('date')
        <div class="booking-field">
            <label class="input-label" for="{{ $elementId }}">{{ $label }}</label>
            <div class="single-search-box date-field">
                @include('components.partials.calendar-icon')
                <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}"
                   placeholder="{{ $placeholder ?: $datePlaceholder }}"
                   class="custom-datepicker @error($submitAs) is-invalid @enderror"
                   value="{{ $fieldValue }}"
                   {{ $required ? 'required' : '' }}
                   data-enable-time="false"
                   data-date-format="d/m/Y"
                   data-min-date="today"
                   autocomplete="off"
                       readonly>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== TIME FIELD ===== --}}
    @case('time')
        <div class="booking-field">
            <label class="input-label">{{ $label }}</label>
            <div class="single-search-box">
                @include('components.partials.clock-icon')
                <div class="custom-select-dropdown">
                    <input type="time" name="{{ $submitAs }}" id="{{ $elementId }}"
                       value="{{ $fieldValue }}"
                       placeholder="{{ $placeholder }}"
                       class="@error($submitAs) is-invalid @enderror"
                           {{ $required ? 'required' : '' }}>
                </div>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== DATE & TIME FIELD ===== --}}
    @case('datetime')
        <div class="booking-field">
            <label class="input-label" for="{{ $elementId }}">{{ $label }}</label>
            <div class="single-search-box date-field">
                @include('components.partials.calendar-icon')
                <input type="datetime-local" name="{{ $submitAs }}" id="{{ $elementId }}"
                   placeholder="{{ $placeholder }}"
                   value="{{ $fieldValue }}"
                   class="@error($submitAs) is-invalid @enderror"
                   {{ $required ? 'required' : '' }}>
            </div>
            @if($hint)
                <small class="text-muted">{{ $hint }}</small>
            @endif
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== SELECT FIELD ===== --}}
    @case('select')
        <div class="booking-field">
            <label class="input-label">{{ $label }}</label>
            <div class="single-search-box">
                <div class="custom-select-dropdown">
                    <select name="{{ $submitAs }}" id="{{ $elementId }}"
                        class="no-nice @error($submitAs) is-invalid @enderror"
                        {{ $required ? 'required' : '' }}>
                    <option value="">{{ $placeholder ?: 'Select ' . $label }}</option>
                    @foreach($options as $option)
                        <option value="{{ $option['value'] }}"
                                {{ $fieldValue == $option['value'] ? 'selected' : '' }}>
                            {{ $option['label'] }}
                        </option>
                    @endforeach
                    </select>
                </div>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== RADIO FIELD ===== --}}
    @case('radio')
        @php
            $isTransferType = ($submitAs === 'transfer_type');
            // Determine which option should be checked
            $checkedValue = $fieldValue ?: $defaultValue;
            // If still no value and we have options, default to the first option
            if (empty($checkedValue) && !empty($options)) {
                $checkedValue = $options[0]['value'] ?? '';
            }
        @endphp
        @if($isTransferType)
            <div class="transfer-type-selector text-center justify-items-center" id="{{ $elementId }}_wrapper">
                <div class="transfer-type-toggle">
                    @foreach($options as $option)
                        <label class="transfer-type-option">
                            <input type="radio" name="{{ $submitAs }}" value="{{ $option['value'] }}"
                                   {{ $checkedValue == $option['value'] ? 'checked' : '' }}
                                   {{ $required ? 'required' : '' }}>
                            <span>{{ $option['label'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error($submitAs)
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>
        @else
            <div class="booking-field" id="{{ $elementId }}_wrapper">
                <span class="input-label" id="{{ $elementId }}_label">{{ $label }}</span>
                <div class="single-search-box radio-field" role="radiogroup" aria-labelledby="{{ $elementId }}_label">
                    <div class="radio-options d-flex gap-3 flex-wrap">
                        @foreach($options as $option)
                            <label class="radio-option d-flex align-items-center gap-1">
                                <input type="radio" name="{{ $submitAs }}" value="{{ $option['value'] }}"
                                       {{ $checkedValue == $option['value'] ? 'checked' : '' }}
                                       {{ $required ? 'required' : '' }}>
                                <span>{{ $option['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @error($submitAs)
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>
        @endif
        @break

    {{-- ===== CHECKBOX FIELD ===== --}}
    @case('checkbox')
        <div class="booking-field">
            <label class="input-label" for="{{ $elementId }}">{{ $label }}</label>
            <div class="single-search-box checkbox-field">
                <input type="checkbox" name="{{ $submitAs }}" id="{{ $elementId }}"
                       value="1"
                       {{ $fieldValue ? 'checked' : '' }}
                       {{ $required ? 'required' : '' }}>
                @if($hint)
                    <small class="text-muted">{{ $hint }}</small>
                @endif
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== TEXT / TEXTAREA / NUMBER ===== --}}
    @case('textarea')
        <div class="booking-field">
            <label class="input-label">{{ $label }}</label>
            <div class="single-search-box booking-textarea-box">
                <div class="custom-select-dropdown">
                    <textarea name="{{ $submitAs }}" id="{{ $elementId }}"
                          placeholder="{{ $placeholder }}"
                          class="booking-textarea @error($submitAs) is-invalid @enderror"
                          {{ $required ? 'required' : '' }}
                              rows="3">{{ $fieldValue }}</textarea>
                </div>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    @case('number')
        @php
            $minimum = data_get($field, 'validation.min');
            $maximum = data_get($field, 'validation.max');
        @endphp
        <div class="booking-field">
            <label class="input-label">{{ $label }}</label>
            <div class="single-search-box">
                <div class="custom-select-dropdown">
                    <input type="number" name="{{ $submitAs }}" id="{{ $elementId }}"
                       placeholder="{{ $placeholder }}"
                       value="{{ $fieldValue }}"
                       class="@error($submitAs) is-invalid @enderror"
                       {{ $required ? 'required' : '' }}
                       @if($minimum !== null && $minimum !== '') min="{{ $minimum }}" @endif
                       @if($maximum !== null && $maximum !== '') max="{{ $maximum }}" @endif>
                </div>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== HIDDEN FIELD ===== --}}
    @case('hidden')
        <input type="hidden" name="{{ $submitAs }}" id="{{ $elementId }}" value="{{ $fieldValue }}">
        @break

    {{-- ===== PACKAGE SELECT (special) ===== --}}
    @case('package_select')
        @if(isset($servicePackages) && $servicePackages->count() > 1)
        <div class="booking-field package-selector-field">
            <label class="input-label">{{ $label }}</label>
            {{-- Only show package selector if there are 2 or more packages --}}

                
                {{-- Button-style package selector --}}
                <div class="package-buttons-wrapper">
                    @foreach($servicePackages as $pkg)
                        <label class="package-button {{ $loop->first && !$fieldValue ? 'active' : ($fieldValue == $pkg->id ? 'active' : '') }}" data-package-id="{{ $pkg->id }}">
                            <input type="radio" 
                                   name="{{ $submitAs }}" 
                                   value="{{ $pkg->id }}" 
                                   class="package-radio-input"
                                   {{ ($loop->first && !$fieldValue) || $fieldValue == $pkg->id ? 'checked' : '' }}
                                   {{ $required ? 'required' : '' }}>
                            <span class="package-button-content">
                                <span class="package-name">{{ $pkg->name }}</span>
                                @if($pkg->max_km_per_day)
                                    <span class="package-detail">{{ number_format($pkg->max_km_per_day) }} km</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @elseif(isset($servicePackages) && $servicePackages->count() === 1)
            {{-- One package is authoritative and does not need a visible selector. --}}
            <input type="hidden" name="{{ $submitAs }}" value="{{ $servicePackages->first()->id }}">
        @endif
        @break

    {{-- ===== DEFAULT: TEXT INPUT ===== --}}
    @default
        <div class="booking-field">
            <label class="input-label">{{ $label }}</label>
            <div class="single-search-box">
                <div class="custom-select-dropdown">
                    <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}"
                       placeholder="{{ $placeholder }}"
                       value="{{ $fieldValue }}"
                       class="@error($submitAs) is-invalid @enderror"
                           {{ $required ? 'required' : '' }}>
                </div>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
@endswitch
