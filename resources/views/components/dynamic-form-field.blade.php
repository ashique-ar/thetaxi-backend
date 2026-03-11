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
    $submitAs = $field['submit_as'] ?? $fieldName;
    $locationMode = $field['location_mode'] ?? 'autocomplete';
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
                ])
                @break

            {{-- Airport selector --}}
            @case('airport')
                <div class="single-search-box location-search-box">
                    <div class="d-flex align-items-center gap-2 py-1">
                        <label class="input-label">{{ $label }}</label>
                        @include('components.partials.location-icon')
                    </div>
                    <div class="custom-select-dropdown">
                        @include('components.airport-select', [
                            'selectId' => $elementId,
                            'name' => $submitAs,
                            'airports' => $airportOptions ?? collect(),
                            'selectedValue' => $fieldValue,
                            'placeholder' => $placeholder ?: 'Select Airport',
                            'required' => $required,
                        ])
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

                    // Determine the active condition value for server-side rendering
                    $condKeys = array_keys($conditions);
                    $activeCondValue = '';

                    // Try transferType prop first (from parent booking form)
                    if ($condField === 'transfer_type' && !empty($transferType)) {
                        $activeCondValue = $transferType;
                    }

                    // Validate activeCondValue is actually a valid condition key
                    // If not (e.g. DB options use different values), fall back to first key
                    if (empty($activeCondValue) || !in_array($activeCondValue, $condKeys, true)) {
                        $activeCondValue = $condKeys[0] ?? '';
                    }
                @endphp
                <!-- DEBUG conditional: condField={{ $condField }} activeCondValue={{ json_encode($activeCondValue) }} transferType={{ json_encode($transferType ?? 'NULL') }} conditionKeys={{ json_encode($condKeys) }} -->
                {{-- This renders both variants; JS toggles visibility based on condition --}}
                <div class="dynamic-conditional-location"
                     data-condition-field="{{ $condField }}"
                     data-field-name="{{ $submitAs }}"
                     data-conditions="{{ json_encode($conditions) }}">
                    {{-- Shared lat/lng hidden inputs for all variants (airport select + autocomplete both write here) --}}
                    <input type="hidden" name="{{ $submitAs }}_lat" class="location-lat conditional-lat" id="{{ $elementId }}_lat" value="{{ $currentLat ?? $fieldDefaultLat }}">
                    <input type="hidden" name="{{ $submitAs }}_lng" class="location-lng conditional-lng" id="{{ $elementId }}_lng" value="{{ $currentLng ?? $fieldDefaultLng }}">

                    @foreach($conditions as $condValue => $condConfig)
                        @php
                            $condType = $condConfig['type'] ?? 'any';
                            $isActiveVariant = ($condValue === $activeCondValue);
                        @endphp
                        <div class="conditional-variant" data-condition-value="{{ $condValue }}" style="display:{{ $isActiveVariant ? 'block' : 'none' }};">
                            @if($condType === 'airport')
                                <div class="single-search-box location-search-box">
                                    <div class="d-flex align-items-center gap-2 py-1">
                                        <label class="input-label">{{ $label }}</label>
                                        @include('components.partials.location-icon')
                                    </div>
                                    <div class="custom-select-dropdown">
                                        @include('components.airport-select', [
                                            'selectId' => $elementId . '_' . $condValue,
                                            'name' => $submitAs,
                                            'airports' => $airportOptions ?? collect(),
                                            'selectedValue' => $isActiveVariant ? $fieldValue : '',
                                            'placeholder' => 'Select Airport',
                                            'required' => $required,
                                            'disabled' => !$isActiveVariant,
                                        ])
                                    </div>
                                    @error($submitAs)
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>
                            @else
                                <div class="single-search-box location-search-box">
                                    <div class="d-flex align-items-center gap-2 py-1">
                                        <label class="input-label">{{ $label }}</label>
                                        @include('components.partials.location-icon')
                                    </div>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}_{{ $condValue }}"
                                               placeholder="{{ $placeholder ?: 'Enter ' . strtolower($label) }}"
                                               class="location-search @error($submitAs) is-invalid @enderror"
                                               value="{{ $isActiveVariant ? $fieldValue : '' }}"
                                               data-default-value="{{ $fieldValue }}"
                                               data-default-lat="{{ $currentLat ?? $fieldDefaultLat }}"
                                               data-default-lng="{{ $currentLng ?? $fieldDefaultLng }}"
                                               {{ $required ? 'required' : '' }}
                                               {{ !$isActiveVariant ? 'disabled' : '' }}
                                               autocomplete="off">
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
                <div class="single-search-box location-search-box">
                    <div class="d-flex align-items-center gap-2 py-1">
                        <label class="input-label">{{ $label }}</label>
                        @include('components.partials.location-icon')
                    </div>
                    <div class="custom-select-dropdown">
                        <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}"
                               placeholder="{{ $placeholder ?: 'Enter ' . strtolower($label) }}"
                               class="location-search @error($submitAs) is-invalid @enderror"
                               value="{{ $fieldValue }}"
                               {{ $required ? 'required' : '' }}
                               autocomplete="off">
                        <input type="hidden" name="{{ $submitAs }}_lat" class="location-lat" value="{{ $currentLat ?? ($field['default_lat'] ?? '') }}">
                        <input type="hidden" name="{{ $submitAs }}_lng" class="location-lng" value="{{ $currentLng ?? ($field['default_lng'] ?? '') }}">
                    </div>
                    @error($submitAs)
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>
        @endswitch
        @break

    {{-- ===== DATE FIELD ===== --}}
    @case('date')
        <div class="single-search-box date-field">
            <div class="d-flex align-items-center gap-2 py-1">
                <label class="input-label">{{ $label }}</label>
                @include('components.partials.calendar-icon')
            </div>
            <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}"
                   placeholder="DD/MM/YYYY"
                   class="custom-datepicker @error($submitAs) is-invalid @enderror"
                   value="{{ $fieldValue ?: date('d/m/Y') }}"
                   {{ $required ? 'required' : '' }}
                   autocomplete="off">
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== TIME FIELD ===== --}}
    @case('time')
        <div class="single-search-box">
            <div class="d-flex align-items-center gap-2 py-1">
                <label class="input-label">{{ $label }}</label>
                @include('components.partials.clock-icon')
            </div>
            <div class="custom-select-dropdown">
                <input type="time" name="{{ $submitAs }}" id="{{ $elementId }}"
                       value="{{ $fieldValue ?: ($defaultValue ?: '09:00') }}"
                       class="@error($submitAs) is-invalid @enderror"
                       {{ $required ? 'required' : '' }}>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    {{-- ===== SELECT FIELD ===== --}}
    @case('select')
        <div class="single-search-box">
            <div class="d-flex align-items-center gap-2 py-1">
                <label class="input-label">{{ $label }}</label>
            </div>
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
        <!-- DEBUG radio: submitAs={{ $submitAs }} fieldValue={{ json_encode($fieldValue) }} defaultValue={{ json_encode($defaultValue) }} checkedValue={{ json_encode($checkedValue) }} optionCount={{ count($options) }} options={{ json_encode($options) }} -->
        <div class="{{ $isTransferType ? 'transfer-type-selector' : 'single-search-box radio-field' }}" id="{{ $elementId }}_wrapper">
            @if(!$isTransferType)
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">{{ $label }}</label>
                </div>
            @endif
            <div class="{{ $isTransferType ? 'transfer-type-toggle' : 'radio-options d-flex gap-3 flex-wrap' }}">
                @foreach($options as $option)
                    <label class="{{ $isTransferType ? 'transfer-type-option' : 'radio-option d-flex align-items-center gap-1' }}">
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
        @break

    {{-- ===== CHECKBOX FIELD ===== --}}
    @case('checkbox')
        <div class="single-search-box checkbox-field">
            <label class="d-flex align-items-center gap-2">
                <input type="checkbox" name="{{ $submitAs }}" id="{{ $elementId }}"
                       value="1" {{ $fieldValue ? 'checked' : '' }}>
                <span class="input-label">{{ $label }}</span>
            </label>
            @if($hint)
                <small class="text-muted">{{ $hint }}</small>
            @endif
        </div>
        @break

    {{-- ===== TEXT / TEXTAREA / NUMBER ===== --}}
    @case('textarea')
        <div class="single-search-box">
            <div class="d-flex align-items-center gap-2 py-1">
                <label class="input-label">{{ $label }}</label>
            </div>
            <div class="custom-select-dropdown">
                <textarea name="{{ $submitAs }}" id="{{ $elementId }}"
                          placeholder="{{ $placeholder }}"
                          class="@error($submitAs) is-invalid @enderror"
                          {{ $required ? 'required' : '' }}
                          rows="3">{{ $fieldValue }}</textarea>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
        @break

    @case('number')
        <div class="single-search-box">
            <div class="d-flex align-items-center gap-2 py-1">
                <label class="input-label">{{ $label }}</label>
            </div>
            <div class="custom-select-dropdown">
                <input type="number" name="{{ $submitAs }}" id="{{ $elementId }}"
                       placeholder="{{ $placeholder }}"
                       value="{{ $fieldValue }}"
                       class="@error($submitAs) is-invalid @enderror"
                       {{ $required ? 'required' : '' }}
                       min="{{ $field['validation']['min'] ?? '' }}"
                       max="{{ $field['validation']['max'] ?? '' }}">
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
        @if(isset($servicePackages) && $servicePackages->count() > 0)
            <div class="single-search-box package-select-box" id="{{ $elementId }}_wrapper">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">{{ $label }}</label>
                </div>
                <div class="custom-select-dropdown">
                    <select name="{{ $submitAs }}" id="{{ $elementId }}" class="no-nice">
                        <option value="">{{ $placeholder ?: 'Select Package' }}</option>
                        @foreach($servicePackages as $pkg)
                            <option value="{{ $pkg->id }}" {{ $fieldValue == $pkg->id ? 'selected' : '' }}>
                                {{ $pkg->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif
        @break

    {{-- ===== DEFAULT: TEXT INPUT ===== --}}
    @default
        <div class="single-search-box">
            <div class="d-flex align-items-center gap-2 py-1">
                <label class="input-label">{{ $label }}</label>
            </div>
            <div class="custom-select-dropdown">
                <input type="text" name="{{ $submitAs }}" id="{{ $elementId }}"
                       placeholder="{{ $placeholder }}"
                       value="{{ $fieldValue }}"
                       class="@error($submitAs) is-invalid @enderror"
                       {{ $required ? 'required' : '' }}>
            </div>
            @error($submitAs)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
@endswitch
