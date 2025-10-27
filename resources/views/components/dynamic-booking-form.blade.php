{{-- Dynamic Booking Form Component --}}
@push('styles')
    <style>
        .conditional-field.hidden {
            display: none !important;
        }

        .service-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .transfer-type-selector {
            margin-bottom: 20px;
        }

        .transfer-type-toggle {
            display: flex;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: #f8f9fa;
        }

        .transfer-type-option {
            flex: 1;
            margin: 0;
            position: relative;
        }

        .transfer-type-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .transfer-type-option span {
            display: block;
            padding: 12px 20px;
            text-align: center;
            background: #f8f9fa;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .transfer-type-option input[type="radio"]:checked+span {
            background: #007bff;
            color: white;
        }

        .dynamic-form-field {
            margin-bottom: 1rem;
        }

        .feature-badge {
            display: inline-block;
            background: #e9ecef;
            padding: 4px 8px;
            border-radius: 4px;
            margin: 2px;
            font-size: 0.875rem;
        }

        /* Flexible Field Layout System */
        .dynamic-form-field {
            margin-bottom: 1rem;
        }

        .dynamic-form-field.field-center {
            display: flex;
            justify-content: center;
        }

        .dynamic-form-field.field-full-width .single-search-box {
            width: 100% !important;
        }

        .dynamic-form-field.field-half-width .single-search-box {
            width: 50%;
        }

        .dynamic-form-field.field-third-width .single-search-box {
            width: 33.33%;
        }

        /* Custom Tour Styles */
        .custom-tour-container {
            position: relative;
        }

        .destinations-map {
            height: 300px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-top: 20px;
        }

        .location-connection-line {
            stroke: #007bff;
            stroke-width: 2;
            stroke-dasharray: 5,5;
        }

        .distance-label {
            background: white;
            padding: 2px 6px;
            border: 1px solid #007bff;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>

    <!-- CSS Styles -->
    <style>
        /* Service Type Selector */
        .service-type-selector .filter-item-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .service-type-selector .single-item {
            flex: 1;
            min-width: 200px;
            padding: 15px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .service-type-selector .single-item:hover {
            border-color: var(--primary-color);
            background: #fff;
        }

        .service-type-selector .single-item.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        .service-type-selector .single-item svg {
            width: 24px;
            height: 24px;
            margin-bottom: 8px;
            fill: currentColor;
        }

        .service-type-selector .single-item span {
            display: block;
            font-weight: 600;
            font-size: 14px;
        }

        /* Form Fields */
        .dynamic-form-field {
            margin-bottom: 1rem;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #495057;
        }

        .form-label i {
            margin-right: 5px;
            color: var(--primary-color);
        }

        /* Service Features */
        .service-features-info {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #dee2e6;
        }

        .features-header h6 {
            margin: 0;
            color: #495057;
            font-weight: 700;
        }

        .features-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .feature-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            background: var(--primary-color);
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .feature-badge i {
            font-size: 10px;
        }

        /* Loading State */
        #form-loading {
            background: #f8f9fa;
            border-radius: 12px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .service-type-selector .single-item {
                min-width: 150px;
                padding: 12px;
            }

            .service-type-selector .single-item span {
                font-size: 12px;
            }
        }
    </style>
@endpush

<div class="filter-wrapper" id="dynamic-booking-form">
    <!-- Service Type Selector -->
    <div class="service-type-selector mb-4">
        <ul class="filter-item-list" id="service-tabs">
            <!-- Service tabs will be dynamically populated -->
        </ul>
    </div>

    <!-- Validation Errors Display -->
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

    <!-- Success Message Display -->
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- Dynamic Form Container -->
    <div class="filter-input-wrap">
        <form id="dynamic-booking-form-main" action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" id="selected-service-type" value="">

            <!-- Loading State -->
            <div id="form-loading" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading service configuration...</p>
            </div>

            <!-- Dynamic Form Fields Container -->
            <div id="dynamic-form-fields" style="display: none;">
                <!-- Common Fields (Always Present) -->
                <div class="row g-3 common-fields">
                    <!-- Pickup Location -->
                    <div class="col-md-6">
                        <div class="single-search-box from-location location-search-box">
                            <label class="form-label">
                                <i class="bi bi-geo-alt"></i> Pickup Location <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="pickup_location" id="pickup_location"
                                class="form-control location-search @error('pickup_location') is-invalid @enderror"
                                placeholder="Enter pickup location" value="{{ old('pickup_location') }}" required>
                            @error('pickup_location')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- Dropoff Location (Conditional) -->
                    <div class="col-md-6" id="dropoff-location-container">
                        <div class="single-search-box to-location location-search-box">
                            <label class="form-label">
                                <i class="bi bi-geo-alt-fill"></i> Dropoff Location
                            </label>
                            <input type="text" name="dropoff_location" id="dropoff_location"
                                class="form-control location-search @error('dropoff_location') is-invalid @enderror"
                                placeholder="Enter dropoff location" value="{{ old('dropoff_location') }}">
                            @error('dropoff_location')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- From Date -->
                    <div class="col-md-6">
                        <div class="single-search-box">
                            <label class="form-label">
                                <i class="bi bi-calendar"></i> From Date <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="from_date" id="from_date"
                                class="form-control datepicker @error('from_date') is-invalid @enderror"
                                placeholder="DD/MM/YYYY" value="{{ old('from_date') }}" required readonly>
                            @error('from_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- To Date (Conditional) -->
                    <div class="col-md-6" id="to-date-container">
                        <div class="single-search-box">
                            <label class="form-label">
                                <i class="bi bi-calendar-check"></i> To Date
                            </label>
                            <input type="text" name="to_date" id="to_date"
                                class="form-control datepicker @error('to_date') is-invalid @enderror"
                                placeholder="DD/MM/YYYY" value="{{ old('to_date') }}" readonly>
                            @error('to_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- From Time -->
                    <div class="col-md-3">
                        <div class="single-search-box">
                            <label class="form-label">
                                <i class="bi bi-clock"></i> From Time
                            </label>
                            <input type="time" name="from_time" id="from_time"
                                class="form-control @error('from_time') is-invalid @enderror"
                                value="{{ old('from_time', '09:00') }}">
                            @error('from_time')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- To Time (Conditional) -->
                    <div class="col-md-3" id="to-time-container">
                        <div class="single-search-box">
                            <label class="form-label">
                                <i class="bi bi-clock-fill"></i> To Time
                            </label>
                            <input type="time" name="to_time" id="to_time"
                                class="form-control @error('to_time') is-invalid @enderror"
                                value="{{ old('to_time') }}">
                            @error('to_time')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- Passengers -->
                    <div class="col-md-3">
                        <div class="single-search-box">
                            <label class="form-label">
                                <i class="bi bi-people"></i> Passengers <span class="text-danger">*</span>
                            </label>
                            <select name="passengers" id="passengers"
                                class="form-select @error('passengers') is-invalid @enderror" required>
                                <option value="">Select</option>
                                @for ($i = 1; $i <= 20; $i++)
                                    <option value="{{ $i }}"
                                        {{ old('passengers') == $i ? 'selected' : '' }}>
                                        {{ $i }} {{ $i == 1 ? 'Person' : 'People' }}
                                    </option>
                                @endfor
                            </select>
                            @error('passengers')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- Luggage -->
                    <div class="col-md-3">
                        <div class="single-search-box">
                            <label class="form-label">
                                <i class="bi bi-luggage"></i> Luggage
                            </label>
                            <select name="luggage" id="luggage"
                                class="form-select @error('luggage') is-invalid @enderror">
                                <option value="">None</option>
                                @for ($i = 1; $i <= 10; $i++)
                                    <option value="{{ $i }}" {{ old('luggage') == $i ? 'selected' : '' }}>
                                        {{ $i }} {{ $i == 1 ? 'Bag' : 'Bags' }}
                                    </option>
                                @endfor
                            </select>
                            @error('luggage')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Service-Specific Fields Container -->
                <div id="service-specific-fields" class="row g-3 mt-3">
                    <!-- Dynamic fields will be inserted here -->
                </div>

                <!-- Submit Button -->
                <div class="search-btn-wrap mt-4">
                    <button type="submit" id="submit-button" class="primary-btn1 search-btn">
                        <span><i class="bi bi-search"></i> Find Vehicles</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Service Features Display -->
<div id="service-features-display" class="service-features-info mt-4" style="display: none;">
    <div class="features-header">
        <h6><i class="bi bi-star"></i> Service Features</h6>
    </div>
    <div class="features-list" id="features-list">
        <!-- Features will be populated dynamically -->
    </div>
</div>

@push('scripts')
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>


    <!-- JavaScript for Dynamic Form -->
    <script>
        // Ensure jQuery is loaded before initializing
        (function() {
            function initWhenReady() {
                if (typeof jQuery !== 'undefined') {
                    initializeDynamicBookingForm();
                } else {
                    // Try again after a short delay
                    setTimeout(initWhenReady, 100);
                }
            }

            // Wait for DOM to be ready, then check for jQuery
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initWhenReady);
            } else {
                initWhenReady();
            }
        })();

        function initializeDynamicBookingForm() {
            const $ = jQuery; // Alias for safety

            let serviceConfig = null;
            let currentServiceCode = null;

            // Initialize the dynamic form
            initializeDynamicForm();

            // Call after form is rendered
            $(document).on('form-rendered', function() {
                initializeAllFeatures();
            });

            // Initialize datepickers when form is ready
            $(document).on('focus', '.datepicker', function() {
                if (!$(this).hasClass('datepicker-initialized')) {
                    $(this).addClass('datepicker-initialized');
                    $(this).datepicker({
                        format: 'dd/mm/yyyy',
                        autoclose: true,
                        todayHighlight: true,
                        startDate: new Date(),
                        orientation: 'bottom auto'
                    });
                }
            });
        }

        function initializeDynamicForm() {
            // Load service configuration
            loadServiceConfiguration();
        }

        function loadServiceConfiguration() {
            $.ajax({
                url: '{{ route('api.services.configuration') }}',
                method: 'GET',
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    if (response.success) {
                        serviceConfig = response.data;
                        renderServiceTabs();
                        $('#form-loading').hide();
                    } else {
                        showError('Failed to load service configuration: ' + (response.message ||
                            'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading service configuration:', xhr, status, error);
                    let errorMessage = 'Unable to load service types. Please refresh the page.';

                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.status === 404) {
                        errorMessage =
                            'Service configuration endpoint not found. Please check your routes.';
                    } else if (xhr.status === 500) {
                        errorMessage = 'Server error occurred. Please try again later.';
                    }

                    showError(errorMessage);
                    $('#form-loading').hide();
                }
            });
        }

        function renderServiceTabs() {
            const $serviceTabs = $('#service-tabs');
            $serviceTabs.empty();

            let firstCategory = null;

            if (!serviceConfig || !serviceConfig.service_types) {
                showError('Invalid service configuration received');
                return;
            }

            // Render CATEGORY tabs (not individual services)
            Object.keys(serviceConfig.service_types).forEach((categoryKey, categoryIndex) => {
                const category = serviceConfig.service_types[categoryKey];

                if (!category || !category.services || category.services.length === 0) {
                    return;
                }

                if (!firstCategory) {
                    firstCategory = categoryKey;
                }

                const iconClass = category.category_info && category.category_info.icon ?
                    `bi-${category.category_info.icon}` :
                    'bi-gear';

                // Create SVG icon based on category
                let svgIcon = '';
                switch (categoryKey) {
                    case 'airport-transfer':
                        svgIcon = `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
                    </svg>`;
                        break;
                    case 'drop-pickup':
                        svgIcon = `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                    </svg>`;
                        break;
                    case 'rental-packages':
                        svgIcon = `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17 5h-2v2h2v2h2V7h2V5h-2V3h-2v2zm-2 4V7H9.01L3 13.01V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6h-6zM5 19v-4.99l4-4 4 4L9 18H5zm14 0h-6v-4l-2-2-4 4v2h12z"/>
                    </svg>`;
                        break;
                    case 'custom-tour':
                        svgIcon = `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2zm0 2.5l-2.33 4.68-5.15.75 3.73 3.63-.88 5.13L12 16.77l4.63 2.42-.88-5.13 3.73-3.63-5.15-.75L12 4.5z"/>
                    </svg>`;
                        break;
                    case 'corporate-transport':
                        svgIcon = `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>
                    </svg>`;
                        break;
                    default:
                        svgIcon = `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>`;
                }

                const $tab = $(`
                <li class="single-item" data-category="${categoryKey}">
                    ${svgIcon}
                    <span>${category.category_info.name}</span>
                </li>
            `);

                $serviceTabs.append($tab);
            });

            // Set up tab click handlers
            $('.single-item').off('click').on('click', function() {
                const categoryKey = $(this).data('category');
                selectCategory(categoryKey);
            });

            // Auto-select first category
            if (firstCategory) {
                selectCategory(firstCategory);
            } else {
                showError('No services available');
            }
        }

        function selectCategory(categoryKey) {
            if (!categoryKey || !serviceConfig.service_types[categoryKey]) {
                showError('Invalid category selected');
                return;
            }

            // Update active tab
            $('.single-item').removeClass('active');
            $(`.single-item[data-category="${categoryKey}"]`).addClass('active');

            const category = serviceConfig.service_types[categoryKey];

            // For categories with single service, load that service directly
            if (category.services.length === 1) {
                const service = category.services[0];
                currentServiceCode = service.code;
                $('#selected-service-type').val(service.code);
                loadServiceFormConfig(service.code);
            } else {
                // For categories with multiple services (like corporate), show first service
                // In future, we can add sub-tabs or dropdowns here
                const service = category.services[0];
                currentServiceCode = service.code;
                $('#selected-service-type').val(service.code);
                loadServiceFormConfig(service.code);
            }
        }


        function selectService(serviceCode) {
            if (!serviceCode) {
                showError('Invalid service selected');
                return;
            }

            // Update active tab
            $('.single-item').removeClass('active');
            $(`.single-item[data-service="${serviceCode}"]`).addClass('active');

            // Update form
            currentServiceCode = serviceCode;
            $('#selected-service-type').val(serviceCode);

            // Load service-specific form configuration
            loadServiceFormConfig(serviceCode);
        }

        function loadServiceFormConfig(serviceCode) {
            $.ajax({
                url: `{{ url('/api/services') }}/${serviceCode}/form-config`,
                method: 'GET',
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    if (response.success) {
                        renderServiceForm(response.data);
                        showServiceFeatures(response.data.supported_features || []);
                    } else {
                        showError('Failed to load form configuration for this service: ' + (response
                            .message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading service form config:', xhr, status, error);
                    let errorMessage = 'Unable to load form for this service type';

                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.status === 404) {
                        errorMessage = 'Service form configuration not found';
                    }

                    showError(errorMessage);
                }
            });
        }

    function renderServiceForm(config) {
        const $specificFields = $('#service-specific-fields');
        $specificFields.empty();
        
        // **IMPORTANT**: Hide ALL common fields - old form doesn't use them!
        // Each service has its own specific field names and structure
        $('.common-fields').hide();

        // Update form action if specified
        if (config.form_action) {
            const actionUrl = config.form_action === 'booking.search' ?
                '{{ route('booking.search') }}' :
                '{{ route('booking.enquiry') }}';
            $('#dynamic-booking-form-main').attr('action', actionUrl);
        }

        // Update submit button text if specified
        if (config.button_text) {
            $('#submit-button span').text(config.button_text);
        }

        // Render ALL fields as service-specific (no common fields logic)
        if (config.base_fields) {
            // Render special fields
            if (config.base_fields.special_fields) {
                Object.keys(config.base_fields.special_fields).forEach(fieldName => {
                    const fieldConfig = config.base_fields.special_fields[fieldName];
                    const $field = renderDynamicField(fieldName, fieldConfig);
                    // Only append if field was rendered (skip null for custom types)
                    if ($field) {
                        $specificFields.append($field);
                    }
                });
            }
        }

        // Show the form
        $('#dynamic-form-fields').show();

        // Set up conditional field handlers
        setupConditionalFields();

        // Trigger form rendered event
        $(document).trigger('form-rendered');

        // Trigger service config loaded event
        $(document).trigger('service-config-loaded', [currentServiceCode]);

        // Apply default values for this service
        applyDefaultValues(currentServiceCode);

        // Initialize all features after form is rendered
        setTimeout(initializeAllFeatures, 300);
    }        function setupConditionalFields() {
            // Handle conditional field display
            $(document).off('change', '[data-conditional]').on('change', '[data-conditional]', function() {
                const targetFieldName = $(this).data('conditional');
                const isChecked = $(this).is(':checked');

                // Show/hide fields that depend on this field
                $(`.conditional-field[data-conditional="${targetFieldName}"]`).toggle(isChecked);

                // Clear values when hiding conditional fields
                if (!isChecked) {
                    $(`.conditional-field[data-conditional="${targetFieldName}"] input, .conditional-field[data-conditional="${targetFieldName}"] select, .conditional-field[data-conditional="${targetFieldName}"] textarea`)
                        .val('');
                }
            });

            // Handle checkbox dependencies
            $(document).off('change', 'input[type="checkbox"][data-conditional]').on('change',
                'input[type="checkbox"][data-conditional]',
                function() {
                    const conditionalField = $(this).data('conditional');
                    const isChecked = $(this).is(':checked');

                    // Find fields that depend on this checkbox
                    $(`.conditional-field[data-conditional="${conditionalField}"]`).each(function() {
                        if (isChecked) {
                            $(this).removeClass('hidden').show();
                        } else {
                            $(this).addClass('hidden').hide();
                            // Clear field values
                            $(this).find('input, select, textarea').val('');
                        }
                });
            });
    }

    function renderDynamicField(fieldName, fieldConfig) {
        // Flexible layout system
        let colClass = 'col-md-6'; // Default: 2 fields per line
        
        // Layout configuration from field config
        if (fieldConfig.layout) {
            switch (fieldConfig.layout.width) {
                case 'full':
                    colClass = 'col-12';
                    break;
                case 'half':
                    colClass = 'col-md-6';
                    break;
                case 'third':
                    colClass = 'col-md-4';
                    break;
                case 'quarter':
                    colClass = 'col-md-3';
                    break;
                default:
                    colClass = 'col-md-6';
            }
            
            // Add alignment classes
            if (fieldConfig.layout.align === 'center') {
                colClass += ' d-flex justify-content-center';
            } else if (fieldConfig.layout.align === 'right') {
                colClass += ' text-end';
            }
        } else if (fieldConfig.type === 'textarea') {
            colClass = 'col-12'; // Textarea always full width by default
        }
        
        const required = fieldConfig.required ? 'required' : '';
        const requiredStar = fieldConfig.required ? '<span class="text-danger">*</span>' : '';

        let inputHtml = '';

            switch (fieldConfig.type) {
                case 'location':
                    // Location field with Google Maps autocomplete + lat/lng hidden fields
                    // Add airport filtering for airport transfer services
                    let locationClasses = 'form-control location-search';
                    let locationData = '';
                    
                    // Airport filtering based on current service and field name
                    if (currentServiceCode && (currentServiceCode.includes('airport'))) {
                        if (fieldName === 'from' || fieldName === 'pickup') {
                            locationClasses += ' airport-pickup-search';
                            locationData = 'data-place-type="airport"';
                        } else if (fieldName === 'to' || fieldName === 'dropoff') {
                            locationClasses += ' airport-destination-search';
                            locationData = 'data-place-type="airport"';
                        }
                    }
                    
                    inputHtml = `
                    <input type="text" 
                           name="${fieldName}" 
                           id="${fieldName}"
                           class="${locationClasses}"
                           placeholder="${fieldConfig.placeholder || 'Enter location'}" 
                           ${locationData}
                           ${required}>
                    <input type="hidden" name="${fieldName}_lat" id="${fieldName}_lat">
                    <input type="hidden" name="${fieldName}_lng" id="${fieldName}_lng">
                `;
                    break;
                    
                case 'text':
                case 'email':
                case 'tel':
                    inputHtml = `
                    <input type="${fieldConfig.type}" 
                           name="${fieldName}" 
                           id="${fieldName}"
                           class="form-control"
                           placeholder="${fieldConfig.placeholder || ''}" 
                           ${required}>
                `;
                    break;

                case 'date':
                    // Get default date values
                    let defaultDate = '';
                    if (fieldName.includes('pickup_date') || fieldName === 'date') {
                        // Default to today
                        const today = new Date();
                        defaultDate = today.toLocaleDateString('en-GB');
                    } else if (fieldName.includes('to_date') || fieldName.includes('end_date')) {
                        // Default to 3 days from today
                        const futureDate = new Date();
                        futureDate.setDate(futureDate.getDate() + 3);
                        defaultDate = futureDate.toLocaleDateString('en-GB');
                    }
                    
                    inputHtml = `
                    <input type="text" 
                           name="${fieldName}" 
                           id="${fieldName}"
                           class="form-control dynamic-datepicker"
                           placeholder="${fieldConfig.placeholder || 'DD/MM/YYYY'}"
                           value="${defaultDate}"
                           data-date-format="dd/mm/yyyy"
                           data-date-autoclose="true"
                           data-date-today-highlight="true"
                           data-date-start-date="0d"
                           autocomplete="off"
                           readonly
                           ${required}>
                `;
                    break;

                case 'time':
                    inputHtml = `
                    <input type="time" 
                           name="${fieldName}" 
                           id="${fieldName}"
                           class="form-control"
                           ${required}>
                `;
                    break;

                case 'number':
                    inputHtml = `
                    <input type="number" 
                           name="${fieldName}" 
                           id="${fieldName}"
                           class="form-control"
                           min="${fieldConfig.min || 0}"
                           max="${fieldConfig.max || ''}"
                           ${required}>
                `;
                    break;

                case 'select':
                    let optionsHtml = '';
                    if (fieldConfig.options) {
                        if (typeof fieldConfig.options === 'object' && !Array.isArray(fieldConfig.options)) {
                            // Handle object format: {value: label}
                            optionsHtml = Object.entries(fieldConfig.options)
                                .map(([value, label]) => `<option value="${value}">${label}</option>`)
                                .join('');
                        } else {
                            // Handle array format
                            optionsHtml = fieldConfig.options
                                .map(option => `<option value="${option}">${option}</option>`)
                                .join('');
                        }
                    }
                    inputHtml = `
                    <select name="${fieldName}" 
                            id="${fieldName}"
                            class="form-select" 
                            ${required}>
                        <option value="">Select ${fieldConfig.label}</option>
                        ${optionsHtml}
                    </select>
                `;
                    break;

                case 'radio':
                    let radioOptionsHtml = '';
                    if (fieldConfig.options && typeof fieldConfig.options === 'object') {
                        radioOptionsHtml = Object.entries(fieldConfig.options)
                            .map(([value, label]) => `
                            <div class="form-check form-check-inline">
                                <input type="radio" 
                                       name="${fieldName}" 
                                       id="${fieldName}_${value}"
                                       class="form-check-input"
                                       value="${value}"
                                       ${fieldConfig.default === value ? 'checked' : ''}
                                       ${required}>
                                <label class="form-check-label" for="${fieldName}_${value}">
                                    ${label}
                                </label>
                            </div>
                        `).join('');
                    }
                    inputHtml = radioOptionsHtml;
                    break;

                case 'checkbox':
                    inputHtml = `
                    <div class="form-check">
                        <input type="checkbox" 
                               name="${fieldName}" 
                               id="${fieldName}"
                               class="form-check-input"
                               value="${fieldConfig.value || '1'}"
                               ${fieldConfig.conditional ? `data-conditional="${fieldConfig.conditional}"` : ''}>
                        <label class="form-check-label" for="${fieldName}">
                            ${fieldConfig.label}
                        </label>
                    </div>
                `;
                    break;

                case 'textarea':
                    inputHtml = `
                    <textarea name="${fieldName}" 
                              id="${fieldName}"
                              class="form-control"
                              rows="${fieldConfig.rows || 3}"
                              placeholder="${fieldConfig.placeholder || ''}"
                              ${required}></textarea>
                `;
                    break;

                case 'file':
                    inputHtml = `
                    <input type="file" 
                           name="${fieldName}" 
                           id="${fieldName}"
                           class="form-control"
                           accept="${fieldConfig.accept || ''}"
                           ${required}>
                `;
                    break;

                case 'custom':
                    // Custom field type - skip rendering (requires special template handling)
                    // Used for complex fields like multi-destination in custom tour
                    return null;

                default:
                    inputHtml = `
                    <input type="text" 
                           name="${fieldName}" 
                           id="${fieldName}"
                           class="form-control"
                           ${required}>
                `;
            }

            const conditionalClass = fieldConfig.conditional ? `conditional-field hidden` : '';
            const conditionalData = fieldConfig.conditional ? `data-conditional="${fieldConfig.conditional}"` :
                '';

            return $(`
            <div class="${colClass} ${conditionalClass}" ${conditionalData}>
                <div class="single-search-box dynamic-form-field">
                    ${fieldConfig.type !== 'checkbox' && fieldConfig.type !== 'radio' ? `
                                <label class="form-label">
                                    <i class="bi bi-gear"></i> ${fieldConfig.label} ${requiredStar}
                                </label>
                            ` : ''}
                    ${inputHtml}
                </div>
            </div>
        `);
        }

        function showServiceFeatures(features) {
            const $featuresDisplay = $('#service-features-display');
            const $featuresList = $('#features-list');

            if (features && features.length > 0) {
                $featuresList.empty();
                features.forEach(feature => {
                    $featuresList.append(`
                    <span class="feature-badge">
                        <i class="bi bi-check-circle"></i> ${feature}
                    </span>
                `);
                });
                $featuresDisplay.show();
            } else {
                $featuresDisplay.hide();
            }
        }

        function showError(message) {
            // Remove existing alerts first
            $('#dynamic-booking-form .alert').remove();

            // Show error message to user
            const $errorAlert = $(`
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Error:</strong> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `);
            $('#dynamic-booking-form').prepend($errorAlert);
        }

        function showSuccess(message) {
            // Remove existing alerts first
            $('#dynamic-booking-form .alert').remove();

            // Show success message to user
            const $successAlert = $(`
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Success:</strong> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `);
            $('#dynamic-booking-form').prepend($successAlert);

            // Auto-dismiss after 3 seconds
            setTimeout(() => {
                $successAlert.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }

        // ===================================================================
        // FEATURE 1: Airport Transfer - Transfer Type & Location Restrictions
        // ===================================================================
        function setupAirportTransferRestrictions() {
            // Handle transfer type change
            $(document).on('change', 'input[name="transfer_type"]', function() {
                const transferType = $(this).val();
                updateAirportLocationRestrictions(transferType);
            });

            // Initial setup if transfer_type exists
            const initialTransferType = $('input[name="transfer_type"]:checked').val();
            if (initialTransferType) {
                updateAirportLocationRestrictions(initialTransferType);
            }
        }

        function updateAirportLocationRestrictions(transferType) {
            const fromField = $('input[name="from"], input[name="pickup_location"]');
            const toField = $('input[name="to"], input[name="dropoff_location"]');

            // Clear existing autocomplete
            if (fromField[0] && fromField[0].googleAutocomplete) {
                google.maps.event.clearInstanceListeners(fromField[0]);
            }
            if (toField[0] && toField[0].googleAutocomplete) {
                google.maps.event.clearInstanceListeners(toField[0]);
            }

            if (transferType === 'from-airport') {
                // FROM: Airport only
                initializeAirportOnlyAutocomplete(fromField[0]);
                // TO: Any location
                initializeRegularAutocomplete(toField[0]);

                // Update placeholders
                fromField.attr('placeholder', 'Select airport (Colombo BIA, Mattala, Jaffna)');
                toField.attr('placeholder', 'Enter destination location');
            } else {
                // FROM: Any location
                initializeRegularAutocomplete(fromField[0]);
                // TO: Airport only
                initializeAirportOnlyAutocomplete(toField[0]);

                // Update placeholders
                fromField.attr('placeholder', 'Enter pickup location');
                toField.attr('placeholder', 'Select airport (Colombo BIA, Mattala, Jaffna)');
            }
        }

        function initializeAirportOnlyAutocomplete(input) {
            if (!input || !window.google || !window.google.maps) return;

            const autocomplete = new google.maps.places.Autocomplete(input, {
                componentRestrictions: {
                    country: 'lk'
                },
                types: ['airport'], // Restrict to airports only
                fields: ['place_id', 'geometry', 'name', 'formatted_address']
            });

            autocomplete.addListener('place_changed', function() {
                const place = autocomplete.getPlace();
                if (place.geometry) {
                    $(input).data('lat', place.geometry.location.lat());
                    $(input).data('lng', place.geometry.location.lng());
                }
            });

            input.googleAutocomplete = autocomplete;
        }

        function initializeRegularAutocomplete(input) {
            if (!input || !window.google || !window.google.maps) return;

            const autocomplete = new google.maps.places.Autocomplete(input, {
                componentRestrictions: {
                    country: 'lk'
                },
                fields: ['place_id', 'geometry', 'name', 'formatted_address']
            });

            autocomplete.addListener('place_changed', function() {
                const place = autocomplete.getPlace();
                if (place.geometry) {
                    $(input).data('lat', place.geometry.location.lat());
                    $(input).data('lng', place.geometry.location.lng());
                }
            });

            input.googleAutocomplete = autocomplete;
        }

        // ===================================================================
        // FEATURE 2: Return Transfer Toggle
        // ===================================================================
        function setupReturnTransferToggle() {
            $(document).on('change', 'input[name="need_return"]', function() {
                const isChecked = $(this).is(':checked');
                toggleReturnFields(isChecked);
            });
        }

        function toggleReturnFields(show) {
            const returnFields = $('.conditional-field[data-conditional="need_return"]');

            if (show) {
                returnFields.removeClass('hidden').show();
                // Make return fields required
                returnFields.find('input, textarea, select').each(function() {
                    if ($(this).data('required-if')) {
                        $(this).prop('required', true);
                    }
                });
            } else {
                returnFields.addClass('hidden').hide();
                // Clear and remove required
                returnFields.find('input, textarea, select').val('').prop('required', false);
            }
        }

        // ===================================================================
        // FEATURE 3: Date & Time Validations
        // ===================================================================
        function setupDateTimeValidations() {
            // Initialize Bootstrap Datepicker
            $('.datepicker').each(function() {
                if (!$(this).data('datepicker-initialized')) {
                    $(this).datepicker({
                        format: 'dd/mm/yyyy',
                        autoclose: true,
                        todayHighlight: true,
                        startDate: new Date(),
                        orientation: 'bottom auto'
                    }).data('datepicker-initialized', true);
                }
            });

            // Date validation on blur
            $(document).on('blur', '.datepicker', function() {
                validateDateField($(this));
            });

            // Validate return date is after pickup date
            $(document).on('change', 'input[name="return_date"], input[name="to_date"]', function() {
                validateReturnDate();
            });
        }

        function validateDateField($field) {
            const dateValue = $field.val();

            if (!dateValue) return true;

            // Validate DD/MM/YYYY format
            const regex = /^(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[012])\/\d{4}$/;
            if (!regex.test(dateValue)) {
                showFieldError($field, 'Please enter date in DD/MM/YYYY format');
                return false;
            }

            // Parse date
            const parts = dateValue.split('/');
            const day = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10) - 1;
            const year = parseInt(parts[2], 10);
            const inputDate = new Date(year, month, day);

            // Check if past date
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            if (inputDate < today) {
                showFieldError($field, 'Cannot select past dates');
                return false;
            }

            clearFieldError($field);
            return true;
        }

        function validateReturnDate() {
            const fromDateVal = $('input[name="from_date"], input[name="date"]').val();
            const toDateVal = $('input[name="to_date"], input[name="return_date"]').val();

            if (!fromDateVal || !toDateVal) return true;

            const fromDate = parseDate(fromDateVal);
            const toDate = parseDate(toDateVal);

            if (toDate <= fromDate) {
                const $toField = $('input[name="to_date"], input[name="return_date"]');
                showFieldError($toField, 'Return/End date must be after start date');
                return false;
            }

            return true;
        }

        function parseDate(dateString) {
            const parts = dateString.split('/');
            const day = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10) - 1;
            const year = parseInt(parts[2], 10);
            return new Date(year, month, day);
        }

        function showFieldError($field, message) {
            $field.addClass('is-invalid');
            $field.next('.invalid-feedback').remove();
            $field.after(`<div class="invalid-feedback" style="display: block;">${message}</div>`);
        }

        function clearFieldError($field) {
            $field.removeClass('is-invalid');
            $field.next('.invalid-feedback').remove();
        }

        // ===================================================================
        // FEATURE 4: Default Values
        // ===================================================================
        function applyDefaultValues(serviceCode) {
            const today = formatDate(new Date());
            const threeDaysLater = formatDate(addDays(new Date(), 3));

            const defaults = {
                'transfers': {
                    'pickup': 'Colombo, Sri Lanka',
                    'dropoff': 'Galle, Sri Lanka',
                    'date': today
                },
                'chauffeur_driven': {
                    'pickup_location': 'Colombo, Sri Lanka',
                    'from_date': today,
                    'to_date': threeDaysLater
                },
                'self_driven': {
                    'pickup_location': 'Colombo, Sri Lanka',
                    'from_date': today,
                    'to_date': threeDaysLater
                },
                'wedding_hire': {
                    'pickup_location': 'Colombo, Sri Lanka',
                    'from_date': threeDaysLater
                },
                'airport_drop': {
                    'date': today
                },
                'airport_pickup': {
                    'date': today
                }
            };

            if (defaults[serviceCode]) {
                Object.keys(defaults[serviceCode]).forEach(fieldName => {
                    const value = defaults[serviceCode][fieldName];
                    const $field = $(`[name="${fieldName}"]`);

                    if ($field.length && !$field.val()) {
                        $field.val(value);

                        // Trigger change for datepickers
                        if ($field.hasClass('datepicker')) {
                            $field.datepicker('update', value);
                        }
                    }
                });
            }
        }

        function formatDate(date) {
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            return `${day}/${month}/${year}`;
        }

        function addDays(date, days) {
            const result = new Date(date);
            result.setDate(result.getDate() + days);
            return result;
        }

        // ===================================================================
        // FEATURE 5: Initialize Google Maps for Location Autocomplete
        // ===================================================================
        function initializeGoogleMapsAutocomplete() {
            // Wait for Google Maps to be loaded
            if (typeof google === 'undefined' || !google.maps) {
                setTimeout(initializeGoogleMapsAutocomplete, 500);
                return;
            }

            // Initialize all location search fields
            $('.location-search').each(function() {
                if (!$(this).data('autocomplete-initialized')) {
                    initializeRegularAutocomplete(this);
                    $(this).data('autocomplete-initialized', true);
                }
            });
        }

        // ===================================================================
        // FEATURE 6: Custom Tour Modal - Drag & Drop with Map
        // ===================================================================
        // Global custom tour data
        let customTourData = {
            map: null,
            markers: [],
            polylines: [],
            destinations: [],
            pickupLocation: null, // Locked pickup location
            isPickupSet: false,
            modalOpen: false // Flag to prevent duplicate modals
        };

        function setupCustomTourModal() {
            // Add custom tour button after form loads for custom_tour service
            $(document).on('service-config-loaded', function(e, serviceCode) {
                if (serviceCode === 'custom_tour') {
                    if ($('#open-custom-tour-modal').length === 0) {
                        const customTourButton = `
                        <div class="mt-3">
                            <button type="button" class="btn btn-outline-primary" id="open-custom-tour-modal">
                                <i class="fas fa-map-marked-alt"></i> Customize Tour Route
                            </button>
                            <input type="hidden" name="custom_destinations" value="">
                        </div>
                    `;
                        $('#service-specific-fields').append(customTourButton);
                    }
                }
            });

            // Modal click handler
            $(document).on('click', '#open-custom-tour-modal', function() {
                if (!customTourData.modalOpen) {
                    openCustomTourModal();
                }
            });
        }

        function openCustomTourModal() {
            // Prevent multiple modals by checking if already open
            if (customTourData.modalOpen || $('#customTourModal').hasClass('show')) {
                return;
            }

            // Set modal open flag
            customTourData.modalOpen = true;

            // Remove any existing modal instances to prevent backdrop issues
            $('#customTourModal').remove();
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css('padding-right', '');

            const modalHtml = `
                <div class="modal fade" id="customTourModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-route"></i> Customize Tour Route
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <!-- Left: Destination List -->
                                    <div class="col-md-5">
                                        <h6 class="mb-3"><i class="fas fa-route"></i> Custom Tour Planning</h6>
                                        
                                        <!-- Pickup Location (Locked) -->
                                        <div class="card mb-3 border-success">
                                            <div class="card-header bg-success text-white py-2">
                                                <small><i class="fas fa-map-marker-alt"></i> Pickup Location & Start Time</small>
                                            </div>
                                            <div class="card-body py-2">
                                                <div class="input-group mb-2">
                                                    <input type="text" id="pickup-location-input" class="form-control" 
                                                           placeholder="Set your pickup location...">
                                                    <button class="btn btn-success" type="button" id="set-pickup-btn">
                                                        <i class="fas fa-map-pin"></i> Set Base
                                                    </button>
                                                </div>
                                                
                                                <!-- Pickup Date and Time -->
                                                <div class="row g-2 mb-2">
                                                    <div class="col-md-6">
                                                        <label class="form-label text-sm">Pickup Date</label>
                                                        <input type="date" 
                                                               id="pickup-date-input"
                                                               class="form-control form-control-sm"
                                                               min="${new Date().toISOString().split('T')[0]}"
                                                               onchange="updatePickupDate(this.value)">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label text-sm">Pickup Time</label>
                                                        <input type="time" 
                                                               id="pickup-time-input"
                                                               class="form-control form-control-sm"
                                                               onchange="updatePickupTime(this.value)">
                                                    </div>
                                                </div>
                                                
                                                <div id="pickup-location-display" class="mt-2" style="display: none;">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas fa-lock text-success me-2"></i>
                                                        <div>
                                                            <strong id="pickup-name"></strong><br>
                                                            <small class="text-muted" id="pickup-address"></small>
                                                            <div id="pickup-datetime-display" class="text-success small mt-1" style="display: none;">
                                                                <i class="fas fa-calendar"></i> <span id="pickup-date-time-text"></span>
                                                            </div>
                                                        </div>
                                                        <button class="btn btn-sm btn-outline-secondary ms-auto" id="change-pickup-btn">
                                                            <i class="fas fa-edit"></i> Change
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Tour Destinations -->
                                        <h6 class="mb-3"><i class="fas fa-list"></i> Tour Destinations</h6>
                                        <div class="input-group mb-3">
                                            <input type="text" id="new-destination-input" class="form-control" 
                                                   placeholder="Search destination in Sri Lanka..." disabled>
                                            <button class="btn btn-primary" type="button" id="add-destination-btn" disabled>
                                                <i class="fas fa-plus"></i> Add
                                            </button>
                                        </div>
                                        <small class="text-muted mb-3 d-block">
                                            <i class="fas fa-info-circle"></i> Set pickup location first, then add destinations
                                        </small>
                                        
                                        <div id="destinations-list" class="border rounded p-3" 
                                             style="min-height: 300px; max-height: 300px; overflow-y: auto; background: #f8f9fa;">
                                            <p class="text-muted text-center py-4">
                                                <i class="fas fa-route fa-2x mb-2"></i><br>
                                                <strong>Your tour destinations will appear here</strong><br>
                                                <small>Add destinations to build your custom tour route</small>
                                            </p>
                                        </div>
                                        
                                        <div class="mt-3 p-3 bg-light rounded">
                                            <h6 class="mb-3"><i class="fas fa-calculator"></i> Tour Summary</h6>
                                            
                                            <!-- Distance & Travel -->
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-road"></i> Total Distance:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="total-distance" class="badge bg-primary">0 km</span>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-clock"></i> Travel Time:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="total-travel-time" class="badge bg-info">0 hours</span>
                                                </div>
                                            </div>
                                            
                                            <!-- Tour Duration -->
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-calendar-day"></i> Tour Duration:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="tour-duration" class="badge bg-success">0 days</span>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-hourglass-half"></i> Active Time:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="active-time" class="badge bg-warning">0 hours</span>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-pause"></i> Idle Time:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="idle-time" class="badge bg-secondary">0 hours</span>
                                                </div>
                                            </div>
                                            
                                            <!-- Stops & Destinations -->
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-map-marked-alt"></i> Total Stops:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="total-stops" class="badge bg-secondary">0</span>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-calendar-check"></i> Scheduled Days:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="scheduled-days" class="badge bg-primary">0</span>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <div class="col-6">
                                                    <strong><i class="fas fa-calendar-times"></i> Idle Days:</strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <span id="idle-days" class="badge bg-warning">0</span>
                                                </div>
                                            </div>
                                            
                                            <!-- Date Range -->
                                            <div class="mt-3 pt-2 border-top">
                                                <div class="row">
                                                    <div class="col-12">
                                                        <small class="text-muted">
                                                            <strong>Tour Period:</strong> 
                                                            <span id="tour-date-range">Not set</span>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Right: Map -->
                                    <div class="col-md-7">
                                        <h6 class="mb-3"><i class="fas fa-map"></i> Route Preview</h6>
                                        <div id="custom-tour-map" style="height: 500px; border-radius: 8px; border: 2px solid #dee2e6;"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                <button type="button" class="btn btn-success" id="search-custom-tour" disabled>
                                    <i class="fas fa-search"></i> Search Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <style>
                    .destination-item { transition: all 0.3s ease; }
                    .destination-item.dragging { opacity: 0.5; }
                    .destination-item.drag-over { border-top: 3px solid #007bff !important; }
                    .drag-handle { font-size: 18px; }
                    .drag-handle:hover { color: #007bff !important; }
                </style>
            `;
            $('body').append(modalHtml);

            // Load existing destinations
            loadExistingDestinations();

            // Initialize map (delayed for modal animation)
            setTimeout(() => {
                initializeCustomTourMap();
                setupDestinationAutocomplete();
            }, 300);

            // Setup handlers
            setupCustomTourHandlers();

            // Show modal with proper cleanup on hide
            const modalElement = document.getElementById('customTourModal');
            const modal = new bootstrap.Modal(modalElement);
            
            // Clean up on modal hide
            modalElement.addEventListener('hidden.bs.modal', function () {
                customTourData.modalOpen = false; // Reset flag
                $('#customTourModal').remove();
                $('.modal-backdrop').remove();
                $('body').removeClass('modal-open').css('padding-right', '');
            });

            modal.show();
        }

        function loadExistingDestinations() {
            // Reset only destinations, preserve any existing pickup location
            customTourData.destinations = [];
            
            // DON'T reset pickup location if it already exists
            if (!customTourData.pickupLocation) {
                customTourData.pickupLocation = null;
                customTourData.isPickupSet = false;
            }
            
            // Try to get pickup location from main form ONLY if we don't have one
            if (!customTourData.pickupLocation || !customTourData.isPickupSet) {
                const startingLocationField = $('input[name="starting_location"]');
                const pickupLocationField = $('input[name="pickup_location"]');
                const fromLocationField = $('input[name="from"]');
                
                let foundLocation = false;
                
                // Check starting_location field first (custom tour field)
                if (startingLocationField.length && startingLocationField.val()) {
                    const lat = $('input[name="starting_lat"]').val();
                    const lng = $('input[name="starting_lng"]').val();
                    
                    if (lat && lng) {
                        customTourData.pickupLocation = {
                            name: startingLocationField.val().split(',')[0] || startingLocationField.val(),
                            address: startingLocationField.val(),
                            lat: parseFloat(lat),
                            lng: parseFloat(lng)
                        };
                        foundLocation = true;
                    }
                }
                
                // Check pickup_location field (other services)
                if (!foundLocation && pickupLocationField.length && pickupLocationField.val()) {
                    const lat = $('input[name="pickup_lat"]').val();
                    const lng = $('input[name="pickup_lng"]').val();
                    
                    if (lat && lng) {
                        customTourData.pickupLocation = {
                            name: pickupLocationField.val().split(',')[0] || pickupLocationField.val(),
                            address: pickupLocationField.val(),
                            lat: parseFloat(lat),
                            lng: parseFloat(lng)
                        };
                        foundLocation = true;
                    }
                }
                
                // Check from field (transfer services)
                if (!foundLocation && fromLocationField.length && fromLocationField.val()) {
                    const lat = $('input[name="from_lat"]').val();
                    const lng = $('input[name="from_lng"]').val();
                    
                    if (lat && lng) {
                        customTourData.pickupLocation = {
                            name: fromLocationField.val().split(',')[0] || fromLocationField.val(),
                            address: fromLocationField.val(),
                            lat: parseFloat(lat),
                            lng: parseFloat(lng)
                        };
                        foundLocation = true;
                    }
                }
                
                // Check for any existing location in other form fields
                if (!foundLocation) {
                    const allLocationFields = $('input[type="text"]').filter(function() {
                        const name = $(this).attr('name');
                        return name && name.includes('location') && $(this).val();
                    });
                    
                    allLocationFields.each(function() {
                        const $field = $(this);
                        const fieldName = $field.attr('name');
                        const latField = $(`input[name="${fieldName.replace('location', 'lat').replace('_location', '_lat')}"]`);
                        const lngField = $(`input[name="${fieldName.replace('location', 'lng').replace('_location', '_lng')}"]`);
                        
                        if (latField.length && lngField.length && latField.val() && lngField.val()) {
                            customTourData.pickupLocation = {
                                name: $field.val().split(',')[0] || $field.val(),
                                address: $field.val(),
                                lat: parseFloat(latField.val()),
                                lng: parseFloat(lngField.val())
                            };
                            foundLocation = true;
                            return false; // break the loop
                        }
                    });
                }
                
                // Set pickup location state if found
                if (foundLocation && customTourData.pickupLocation) {
                    customTourData.isPickupSet = true;
                    $('#pickup-location-input').val(customTourData.pickupLocation.address);
                    
                    // Load pickup date from main form if available
                    const pickupDateField = $('input[name="pickup_date"], input[name="starting_date"], input[name="date"]');
                    if (pickupDateField.length && pickupDateField.val()) {
                        customTourData.pickupLocation.pickupDate = pickupDateField.val();
                        $('#pickup-date-input').val(pickupDateField.val());
                    }
                    
                    // Load pickup time from main form if available
                    const pickupTimeField = $('input[name="pickup_time"], input[name="starting_time"], input[name="time"]');
                    if (pickupTimeField.length && pickupTimeField.val()) {
                        customTourData.pickupLocation.pickupTime = pickupTimeField.val();
                        $('#pickup-time-input').val(pickupTimeField.val());
                    }
                    
                    // Update date time display
                    updatePickupDateTimeDisplay();
                    
                    // Show in modal
                    setTimeout(() => {
                        showPickupLocation();
                        enableDestinationInput();
                        enableSearchButtonIfReady();
                    }, 100);
                }
            } else {
                // We already have a pickup location, just display it
                $('#pickup-location-input').val(customTourData.pickupLocation.address);
                
                // Load date/time if available
                if (customTourData.pickupLocation.pickupDate) {
                    $('#pickup-date-input').val(customTourData.pickupLocation.pickupDate);
                }
                if (customTourData.pickupLocation.pickupTime) {
                    $('#pickup-time-input').val(customTourData.pickupLocation.pickupTime);
                }
                updatePickupDateTimeDisplay();
                
                setTimeout(() => {
                    showPickupLocation();
                    enableDestinationInput();
                    enableSearchButtonIfReady();
                }, 100);
            }
            
            // Load existing custom destinations
            const existingData = $('input[name="custom_destinations"]').val();
            if (existingData) {
                try {
                    customTourData.destinations = JSON.parse(existingData);
                    renderDestinationsList();
                } catch (e) {
                    customTourData.destinations = [];
                }
            }
            
            // Calculate initial summary and enable search button if ready
            setTimeout(() => {
                calculateTourSummary();
                enableSearchButtonIfReady();
            }, 200);
        }
        
        function updateMainFormPickupLocation() {
            if (customTourData.pickupLocation) {
                // Only update fields that are empty or specifically for custom tour
                if (!$('input[name="starting_location"]').val() || $('input[name="starting_location"]').length > 0) {
                    $('input[name="starting_location"]').val(customTourData.pickupLocation.address);
                    $('input[name="starting_lat"]').val(customTourData.pickupLocation.lat);
                    $('input[name="starting_lng"]').val(customTourData.pickupLocation.lng);
                }
                
                // For pickup_location and from fields, only update if they're empty
                if (!$('input[name="pickup_location"]').val()) {
                    $('input[name="pickup_location"]').val(customTourData.pickupLocation.address);
                    $('input[name="pickup_lat"]').val(customTourData.pickupLocation.lat);
                    $('input[name="pickup_lng"]').val(customTourData.pickupLocation.lng);
                }
                
                if (!$('input[name="from"]').val()) {
                    $('input[name="from"]').val(customTourData.pickupLocation.address);
                    $('input[name="from_lat"]').val(customTourData.pickupLocation.lat);
                    $('input[name="from_lng"]').val(customTourData.pickupLocation.lng);
                }
            }
        }

        function initializeCustomTourMap() {
            // Clear existing map
            if (customTourData.map) {
                customTourData.map.remove();
            }

            // Check if Leaflet is loaded, if not load it
            if (typeof L === 'undefined') {
                if (!$('link[href*="leaflet.css"]').length) {
                    $('head').append(
                        '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">');
                }
                $.getScript('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', function() {
                    createMap();
                });
            } else {
                createMap();
            }

            function createMap() {
                customTourData.map = L.map('custom-tour-map').setView([7.8731, 80.7718], 8);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap'
                }).addTo(customTourData.map);

                setTimeout(() => {
                    customTourData.map.invalidateSize();
                    updateMapRoute();
                }, 100);
            }
        }

        function setupDestinationAutocomplete() {
            // Setup pickup location autocomplete
            const pickupInput = document.getElementById('pickup-location-input');
            const destinationInput = document.getElementById('new-destination-input');

            if (window.google && window.google.maps) {
                // Pickup location autocomplete
                const pickupAutocomplete = new google.maps.places.Autocomplete(pickupInput, {
                    componentRestrictions: { country: 'lk' },
                    fields: ['place_id', 'geometry', 'name', 'formatted_address']
                });

                pickupAutocomplete.addListener('place_changed', function() {
                    const place = pickupAutocomplete.getPlace();
                    if (place.geometry) {
                        customTourData.pickupLocation = {
                            name: place.name,
                            address: place.formatted_address,
                            lat: place.geometry.location.lat(),
                            lng: place.geometry.location.lng()
                        };
                    }
                });

                // Destination autocomplete
                const destinationAutocomplete = new google.maps.places.Autocomplete(destinationInput, {
                    componentRestrictions: { country: 'lk' },
                    fields: ['place_id', 'geometry', 'name', 'formatted_address']
                });

                destinationAutocomplete.addListener('place_changed', function() {
                    const place = destinationAutocomplete.getPlace();
                    if (place.geometry) {
                        addDestination({
                            name: place.name,
                            address: place.formatted_address,
                            lat: place.geometry.location.lat(),
                            lng: place.geometry.location.lng()
                        });
                        destinationInput.value = '';
                    }
                });
            }
        }
        
        function showPickupLocation() {
            if (customTourData.pickupLocation) {
                $('#pickup-name').text(customTourData.pickupLocation.name);
                $('#pickup-address').text(customTourData.pickupLocation.address);
                $('#pickup-location-input').hide();
                $('#set-pickup-btn').hide();
                $('#pickup-location-display').show();
            }
        }
        
        function enableDestinationInput() {
            $('#new-destination-input').prop('disabled', false).attr('placeholder', 'Search destination in Sri Lanka...');
            $('#add-destination-btn').prop('disabled', false);
            $('.text-muted').hide();
        }
        
        function disableDestinationInput() {
            $('#new-destination-input').prop('disabled', true).attr('placeholder', 'Set pickup location first...');
            $('#add-destination-btn').prop('disabled', true);
            $('.text-muted').show();
        }

        function addDestination(dest) {
            if (!customTourData.isPickupSet) {
                alert('Please set your pickup location first.');
                return;
            }
            
            customTourData.destinations.push(dest);
            renderDestinationsList();
            updateMapRoute();
            updateMainForm();
            calculateDistanceMatrix();
            calculateTourSummary();
            enableSearchButtonIfReady();
        }
        
        function updateMainForm() {
            // Update the main form with pickup location and destinations
            if (customTourData.isPickupSet && customTourData.pickupLocation) {
                // Update starting location in main form (custom tour specific)
                $('input[name="starting_location"]').val(customTourData.pickupLocation.address);
                $('input[name="starting_lat"]').val(customTourData.pickupLocation.lat);
                $('input[name="starting_lng"]').val(customTourData.pickupLocation.lng);
                
                // Also update other possible pickup location fields
                $('input[name="pickup_location"]').val(customTourData.pickupLocation.address);
                $('input[name="pickup_lat"]').val(customTourData.pickupLocation.lat);
                $('input[name="pickup_lng"]').val(customTourData.pickupLocation.lng);
                
                $('input[name="from"]').val(customTourData.pickupLocation.address);
                $('input[name="from_lat"]').val(customTourData.pickupLocation.lat);
                $('input[name="from_lng"]').val(customTourData.pickupLocation.lng);
            }
            
            // Update custom destinations hidden field
            $('input[name="custom_destinations"]').val(JSON.stringify(customTourData.destinations));
        }

        function setupCustomTourHandlers() {
            // Set pickup location button
            $('#set-pickup-btn').off('click').on('click', function() {
                if (customTourData.pickupLocation) {
                    setPickupLocationFromModal(customTourData.pickupLocation);
                } else {
                    alert('Please select a pickup location first.');
                }
            });

            // Change pickup location button
            $(document).off('click', '#change-pickup-btn').on('click', '#change-pickup-btn', function() {
                customTourData.isPickupSet = false;
                customTourData.pickupLocation = null;
                $('#pickup-location-display').hide();
                $('#pickup-location-input').show().val('');
                $('#set-pickup-btn').show();
                disableDestinationInput();
                updateMapRoute();
                
                // Clear pickup location from main form too
                $('input[name="starting_location"]').val('');
                $('input[name="starting_lat"]').val('');
                $('input[name="starting_lng"]').val('');
                $('input[name="pickup_location"]').val('');
                $('input[name="pickup_lat"]').val('');
                $('input[name="pickup_lng"]').val('');
            });

            // Add destination button
            $('#add-destination-btn').off('click').on('click', function() {
                if (customTourData.isPickupSet) {
                    $('#new-destination-input').focus();
                } else {
                    alert('Please set your pickup location first.');
                }
            });

            // Remove destination
            $(document).off('click', '.remove-destination').on('click', '.remove-destination', function() {
                const index = $(this).data('index');
                customTourData.destinations.splice(index, 1);
                renderDestinationsList();
                updateMapRoute();
                updateMainForm();
                calculateDistanceMatrix();
                calculateTourSummary();
                enableSearchButtonIfReady();
            });

            // Search custom tour - direct to search results
            $('#search-custom-tour').off('click').on('click', function() {
                searchCustomTour();
            });

            setupDragAndDrop();
        }

        function renderDestinationsList() {
            const $list = $('#destinations-list');

            if (customTourData.destinations.length === 0) {
                $list.html(`
                <p class="text-muted text-center py-4">
                    <i class="fas fa-route fa-2x mb-2"></i><br>
                    <strong>Your tour destinations will appear here</strong><br>
                    <small>Add destinations to build your custom tour route</small>
                </p>
            `);
                $('#total-stops').text('0');
                return;
            }

            let html = '<div id="sortable-destinations">';

            customTourData.destinations.forEach((dest, index) => {
                html += `
                <div class="destination-item card mb-3 p-3 shadow-sm" data-index="${index}" draggable="true">
                    <div class="d-flex align-items-start">
                        <div class="drag-handle me-2" style="cursor: move;">
                            <i class="fas fa-grip-vertical text-muted"></i>
                        </div>
                        <div class="me-2">
                            <span class="badge bg-primary">${index + 1}</span>
                        </div>
                        <div class="flex-grow-1">
                            <div class="mb-2">
                                <strong>${dest.name}</strong><br>
                                <small class="text-muted">${dest.address}</small>
                            </div>
                            
                            <!-- Date and Time Selection -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label text-sm">Visit Date</label>
                                    <input type="date" 
                                           class="form-control form-control-sm destination-date"
                                           value="${dest.visitDate || ''}"
                                           min="${new Date().toISOString().split('T')[0]}"
                                           onchange="updateDestinationDate(${index}, this.value)">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-sm">Visit Time</label>
                                    <input type="time" 
                                           class="form-control form-control-sm destination-time"
                                           value="${dest.visitTime || ''}"
                                           onchange="updateDestinationTime(${index}, this.value)">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-sm">Duration</label>
                                    <select class="form-control form-control-sm destination-duration"
                                            onchange="updateDestinationDuration(${index}, this.value)">
                                        <option value="0.5" ${dest.duration === '0.5' ? 'selected' : ''}>30 min</option>
                                        <option value="1" ${dest.duration === '1' || !dest.duration ? 'selected' : ''}>1 hour</option>
                                        <option value="1.5" ${dest.duration === '1.5' ? 'selected' : ''}>1.5 hours</option>
                                        <option value="2" ${dest.duration === '2' ? 'selected' : ''}>2 hours</option>
                                        <option value="3" ${dest.duration === '3' ? 'selected' : ''}>3 hours</option>
                                        <option value="4" ${dest.duration === '4' ? 'selected' : ''}>4 hours</option>
                                        <option value="6" ${dest.duration === '6' ? 'selected' : ''}>6 hours</option>
                                        <option value="8" ${dest.duration === '8' ? 'selected' : ''}>Full day</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="distance-info mt-1" id="distance-${index}" style="display: none;">
                                <small class="text-info">
                                    <i class="fas fa-route"></i> 
                                    <span class="distance-text">Calculating...</span>
                                </small>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-danger remove-destination" data-index="${index}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            });

            html += '</div>';
            $list.html(html);
            
            // Update total stops counter
            $('#total-stops').text(customTourData.destinations.length);
        }

        function setupDragAndDrop() {
            let draggedIndex = null;

            $(document).off('dragstart', '.destination-item').on('dragstart', '.destination-item', function(e) {
                draggedIndex = parseInt($(this).data('index'));
                $(this).addClass('dragging');
                e.originalEvent.dataTransfer.effectAllowed = 'move';
            });

            $(document).off('dragend', '.destination-item').on('dragend', '.destination-item', function() {
                $(this).removeClass('dragging');
                $('.destination-item').removeClass('drag-over');
            });

            $(document).off('dragover', '.destination-item').on('dragover', '.destination-item', function(e) {
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'move';

                if (draggedIndex !== null) {
                    const targetIndex = parseInt($(this).data('index'));
                    if (draggedIndex !== targetIndex) {
                        $(this).addClass('drag-over');
                    }
                }
            });

            $(document).off('dragleave', '.destination-item').on('dragleave', '.destination-item', function() {
                $(this).removeClass('drag-over');
            });

            $(document).off('drop', '.destination-item').on('drop', '.destination-item', function(e) {
                e.preventDefault();
                $(this).removeClass('drag-over');

                const targetIndex = parseInt($(this).data('index'));

                if (draggedIndex !== null && draggedIndex !== targetIndex) {
                    const [draggedItem] = customTourData.destinations.splice(draggedIndex, 1);
                    customTourData.destinations.splice(targetIndex, 0, draggedItem);

                    renderDestinationsList();
                    updateMapRoute();
                    updateMainForm();
                    calculateDistanceMatrix();
                }
            });
        }

        function updateMapRoute() {
            if (!customTourData.map) {
                console.log('Map not initialized yet');
                return;
            }

            // Clear existing markers and polylines
            if (customTourData.markers) {
                customTourData.markers.forEach(m => {
                    if (m && typeof m.remove === 'function') {
                        m.remove();
                    }
                });
            }
            customTourData.markers = [];
            
            if (customTourData.polylines) {
                customTourData.polylines.forEach(line => {
                    if (line && typeof line.remove === 'function') {
                        line.remove();
                    }
                });
            }
            customTourData.polylines = [];

            const bounds = L.latLngBounds([]);
            const allLocations = [];

            // Add pickup location marker (if set)
            if (customTourData.isPickupSet && customTourData.pickupLocation) {
                const pickupMarker = L.marker([customTourData.pickupLocation.lat, customTourData.pickupLocation.lng], {
                    icon: L.divIcon({
                        className: 'custom-marker pickup-marker',
                        html: `<div style="background: #28a745; color: white; border-radius: 50%; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 3px solid white; box-shadow: 0 3px 8px rgba(0,0,0,0.5);"><i class="fas fa-home"></i></div>`,
                        iconSize: [35, 35],
                        iconAnchor: [17.5, 17.5]
                    })
                }).addTo(customTourData.map);

                pickupMarker.bindPopup(`<strong style="color: #28a745;"><i class="fas fa-home"></i> Pickup Location</strong><br>${customTourData.pickupLocation.name}`);
                customTourData.markers.push(pickupMarker);
                bounds.extend([customTourData.pickupLocation.lat, customTourData.pickupLocation.lng]);
                allLocations.push(customTourData.pickupLocation);
            }

            // Add destination markers
            customTourData.destinations.forEach((dest, idx) => {
                const marker = L.marker([dest.lat, dest.lng], {
                    icon: L.divIcon({
                        className: 'custom-marker destination-marker',
                        html: `<div style="background: #007bff; color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.4);">${idx + 1}</div>`,
                        iconSize: [32, 32],
                        iconAnchor: [16, 16]
                    })
                }).addTo(customTourData.map);

                marker.bindPopup(`<strong>Stop ${idx + 1}</strong><br>${dest.name}`);
                customTourData.markers.push(marker);
                bounds.extend([dest.lat, dest.lng]);
                allLocations.push(dest);
            });

            // Draw straight lines between consecutive locations
            if (allLocations.length > 1) {
                for (let i = 0; i < allLocations.length - 1; i++) {
                    const from = allLocations[i];
                    const to = allLocations[i + 1];
                    
                    const polyline = L.polyline([
                        [from.lat, from.lng],
                        [to.lat, to.lng]
                    ], {
                        color: i === 0 ? '#28a745' : '#007bff', // Green for pickup to first destination
                        weight: 4,
                        opacity: 0.8,
                        dashArray: '8, 12'
                    }).addTo(customTourData.map);

                    // Add distance label on the line
                    const midLat = (from.lat + to.lat) / 2;
                    const midLng = (from.lng + to.lng) / 2;
                    const distance = L.latLng(from.lat, from.lng).distanceTo(L.latLng(to.lat, to.lng));
                    const distanceKm = (distance / 1000).toFixed(1);
                    
                    const distanceMarker = L.marker([midLat, midLng], {
                        icon: L.divIcon({
                            className: 'distance-label',
                            html: `<div style="background: rgba(255,255,255,0.9); padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; border: 1px solid #ccc;">${distanceKm}km</div>`,
                            iconSize: [60, 20],
                            iconAnchor: [30, 10]
                        })
                    }).addTo(customTourData.map);

                    customTourData.polylines.push(polyline);
                    customTourData.markers.push(distanceMarker);
                }

                // Calculate and display basic distance
                calculateBasicDistance();
            }

            // Fit map to bounds if there are locations
            if (allLocations.length > 0) {
                customTourData.map.fitBounds(bounds, { padding: [20, 20] });
            }
        }

        function calculateBasicDistance() {
            if (!customTourData.isPickupSet || customTourData.destinations.length === 0) {
                $('#total-distance').text('0 km');
                $('#estimated-duration').text('0 hours');
                return;
            }

            let totalDistance = 0;
            const allLocations = [customTourData.pickupLocation, ...customTourData.destinations];

            // Calculate straight-line distances between consecutive locations
            for (let i = 0; i < allLocations.length - 1; i++) {
                const from = L.latLng(allLocations[i].lat, allLocations[i].lng);
                const to = L.latLng(allLocations[i + 1].lat, allLocations[i + 1].lng);
                totalDistance += from.distanceTo(to);
            }

            const distanceKm = (totalDistance / 1000).toFixed(1);
            $('#total-distance').text(`${distanceKm} km (straight-line)`);

            // Basic time estimation (assuming 40 km/h average + stops)
            const hours = (distanceKm / 40 + customTourData.destinations.length * 0.5).toFixed(1);
            $('#estimated-duration').text(`${hours} hours (estimated)`);
        }

        function calculateDistanceMatrix() {
            // Only calculate if Google Maps is loaded and we have locations
            if (!window.google || !window.google.maps || !customTourData.isPickupSet || customTourData.destinations.length === 0) {
                console.log('Google Maps not available or insufficient data for distance calculation');
                return;
            }

            console.log('Calculating distances with Google Distance Matrix API');
            const service = new google.maps.DistanceMatrixService();
            const allLocations = [customTourData.pickupLocation, ...customTourData.destinations];
            
            // Show loading indicators
            $('.distance-info').show().find('.distance-text').text('Calculating...');

            // Calculate distances from pickup to each destination and between destinations
            for (let i = 0; i < allLocations.length - 1; i++) {
                const origin = new google.maps.LatLng(allLocations[i].lat, allLocations[i].lng);
                const destination = new google.maps.LatLng(allLocations[i + 1].lat, allLocations[i + 1].lng);

                (function(segmentIndex) {
                    service.getDistanceMatrix({
                        origins: [origin],
                        destinations: [destination],
                        travelMode: google.maps.TravelMode.DRIVING,
                        unitSystem: google.maps.UnitSystem.METRIC,
                        avoidHighways: false,
                        avoidTolls: false
                    }, function(response, status) {
                        console.log(`Distance API response for segment ${segmentIndex}:`, status, response);
                        
                        if (status === 'OK' && response.rows[0].elements[0].status === 'OK') {
                            const distance = response.rows[0].elements[0].distance.text;
                            const duration = response.rows[0].elements[0].duration.text;
                            
                            // Update the corresponding destination info
                            if (segmentIndex === 0) {
                                // First segment (pickup to first destination)
                                $(`#distance-0 .distance-text`).text(`${distance} from pickup (${duration})`);
                            } else {
                                // Subsequent segments
                                $(`#distance-${segmentIndex} .distance-text`).text(`${distance} from previous (${duration})`);
                            }
                        } else {
                            console.error(`Distance calculation failed for segment ${segmentIndex}:`, status);
                            $(`#distance-${segmentIndex} .distance-text`).text('Distance unavailable');
                        }
                    });
                })(i);
            }

            // Calculate total route distance after a short delay
            setTimeout(calculateTotalRouteDistance, 1000);
        }

        function calculateTotalRouteDistance() {
            if (!window.google || !window.google.maps || !customTourData.isPickupSet || customTourData.destinations.length === 0) {
                console.log('Cannot calculate total route distance - missing requirements');
                return;
            }

            console.log('Calculating total route distance');
            const service = new google.maps.DistanceMatrixService();
            const allLocations = [customTourData.pickupLocation, ...customTourData.destinations];
            
            const origins = [];
            const destinations = [];

            // Create origin-destination pairs for consecutive locations
            for (let i = 0; i < allLocations.length - 1; i++) {
                origins.push(new google.maps.LatLng(allLocations[i].lat, allLocations[i].lng));
                destinations.push(new google.maps.LatLng(allLocations[i + 1].lat, allLocations[i + 1].lng));
            }

            service.getDistanceMatrix({
                origins: origins,
                destinations: destinations,
                travelMode: google.maps.TravelMode.DRIVING,
                unitSystem: google.maps.UnitSystem.METRIC,
                avoidHighways: false,
                avoidTolls: false
            }, function(response, status) {
                console.log('Total route distance API response:', status, response);
                
                if (status === 'OK') {
                    let totalDistance = 0;
                    let totalDuration = 0;
                    let validSegments = 0;

                    response.rows.forEach((row, index) => {
                        if (row.elements[0].status === 'OK') {
                            totalDistance += row.elements[0].distance.value; // in meters
                            totalDuration += row.elements[0].duration.value; // in seconds
                            validSegments++;
                        }
                    });

                    if (validSegments > 0) {
                        const distanceKm = (totalDistance / 1000).toFixed(1);
                        const durationHours = (totalDuration / 3600).toFixed(1);
                        
                        $('#total-distance').text(`${distanceKm} km (driving)`);
                        $('#estimated-duration').text(`${durationHours} hours (driving)`);
                        
                        console.log(`Total distance calculated: ${distanceKm}km, duration: ${durationHours}h`);
                    } else {
                        console.error('No valid distance segments found');
                    }
                } else {
                    console.error('Total route distance calculation failed:', status);
                }
            });
        }

        function searchCustomTour() {
            if (!customTourData.isPickupSet) {
                alert('Please set your pickup location first.');
                return;
            }

            if (customTourData.destinations.length < 1) {
                alert('Please add at least 1 destination for your custom tour.');
                return;
            }
            
            if (!customTourData.pickupLocation?.pickupDate) {
                alert('Please set your pickup date.');
                return;
            }

            // Calculate final summary
            calculateTourSummary();
            
            // Prepare data for search
            const searchData = {
                service_type: 'custom_tour',
                pickup_location: customTourData.pickupLocation.address,
                pickup_lat: customTourData.pickupLocation.lat,
                pickup_lng: customTourData.pickupLocation.lng,
                pickup_date: customTourData.pickupLocation.pickupDate,
                pickup_time: customTourData.pickupLocation.pickupTime || '09:00',
                destinations: customTourData.destinations,
                summary: customTourData.summary,
                total_distance: customTourData.summary?.totalDistance || 0,
                tour_duration: customTourData.summary?.tourDuration || 1,
                total_stops: customTourData.destinations.length
            };

            // Create a form and submit to search
            const form = $('<form>', {
                method: 'POST',
                action: '/search-results',
                style: 'display: none;'
            });
            
            // Add CSRF token
            form.append($('<input>', {
                type: 'hidden',
                name: '_token',
                value: $('meta[name="csrf-token"]').attr('content')
            }));
            
            // Add search data
            Object.keys(searchData).forEach(key => {
                if (typeof searchData[key] === 'object') {
                    form.append($('<input>', {
                        type: 'hidden',
                        name: key,
                        value: JSON.stringify(searchData[key])
                    }));
                } else {
                    form.append($('<input>', {
                        type: 'hidden',
                        name: key,
                        value: searchData[key]
                    }));
                }
            });
            
            // Append form to body and submit
            $('body').append(form);
            form.submit();
        }

        // ===================================================================
        // Initialize All Features
        // ===================================================================
        function initializeAllFeatures() {
            setupAirportTransferRestrictions();
            setupReturnTransferToggle();
            setupDateTimeValidations();
            setupCustomTourModal();
            initializeGoogleMapsAutocomplete();
            initializeDynamicDatePickers();
            initializeAirportFiltering();
            setDefaultLocations();
            initializeCustomTourFeatures();
        }

        // Enhanced Date Picker Initialization
        function initializeDynamicDatePickers() {
            // Initialize all dynamic datepickers
            $(document).off('focus', '.dynamic-datepicker').on('focus', '.dynamic-datepicker', function() {
                if (!$(this).hasClass('datepicker-initialized')) {
                    $(this).addClass('datepicker-initialized');
                    $(this).datepicker({
                        format: 'dd/mm/yyyy',
                        autoclose: true,
                        todayHighlight: true,
                        startDate: '0d',
                        orientation: 'auto'
                    });
                }
            });
        }

        // Airport Filtering for Location Autocomplete
        function initializeAirportFiltering() {
            // Enhanced Google Places autocomplete with airport filtering
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                $(document).off('focus', '.airport-pickup-search, .airport-destination-search')
                    .on('focus', '.airport-pickup-search, .airport-destination-search', function() {
                        const input = this;
                        const $input = $(input);
                        
                        if (!$input.data('autocomplete-initialized')) {
                            const autocomplete = new google.maps.places.Autocomplete(input, {
                                types: ['airport', 'establishment'],
                                componentRestrictions: { country: 'lk' }, // Sri Lanka
                                fields: ['place_id', 'geometry', 'name', 'formatted_address']
                            });
                            
                            autocomplete.addListener('place_changed', function() {
                                const place = autocomplete.getPlace();
                                if (place.geometry) {
                                    $input.siblings('input[name$="_lat"]').val(place.geometry.location.lat());
                                    $input.siblings('input[name$="_lng"]').val(place.geometry.location.lng());
                                }
                            });
                            
                            $input.data('autocomplete-initialized', true);
                        }
                    });
            }
        }

        // Set Default Locations for Non-Airport Services
        function setDefaultLocations() {
            if (currentServiceCode && !currentServiceCode.includes('airport')) {
                // Set default location to Colombo for non-airport services
                setTimeout(function() {
                    $('.location-search').each(function() {
                        const $input = $(this);
                        if (!$input.val()) {
                            $input.val('Colombo, Sri Lanka');
                            // Set approximate Colombo coordinates
                            $input.siblings('input[name$="_lat"]').val('6.9271');
                            $input.siblings('input[name$="_lng"]').val('79.8612');
                        }
                    });
                }, 500);
            }
        }

        // Custom Tour Features - Using Modal Only (No Google Maps in main form)
        function initializeCustomTourFeatures() {
            // Custom tour uses modal only - no map in main form
        }
        
        // Helper functions for destination date/time management
        function updateDestinationDate(index, date) {
            if (customTourData.destinations[index]) {
                customTourData.destinations[index].visitDate = date;
                calculateTourSummary();
                updateMainForm();
            }
        }
        
        function updateDestinationTime(index, time) {
            if (customTourData.destinations[index]) {
                customTourData.destinations[index].visitTime = time;
                calculateTourSummary();
                updateMainForm();
            }
        }
        
        function updateDestinationDuration(index, duration) {
            if (customTourData.destinations[index]) {
                customTourData.destinations[index].duration = duration;
                calculateTourSummary();
                updateMainForm();
            }
        }
        
        // Pickup date and time functions
        function updatePickupDate(date) {
            if (!customTourData.pickupLocation) {
                customTourData.pickupLocation = {};
            }
            customTourData.pickupLocation.pickupDate = date;
            updatePickupDateTimeDisplay();
            calculateTourSummary();
            enableSearchButtonIfReady();
        }
        
        function updatePickupTime(time) {
            if (!customTourData.pickupLocation) {
                customTourData.pickupLocation = {};
            }
            customTourData.pickupLocation.pickupTime = time;
            updatePickupDateTimeDisplay();
            calculateTourSummary();
            enableSearchButtonIfReady();
        }
        
        function updatePickupDateTimeDisplay() {
            const pickupDate = customTourData.pickupLocation?.pickupDate;
            const pickupTime = customTourData.pickupLocation?.pickupTime;
            
            if (pickupDate || pickupTime) {
                let dateTimeText = '';
                if (pickupDate) {
                    const date = new Date(pickupDate);
                    dateTimeText += date.toLocaleDateString('en-US', { 
                        weekday: 'short', 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric' 
                    });
                }
                if (pickupTime) {
                    if (dateTimeText) dateTimeText += ' at ';
                    dateTimeText += pickupTime;
                }
                
                $('#pickup-date-time-text').text(dateTimeText);
                $('#pickup-datetime-display').show();
            } else {
                $('#pickup-datetime-display').hide();
            }
        }
        
        // Enhanced summary calculations
        function calculateTourSummary() {
            if (!customTourData.destinations || customTourData.destinations.length === 0) {
                resetSummaryDisplay();
                return;
            }
            
            let totalDistance = 0;
            let totalTravelTime = 0; // in minutes
            let activeTime = 0; // in hours (visit durations)
            let datesUsed = new Set();
            
            // Calculate distances and travel times
            customTourData.destinations.forEach(dest => {
                if (dest.distance_value) {
                    totalDistance += dest.distance_value / 1000; // Convert to KM
                }
                if (dest.duration_value) {
                    totalTravelTime += dest.duration_value / 60; // Convert to hours
                }
                if (dest.duration) {
                    activeTime += parseFloat(dest.duration);
                }
                if (dest.visitDate) {
                    datesUsed.add(dest.visitDate);
                }
            });
            
            // Get pickup date if available
            const pickupDate = customTourData.pickupLocation?.pickupDate;
            if (pickupDate) {
                datesUsed.add(pickupDate);
            }
            
            // Calculate tour duration
            const dateArray = Array.from(datesUsed).sort();
            let tourDuration = 0;
            let idleDays = 0;
            
            if (dateArray.length > 1) {
                const startDate = new Date(dateArray[0]);
                const endDate = new Date(dateArray[dateArray.length - 1]);
                tourDuration = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1;
                idleDays = tourDuration - dateArray.length;
            } else if (dateArray.length === 1) {
                tourDuration = 1;
            }
            
            // Calculate idle time (assuming 8-hour working days)
            const maxWorkingHours = dateArray.length * 8;
            const idleTime = Math.max(0, maxWorkingHours - activeTime - totalTravelTime);
            
            // Update displays
            $('#total-distance').text(totalDistance.toFixed(1) + ' km');
            $('#total-travel-time').text(totalTravelTime.toFixed(1) + ' hours');
            $('#tour-duration').text(tourDuration + ' days');
            $('#active-time').text(activeTime.toFixed(1) + ' hours');
            $('#idle-time').text(idleTime.toFixed(1) + ' hours');
            $('#total-stops').text(customTourData.destinations.length);
            $('#scheduled-days').text(dateArray.length);
            $('#idle-days').text(idleDays);
            
            // Update date range
            if (dateArray.length > 0) {
                if (dateArray.length === 1) {
                    $('#tour-date-range').text(new Date(dateArray[0]).toLocaleDateString());
                } else {
                    const startFormatted = new Date(dateArray[0]).toLocaleDateString();
                    const endFormatted = new Date(dateArray[dateArray.length - 1]).toLocaleDateString();
                    $('#tour-date-range').text(`${startFormatted} - ${endFormatted}`);
                }
            } else {
                $('#tour-date-range').text('Not set');
            }
            
            // Store summary data for submission
            customTourData.summary = {
                totalDistance: totalDistance,
                totalTravelTime: totalTravelTime,
                tourDuration: tourDuration,
                activeTime: activeTime,
                idleTime: idleTime,
                scheduledDays: dateArray.length,
                idleDays: idleDays,
                dateRange: {
                    start: dateArray[0] || null,
                    end: dateArray[dateArray.length - 1] || null
                }
            };
        }
        
        function resetSummaryDisplay() {
            $('#total-distance').text('0 km');
            $('#total-travel-time').text('0 hours');
            $('#tour-duration').text('0 days');
            $('#active-time').text('0 hours');
            $('#idle-time').text('0 hours');
            $('#total-stops').text('0');
            $('#scheduled-days').text('0');
            $('#idle-days').text('0');
            $('#tour-date-range').text('Not set');
        }
        
        function enableSearchButtonIfReady() {
            const hasPickupLocation = customTourData.isPickupSet;
            const hasDestinations = customTourData.destinations && customTourData.destinations.length > 0;
            const hasPickupDate = customTourData.pickupLocation?.pickupDate;
            
            if (hasPickupLocation && hasDestinations && hasPickupDate) {
                $('#search-custom-tour').prop('disabled', false);
            } else {
                $('#search-custom-tour').prop('disabled', true);
            }
        }
        
        // Enhanced pickup location handling when setting from modal
        function setPickupLocationFromModal(locationData) {
            if (locationData) {
                customTourData.pickupLocation = locationData;
                customTourData.isPickupSet = true;
                
                // Only update main form if user explicitly sets it from modal
                // Don't override existing values unless user specifically changes them
                updateMainFormPickupLocation();
                
                // Update modal display
                showPickupLocation();
                enableDestinationInput();
                
                // Enable search button check
                enableSearchButtonIfReady();
                
                // Recalculate all distances
                if (customTourData.destinations.length > 0) {
                    calculateDistancesForAllDestinations();
                    drawTourRoute();
                }
                
                // Calculate summary
                calculateTourSummary();
            }
        }
    </script>
@endpush
