@php
    $formSettings = $form->settings ?? [];
    $phoneInitialCountry = $formSettings['phone_initial_country'] ?? 'lk';
    $phonePreferredCountries = $formSettings['phone_preferred_countries'] ?? ['lk', 'us', 'gb', 'au'];
    if (is_string($phonePreferredCountries)) {
        $phonePreferredCountries = array_filter(array_map('trim', explode(',', $phonePreferredCountries)));
    }
    $formAction = ($servicePage->settings ?? [])['form_action'] ?? 'booking.enquiry';
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
    <input type="hidden" name="inquiry_service_page_id" value="{{ $servicePage->id }}">
    <input type="hidden" name="service_slug" value="{{ $servicePage->slug }}">
    @if (!empty($servicePage->inquiry_type))
        <input type="hidden" name="inquiry_type" value="{{ $servicePage->inquiry_type }}">
    @endif
    <input type="hidden" name="service_type" value="{{ $servicePage->code }}">

    @foreach ($form->fields as $field)
        @php
            $isTextarea = $field->type === 'textarea';
            $isSelect = $field->type === 'select';
            $isRadio = $field->type === 'radio';
            $wrapperClasses = 'single-search-box';
            if ($field->width === 'full') {
                $wrapperClasses .= ' inquiry-full-width';
            }
            $conditional = $field->conditional_logic ? e(json_encode($field->conditional_logic)) : null;
            $inputId = 'field-' . $field->id;
        @endphp

        <div class="{{ $wrapperClasses }}" @if ($conditional) data-conditional="{{ $conditional }}" @endif>
            @if (!empty($field->icon))
                <i class="{{ $field->icon }}"></i>
            @endif

            @if ($isTextarea)
                <textarea id="{{ $inputId }}" name="{{ $field->name }}"
                    placeholder="{{ $field->placeholder ?? $field->label }}"
                    class="@error($field->name) is-invalid @enderror"
                    rows="4"
                    data-required="{{ $field->is_required ? 'true' : 'false' }}"
                    @if ($field->is_required) required @endif>{{ old($field->name, $field->default_value) }}</textarea>
            @elseif ($isSelect)
                <select id="{{ $inputId }}" name="{{ $field->name }}"
                    class="form-select @error($field->name) is-invalid @enderror"
                    data-required="{{ $field->is_required ? 'true' : 'false' }}"
                    @if ($field->is_required) required @endif>
                    <option value="">Select {{ $field->label }}</option>
                    @foreach ($field->options ?? [] as $option)
                        <option value="{{ $option['value'] ?? '' }}"
                            {{ old($field->name, $field->default_value) == ($option['value'] ?? '') ? 'selected' : '' }}>
                            {{ $option['label'] ?? $option['value'] ?? '' }}
                        </option>
                    @endforeach
                </select>
            @elseif ($isRadio)
                <div class="inquiry-radio-group">
                    @foreach ($field->options ?? [] as $index => $option)
                        @php
                            $optionValue = $option['value'] ?? '';
                            $optionLabel = $option['label'] ?? $optionValue;
                            $radioId = $inputId . '-' . $index;
                        @endphp
                        <label for="{{ $radioId }}" class="inquiry-radio-option">
                            <input id="{{ $radioId }}" type="radio" name="{{ $field->name }}"
                                value="{{ $optionValue }}"
                                data-required="{{ $field->is_required ? 'true' : 'false' }}"
                                @if (old($field->name, $field->default_value) == $optionValue) checked @endif
                                @if ($field->is_required) required @endif>
                            <span>{{ $optionLabel }}</span>
                        </label>
                    @endforeach
                </div>
            @else
                <input id="{{ $inputId }}" type="{{ $field->type }}" name="{{ $field->name }}"
                    placeholder="{{ $field->placeholder ?? $field->label }}"
                    class="@error($field->name) is-invalid @enderror"
                    value="{{ old($field->name, $field->default_value) }}"
                    data-required="{{ $field->is_required ? 'true' : 'false' }}"
                    @if ($field->is_required) required @endif autocomplete="off">
            @endif

            @error($field->name)
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
    @endforeach

    <button type="submit" class="primary-btn1 inquiry-submit-btn">
        <span>{{ $form->submit_label ?? 'Submit Inquiry' }}</span>
    </button>
</form>
