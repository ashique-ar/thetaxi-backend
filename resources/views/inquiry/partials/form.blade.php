@php
    $formSettings = $form->settings ?? [];
    $phoneInitialCountry = $formSettings['phone_initial_country'] ?? 'lk';
    $phonePreferredCountries = $formSettings['phone_preferred_countries'] ?? ['lk', 'us', 'gb', 'au'];
    if (is_string($phonePreferredCountries)) {
        $phonePreferredCountries = array_filter(array_map('trim', explode(',', $phonePreferredCountries)));
    }
    $formAction = ($servicePage->settings ?? [])['form_action'] ?? 'booking.enquiry';
    $showIntro = $showIntro ?? true;
@endphp

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

<form class="filter-input show" data-inquiry-form
    data-phone-initial-country="{{ $phoneInitialCountry }}"
    data-phone-preferred-countries="{{ implode(',', $phonePreferredCountries) }}"
    action="{{ route($formAction) }}" method="POST">
    @csrf
    @include('inquiry.partials.spam-protection', ['honeypotId' => 'service-inquiry-company-website'])
    <input type="hidden" name="inquiry_service_page_id" value="{{ $servicePage->id }}">
    <input type="hidden" name="service_slug" value="{{ $servicePage->slug }}">
    @if (!empty($servicePage->inquiry_type))
        <input type="hidden" name="inquiry_type" value="{{ $servicePage->inquiry_type }}">
    @endif
    <input type="hidden" name="service_type" value="{{ $servicePage->code }}">

    @if ($showIntro && (!empty($form->name) || !empty($form->description)))
        <div class="inquiry-form-intro">
            @if (!empty($form->name))
                <h3>{{ $form->name }}</h3>
            @endif
            @if (!empty($form->description))
                <p>{{ $form->description }}</p>
            @endif
        </div>
    @endif

    @foreach ($form->fields as $field)
        @php
            $isTextarea = $field->type === 'textarea';
            $isSelect = $field->type === 'select';
            $isRadio = $field->type === 'radio';
            $wrapperClasses = 'inquiry-field-wrap';
            if ($field->width === 'full') {
                $wrapperClasses .= ' inquiry-full-width';
            }
            $conditionalLogic = $field->conditional_logic;
            if (is_array($conditionalLogic) && $conditionalLogic !== [] && array_keys($conditionalLogic) === range(0, count($conditionalLogic) - 1)) {
                $conditionalLogic = $conditionalLogic[0] ?? null;
            }
            if (!is_array($conditionalLogic)) {
                $conditionalLogic = null;
            }
            $isConditionalRequired = false;
            $validationRules = $field->validation_rules ? explode('|', $field->validation_rules) : [];

            foreach ($validationRules as $rule) {
                if (str_starts_with($rule, 'required_if:')) {
                    $parts = explode(':', $rule, 2);
                    $conditions = isset($parts[1]) ? explode(',', $parts[1]) : [];
                    if (count($conditions) >= 2 && (empty($conditionalLogic) || empty($conditionalLogic['field']))) {
                        $conditionalLogic = [
                            'field' => $conditions[0],
                            'operator' => 'equals',
                            'value' => $conditions[1],
                        ];
                    }
                    $isConditionalRequired = true;
                }
            }

            if ($field->is_required) {
                $isConditionalRequired = true;
            }

            $conditional = $conditionalLogic && !empty($conditionalLogic['field']) ? $conditionalLogic : null;
            $shouldShow = true;
            if ($conditional) {
                $conditionField = $conditional['field'];
                $conditionOperator = $conditional['operator'] ?? 'equals';
                $conditionValue = $conditional['value'] ?? null;
                $conditionFieldModel = $form->fields->firstWhere('name', $conditionField);
                $currentValue = old($conditionField, $conditionFieldModel?->default_value);

                $conditionValues = is_array($conditionValue) ? $conditionValue : [$conditionValue];
                $currentValues = is_array($currentValue) ? $currentValue : [$currentValue];
                $conditionValues = array_map('strval', $conditionValues);
                $currentValues = array_map('strval', $currentValues);
                $matches = count(array_intersect($currentValues, $conditionValues)) > 0;

                $shouldShow = $conditionOperator === 'not_equals' ? !$matches : $matches;
            }
            $inputId = 'field-' . $field->id;
            $displayLabel = $field->label . ($field->is_required ? ' *' : '');
        @endphp

        <div class="{{ $wrapperClasses }}"
            @if ($conditional) data-conditional='@json($conditional)' @endif
            @if ($conditional && !$shouldShow) style="display:none !important;" @endif>
            <div class="single-search-box">
                @if (!empty($field->icon))
                    <i class="{{ $field->icon }}"></i>
                @endif

                @if ($isTextarea)
                    <textarea id="{{ $inputId }}" name="{{ $field->name }}"
                        placeholder="{{ ($field->placeholder ?? $field->label) . ($field->is_required ? ' *' : '') }}"
                        class="@error($field->name) is-invalid @enderror"
                        rows="4"
                        data-required="{{ $isConditionalRequired ? 'true' : 'false' }}"
                        @if ($field->is_required) required @endif>{{ old($field->name, $field->default_value) }}</textarea>
                @elseif ($isSelect)
                    <select id="{{ $inputId }}" name="{{ $field->name }}"
                        class="form-select @error($field->name) is-invalid @enderror"
                        data-required="{{ $isConditionalRequired ? 'true' : 'false' }}"
                        @if ($field->is_required) required @endif>
                        <option value="">Select {{ $displayLabel }}</option>
                        @foreach ($field->options ?? [] as $option)
                            @php
                                $optionValue = is_array($option) ? ($option['value'] ?? '') : $option;
                                $optionLabel = is_array($option) ? ($option['label'] ?? $optionValue) : $option;
                            @endphp
                            <option value="{{ $optionValue }}"
                                {{ old($field->name, $field->default_value) == $optionValue ? 'selected' : '' }}>
                                {{ $optionLabel }}
                            </option>
                        @endforeach
                    </select>
                @elseif ($isRadio)
                    <div class="inquiry-radio-group">
                        @foreach ($field->options ?? [] as $index => $option)
                            @php
                                $optionValue = is_array($option) ? ($option['value'] ?? '') : $option;
                                $optionLabel = is_array($option) ? ($option['label'] ?? $optionValue) : $option;
                                $radioId = $inputId . '-' . $index;
                            @endphp
                            <label for="{{ $radioId }}" class="inquiry-radio-option">
                                <input id="{{ $radioId }}" type="radio" name="{{ $field->name }}"
                                    value="{{ $optionValue }}"
                                    data-required="{{ $isConditionalRequired ? 'true' : 'false' }}"
                                    @if (old($field->name, $field->default_value) == $optionValue) checked @endif
                                    @if ($field->is_required) required @endif>
                                <span>{{ $optionLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <input id="{{ $inputId }}" type="{{ $field->type }}" name="{{ $field->name }}"
                        placeholder="{{ ($field->placeholder ?? $field->label) . ($field->is_required ? ' *' : '') }}"
                        class="@error($field->name) is-invalid @enderror"
                        value="{{ old($field->name, $field->default_value) }}"
                        data-required="{{ $isConditionalRequired ? 'true' : 'false' }}"
                        @if ($field->is_required) required @endif autocomplete="off">
                @endif
            </div>

            @error($field->name)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
            @if (!empty($field->help_text))
                <small class="inquiry-help-text">{{ $field->help_text }}</small>
            @endif
        </div>
    @endforeach

    <button type="submit" class="primary-btn1 inquiry-submit-btn">
        <span>{{ $form->submit_label ?? 'Submit Inquiry' }}</span>
    </button>
</form>
