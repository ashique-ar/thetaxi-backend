{{--
    Vehicle Card Scripts Component
    
    Contains all JavaScript for vehicle card interactions (Add to Cart, Book Now, Request Quotation).
    Uses event delegation pattern to ensure handlers work correctly regardless of how many vehicle cards
    are rendered on the page.
    
    This component should be included once per page, typically via the vehicle-card component.
    The @once directive ensures scripts are loaded only once even if multiple vehicle cards are rendered.
    
    Requirements: 2.1, 2.2, 2.3, 2.4, 6.1, 6.2, 6.3, 6.4, 6.5, 6.6
--}}

@once('vehicle-card-scripts')
@push('scripts')
<script>
    /**
     * Vehicle Card Scripts - Event Delegation Pattern
     * 
     * All event handlers use $(document).on() for event delegation to ensure:
     * 1. Handlers work for dynamically added elements
     * 2. Only one handler is bound regardless of how many cards exist
     * 3. No duplicate event bindings when component is included multiple times
     * 
     * Requirements: 2.2, 2.3, 6.1, 6.2, 6.3, 6.6
     */
    (function() {
        // Guard against multiple initializations
        if (window.vehicleCardScriptsInitialized) {
            return;
        }
        window.vehicleCardScriptsInitialized = true;

        /**
         * Add to Cart AJAX function with button state management
         * 
         * @param {Object} item - Cart item data
         * @param {Function} callback - Callback function called with response
         * 
         * Requirements: 2.5, 6.4, 6.5
         */
        window.addToCart = function(item, callback) {
            // Add to cart via AJAX - let backend recalculate pricing
            console.log('Adding to cart with return trip data:', {
                is_return_trip: item.is_return_trip,
                return_trip_date: item.return_trip_date,
                service_package_id: item.service_package_id,
                package_id: item.package_id
            });

            return $.ajax({
                url: '{{ route('cart.add') }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    vehicle_group_id: item.group_id,
                    name: item.group_name,
                    pickup_date: item.from_date,
                    return_date: item.to_date,
                    from_time: item.from_time,
                    to_time: item.to_time,
                    pickup_location: item.pickup_location,
                    pickup_lat: item.pickup_lat,
                    pickup_lng: item.pickup_lng,
                    dropoff_location: item.dropoff_location,
                    dropoff_lat: item.dropoff_lat,
                    dropoff_lng: item.dropoff_lng,
                    service_type: item.service_type,
                    // Service package for pricing
                    service_package_id: item.service_package_id || item.package_id || '',
                    package_id: item.package_id || item.service_package_id || '',
                    // Return trip data
                    is_return_trip: item.is_return_trip || false,
                    return_trip_date: item.return_trip_date || '',
                    return_trip_time: item.return_trip_time || '',
                    search_data: item
                },
                success: function(response) {
                    if (response.success) {
                        // Load updated cart from server (function from cart-summary-float component)
                        if (typeof loadCart === 'function') {
                            loadCart();
                        } else if (typeof loadCartFromServer === 'function') {
                            loadCartFromServer();
                        }
                        
                        // Show cart float if function exists
                        if (typeof showCartFloat === 'function') {
                            showCartFloat();
                        }
                        
                        // Dispatch cart updated event
                        window.dispatchEvent(new CustomEvent('cartUpdated'));
                        
                        // Show success message
                        showSuccessNotification('Vehicle added to cart successfully!');

                        // Invoke callback if provided
                        if (typeof callback === 'function') {
                            callback(response);
                        }
                    } else {
                        showErrorNotification('Error: ' + response.message);
                        if (typeof callback === 'function') {
                            callback({
                                success: false,
                                error: response
                            });
                        }
                    }
                },
                error: function(xhr) {
                    console.error('Error adding to cart:', xhr);
                    const errorMsg = xhr.responseJSON?.message || 'Error adding item to cart. Please try again.';
                    showErrorNotification(errorMsg);
                    if (typeof callback === 'function') {
                        callback({
                            success: false,
                            error: xhr
                        });
                    }
                }
            });
        };

        /**
         * Show success notification toast
         * Creates a Bootstrap alert that auto-dismisses after 4 seconds
         * 
         * @param {string} message - The success message to display
         * 
         * Requirements: 2.7
         */
        window.showSuccessNotification = function(message) {
            // Check if function already exists from cart-summary-float component
            if (window._cartFloatShowSuccessNotification) {
                window._cartFloatShowSuccessNotification(message);
                return;
            }
            
            const alert = $(`
                <div class="alert alert-success alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 1100; min-width: 300px;">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `);
            $('body').append(alert);
            setTimeout(() => alert.alert('close'), 4000);
        };

        /**
         * Show error notification toast
         * Creates a Bootstrap alert that auto-dismisses after 5 seconds
         * 
         * @param {string} message - The error message to display
         * 
         * Requirements: 2.8
         */
        window.showErrorNotification = function(message) {
            // Check if function already exists from cart-summary-float component
            if (window._cartFloatShowErrorNotification) {
                window._cartFloatShowErrorNotification(message);
                return;
            }
            
            const alert = $(`
                <div class="alert alert-danger alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 1100; min-width: 300px;">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `);
            $('body').append(alert);
            setTimeout(() => alert.alert('close'), 5000);
        };

        /**
         * Get search data from the page
         * Extracts booking search parameters from:
         * 1. Global window.bookingSearchData variable (set by search page)
         * 2. Data attributes on the button
         * 3. Fallback minimal data (server will use search_id to get full data)
         * 
         * @param {jQuery} $btn - The button element that was clicked
         * @returns {Object} Search data object
         */
        function getSearchData($btn) {
            const searchId = $btn.data('search-id');
            
            // Try to get search data from global variable if available (set by search.blade.php)
            if (window.bookingSearchData) {
                return {
                    search_id: searchId,
                    ...window.bookingSearchData
                };
            }
            
            // Try to get from data attributes on button or parent form
            const $card = $btn.closest('.vehicle-card');
            
            // Fallback: return minimal data (server will use search_id to get full data)
            return {
                search_id: searchId,
                from_date: $btn.data('from-date') || '',
                to_date: $btn.data('to-date') || '',
                from_time: $btn.data('from-time') || '',
                to_time: $btn.data('to-time') || '',
                service_type: $btn.data('service-type') || '',
                pickup_location: $btn.data('pickup-location') || '',
                pickup_lat: $btn.data('pickup-lat') || null,
                pickup_lng: $btn.data('pickup-lng') || null,
                dropoff_location: $btn.data('dropoff-location') || '',
                dropoff_lat: $btn.data('dropoff-lat') || null,
                dropoff_lng: $btn.data('dropoff-lng') || null,
                duration_days: parseInt($btn.data('duration-days')) || 1,
                service_package_id: $btn.data('service-package-id') || '',
                package_id: $btn.data('package-id') || '',
                is_return_trip: $btn.data('is-return-trip') === true || $btn.data('is-return-trip') === 'true',
                return_trip_date: $btn.data('return-trip-date') || '',
                return_trip_time: $btn.data('return-trip-time') || ''
            };
        }

        /**
         * Handle button loading state
         * Disables button and shows spinner during AJAX request
         * 
         * @param {jQuery} $btn - The button element
         * @param {boolean} loading - Whether to show loading state
         * @param {string} loadingText - Text to show while loading
         */
        function setButtonLoading($btn, loading, loadingText = 'Adding...') {
            if (loading) {
                if (!$btn.data('original-html')) {
                    $btn.data('original-html', $btn.html());
                }
                const spinner = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>';
                $btn.prop('disabled', true).addClass('loading').html(spinner + loadingText);
            } else {
                $btn.prop('disabled', false).removeClass('loading').html($btn.data('original-html'));
            }
        }

        // ============================================================
        // EVENT HANDLERS - Using Event Delegation (Requirements: 6.1, 6.2, 6.3)
        // ============================================================

        /**
         * Add to Cart Button Handler
         * Uses event delegation to handle clicks on any .add-to-cart-btn element
         * 
         * Requirements: 2.2, 6.1, 6.4, 6.5
         */
        $(document).on('click', '.add-to-cart-btn', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            
            // Prevent double-clicks (Requirement 6.4)
            if ($btn.prop('disabled') || $btn.hasClass('loading')) {
                return;
            }
            
            const groupId = $btn.data('group-id');
            const groupName = $btn.data('group-name');
            const totalPrice = parseFloat($btn.data('base-price'));
            const currency = $btn.data('currency');
            
            // Get search data
            const searchData = getSearchData($btn);
            
            // Build item object
            const item = {
                group_id: groupId,
                group_name: groupName,
                currency: currency,
                quantity: 1,
                ...searchData
            };
            
            // Show loading state (Requirement 6.4)
            setButtonLoading($btn, true, 'Adding...');
            
            // Safety timeout in case callback never fires (Requirement 6.5)
            const safetyTimer = setTimeout(() => {
                console.error('Add to cart safety timeout');
                setButtonLoading($btn, false);
                showErrorNotification('Timed out adding to cart. Please check your network and try again.');
            }, 15000); // 15s
            
            // Send to cart with callback to restore button state
            addToCart(item, function(response) {
                clearTimeout(safetyTimer);
                if (response && response.success !== false) {
                    // Show success state briefly
                    $btn.html('<i class="bi bi-check-circle-fill"></i> Added!');
                    setTimeout(() => {
                        setButtonLoading($btn, false);
                    }, 2000);
                } else {
                    // If adding to cart failed, restore button state and show error
                    console.error('Add to cart failed.', response);
                    setButtonLoading($btn, false);
                    showErrorNotification('Failed to add to cart. Please try again.');
                }
            });
        });

        /**
         * Book Now Button Handler
         * Adds item to cart then redirects to cart page
         * Uses event delegation to handle clicks on any .book-now-btn element
         * 
         * Requirements: 2.3, 2.6, 6.2
         */
        $(document).on('click', '.book-now-btn', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            
            // Prevent double-clicks
            if ($btn.prop('disabled') || $btn.hasClass('loading')) {
                return;
            }
            
            const groupId = $btn.data('group-id');
            const groupName = $btn.data('group-name');
            const totalPrice = parseFloat($btn.data('base-price'));
            const currency = $btn.data('currency');
            
            // Get search data
            const searchData = getSearchData($btn);
            
            // Debug: log Book Now payload
            console.log('Book Now payload', {
                group_id: groupId,
                group_name: groupName,
                service_package_id: searchData.service_package_id,
                is_return_trip: searchData.is_return_trip,
                return_trip_date: searchData.return_trip_date
            });
            
            // Build item object
            const item = {
                group_id: groupId,
                group_name: groupName,
                currency: currency,
                quantity: 1,
                ...searchData
            };
            
            // Show loading state
            setButtonLoading($btn, true, 'Adding...');
            
            // Safety timeout in case callback never fires
            const safetyTimer = setTimeout(() => {
                console.error('Book now safety timeout');
                setButtonLoading($btn, false);
                showErrorNotification('Timed out adding to cart. Please check your network and try again.');
            }, 15000); // 15s
            
            // Add to cart first and redirect only after successful addition
            addToCart(item, function(response) {
                clearTimeout(safetyTimer);
                if (response && response.success !== false) {
                    // Redirect to checkout page
                    window.location.href = '{{ route('checkout') }}';
                } else {
                    // If adding to cart failed, restore button state and show error
                    console.error('Add to cart failed, not redirecting to cart.', response);
                    setButtonLoading($btn, false);
                }
            });
        });

        /**
         * Request Quotation Button Handler
         * Populates the quotation modal with vehicle details
         * Uses event delegation to handle clicks on any .request-quotation-btn element
         * 
         * Requirements: 2.3, 6.3
         */
        $(document).on('click', '.request-quotation-btn', function(e) {
            const $btn = $(this);
            const groupId = $btn.data('group-id');
            const groupName = $btn.data('group-name');
            const searchId = $btn.data('search-id');
            
            // Populate modal fields
            $('#quotation_vehicle_group_id').val(groupId);
            $('#quotation_search_id').val(searchId || '');
            $('#quotation_vehicle_name').text(groupName);
            if (window.bookingSearchData) {
                $('#quotation_service_type').val(window.bookingSearchData.service_type || 'day_rental');
                $('#quotation_pickup_location').val(window.bookingSearchData.pickup_location || '');
                $('#quotation_dropoff_location').val(window.bookingSearchData.dropoff_location || '');
                $('#quotation_travel_date').val(window.bookingSearchData.from_date || '');
                $('#quotation_travel_time').val(window.bookingSearchData.from_time || '');
                $('#quotation_return_date').val(window.bookingSearchData.to_date || window.bookingSearchData.return_trip_date || '');
                $('#quotation_return_time').val(window.bookingSearchData.to_time || window.bookingSearchData.return_trip_time || '');
            }
        });

        /**
         * Quotation Form Submission Handler
         * Handles AJAX submission of the quotation request form
         * 
         * Requirements: 2.8
         */
        $(document).on('submit', '#quotationRequestForm', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $submitBtn = $form.find('button[type="submit"]');
            const originalBtnText = $submitBtn.html();
            
            // Show loading state
            $submitBtn.prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...'
            );
            
            $.ajax({
                url: $form.attr('action'),
                method: 'POST',
                data: $form.serialize(),
                success: function(response) {
                    if (response.success) {
                        // Close modal and show success
                        $('#requestQuotationModal').modal('hide');
                        showSuccessNotification(
                            'Quotation request submitted successfully! Our team will contact you shortly.'
                        );
                        $form[0].reset();
                    } else {
                        showErrorNotification(response.message || 'Failed to submit quotation request. Please try again.');
                    }
                },
                error: function(xhr) {
                    const errorMsg = xhr.responseJSON?.message || 'An error occurred. Please try again.';
                    showErrorNotification(errorMsg);
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).html(originalBtnText);
                }
            });
        });

    })();
</script>
@endpush
@endonce
