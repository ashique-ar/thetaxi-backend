@push('scripts')
    <script>
        $(document).ready(function() {
            // Get PHP variables from blade
            const currencySymbol = '{{ $currencySymbol }}';
            let checkoutTotal = {{ $total }};
            const pickupDepositTotal = {{ (float) collect($cart)->sum(fn ($item) => (float) ($item['refundable_deposit'] ?? 0)) }};
            const advancePercentage = {{ $advancePercentage }};
            const advanceMinAmount = {{ $advanceMinAmount }};
            const paymentTermsGroups = $('.payment-terms-group');

            function calculateAdvanceAmount(amount) {
                const normalizedTotal = Math.max(0, Number(amount) || 0);
                const percentageAmount = normalizedTotal * (advancePercentage / 100);
                return Math.min(normalizedTotal, Math.max(percentageAmount, advanceMinAmount));
            }

            function formatAdvancePercentage(amount) {
                const normalizedTotal = Math.max(0, Number(amount) || 0);
                if (!normalizedTotal) return '0';
                return String(Number(((calculateAdvanceAmount(normalizedTotal) / normalizedTotal) * 100).toFixed(2)));
            }

            // Initialize Select2 for country dropdown
            $('#country-select').select2({
                placeholder: 'Select Country',
                allowClear: true,
                width: '100%'
            });

            function updatePaymentTerms(type) {
                if (!paymentTermsGroups.length && $('[data-payment-types]').length === 0) {
                    return;
                }

                // Hide/Show legacy payment-specific groups
                if (paymentTermsGroups.length) {
                    paymentTermsGroups.hide();
                    const match = paymentTermsGroups.filter(`[data-payment-type="${type}"]`);
                    if (match.length) {
                        match.show();
                    }
                }

                // Additionally, show/hide individual term items that declare payment-type applicability
                $('[data-payment-types]').each(function() {
                    const $el = $(this);
                    const allowed = ($el.data('paymentTypes') || '').toString();
                    if (!allowed || allowed === '') {
                        // No payment-type restriction; always visible
                        $el.show();
                        return;
                    }
                    const list = allowed.split(',').map(x => x.trim()).filter(Boolean);
                    if (list.indexOf(type) !== -1) {
                        $el.show();
                    } else {
                        $el.hide();
                    }
                });
            }

            // Payment type selection handling
            $('input[name="payment_type"]').on('change', function() {
                const paymentType = $(this).val();
                const alertContent = $('#alert-content');
                const paymentMethodSection = $('.choose-payment-method');
                const submitBtn = $('#checkout-submit-btn span');
                const tourOnlyCheckout = @json($tourOnlyCheckout);

                // Calculate payment amounts
                const advanceAmount = calculateAdvanceAmount(checkoutTotal);
                const fullAmount = checkoutTotal;

                // Update the alert content based on payment type
                switch (paymentType) {
                    case 'advance': {
                        const actualAdvancePercentage = formatAdvancePercentage(checkoutTotal);
                        alertContent.html(`
                    <h6><i class="bi bi-info-circle"></i> Advance Payment (${actualAdvancePercentage}%)</h6>
                    <p class="mb-0">${tourOnlyCheckout ? `You are paying ${actualAdvancePercentage}% advance. The remaining amount is due according to your Tour booking terms.` : `You are paying ${actualAdvancePercentage}% advance. The remaining amount will be collected at the time of vehicle pickup.`}</p>
                `);
                        $('[data-advance-percentage]').text(actualAdvancePercentage);
                        paymentMethodSection.show();
                        submitBtn.html(
                            `Complete Booking - ${currencySymbol}${Math.floor(Math.max(0, advanceAmount)).toFixed(0)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                    }
                    case 'quotation':
                        alertContent.html(`
                    <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                    <p class="mb-0">You are requesting a quotation. Our team will contact you with detailed pricing and booking information.</p>
                `);
                        paymentMethodSection.hide();
                        submitBtn.html(
                            'Submit Quotation Request <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>'
                        );
                        break;
                    case 'checkin':
                        alertContent.html(`
                    <h6><i class="bi bi-cash-coin"></i> ${tourOnlyCheckout ? 'Pay later' : 'Pay on Check-in'}</h6>
                    <p class="mb-0">${tourOnlyCheckout ? 'No payment is required now. The full amount is due according to your Tour booking terms.' : 'No payment is required now. You will pay the full amount when you check-in to collect the vehicle.'}</p>
                `);
                        paymentMethodSection.hide();
                        submitBtn.html(
                            `Confirm Booking - ${tourOnlyCheckout ? 'Pay Later' : 'Pay on Check-in'} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                    default:
                        alertContent.html(`
                    <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                    <p class="mb-0">You are making full payment for your booking.</p>
                `);
                        paymentMethodSection.show();
                        submitBtn.html(
                            `Complete Booking - ${currencySymbol}${Math.floor(Math.max(0, fullAmount)).toFixed(0)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                }
                updatePaymentTerms(paymentType);
            });


            function restoreCheckoutInteractiveState() {
                $('#checkout-submit-btn').prop('disabled', false);
                $('input[name="payment_type"]:checked').trigger('change');
            }

            // Apply the selected payment type on first render and whenever the
            // browser restores this page from its back-forward cache.
            restoreCheckoutInteractiveState();
            window.addEventListener('pageshow', restoreCheckoutInteractiveState);

            // Form submission - disable submit button to prevent double submission
            $('#checkout-form').on('submit', function(e) {
                syncAcceptedTerms();
                const $termsPanel = $('#termsAcceptancePanel');
                const $acceptTerms = $('#accept_all_terms');
                if ($acceptTerms.length && !$acceptTerms.is(':checked')) {
                    e.preventDefault();
                    $termsPanel.addClass('terms-missing').removeClass('terms-accepted');
                    $acceptTerms.trigger('focus');
                    $('html, body').animate({
                        scrollTop: Math.max($termsPanel.offset().top - 110, 0)
                    }, 250);
                    return false;
                }

                // Payment method is now set automatically, server validates
                $('#checkout-submit-btn').prop('disabled', true).html('<span>Processing...</span>');
            });

            // Terms acceptance sync - single checkbox controls hidden per-term inputs expected by server
            function syncAcceptedTerms() {
                const $container = $('#termsAcceptedHiddenInputs').empty();
                const checked = $('#accept_all_terms').is(':checked');
                if (!checked) return;
                // find visible term items and create hidden inputs
                $('[data-term-id]').each(function() {
                    const $item = $(this);
                    if ($item.is(':visible')) {
                        const id = $item.data('termId');
                        const version = $item.data('termVersion');
                        if (id) {
                            const input = $('<input>').attr({
                                type: 'hidden',
                                name: `terms_accepted[${id}]`,
                                value: version || '1',
                                id: `terms_accepted_${id}`
                            });
                            $container.append(input);

                            // Collapse the term body to keep UI compact
                            const $body = $item.find('.term-body');
                            if ($body.is(':visible')) {
                                $body.slideUp(150);
                                $item.find('.term-toggle').text('Show').attr('aria-expanded', 'false');
                            }
                        }
                    }
                });
            }

            $('#accept_all_terms').on('change', function() {
                $('#termsAcceptancePanel')
                    .toggleClass('terms-accepted', $(this).is(':checked'))
                    .removeClass('terms-missing');
                syncAcceptedTerms();
                // Collapse or expand visible terms depending on checked state
                if ($(this).is(':checked')) {
                    $('[data-term-id]:visible').each(function() {
                        const $item = $(this);
                        const $body = $item.find('.term-body');
                        if ($body.is(':visible')) {
                            $body.slideUp(150);
                        }
                        $item.find('.term-toggle').text('Show').attr('aria-expanded', 'false');
                    });
                } else {
                    $('[data-term-id]:visible').each(function() {
                        const $item = $(this);
                        const $body = $item.find('.term-body');
                        if ($body.is(':hidden')) {
                            $body.slideDown(150);
                        }
                        $item.find('.term-toggle').text('Hide').attr('aria-expanded', 'true');
                    });
                }
            });

            // Re-sync when payment type changes (terms visibility updated elsewhere)
            $('input[name="payment_type"]').on('change', function() {
                if ($('#accept_all_terms').is(':checked')) {
                    // small debounce
                    setTimeout(syncAcceptedTerms, 50);
                }
            });

            // Term toggle handlers (allow clicking the header or button to show/hide body)
            $(document).on('click', '.term-toggle', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const $item = $btn.closest('.term-item');
                const $body = $item.find('.term-body');
                if ($body.is(':visible')) {
                    $body.slideUp(150);
                    $btn.text('Show').attr('aria-expanded', 'false');
                } else {
                    $body.slideDown(150);
                    $btn.text('Hide').attr('aria-expanded', 'true');
                }
            });

            $(document).on('click', '.term-header', function(e) {
                if ($(e.target).closest('.term-toggle').length) return; // avoid double handling
                const $item = $(this).closest('.term-item');
                $item.find('.term-toggle').trigger('click');
            });

            // On page load ensure state if old inputs were present
            if ($('#accept_all_terms').length) {
                // if server-side old inputs indicate prior acceptance, check global box
                const hasPreviouslyAccepted = Object.keys(@json(old('terms_accepted', []))).length > 0;
                if (hasPreviouslyAccepted) {
                    $('#accept_all_terms').prop('checked', true);
                    $('#termsAcceptancePanel').addClass('terms-accepted');
                    syncAcceptedTerms();
                }
            }
            // ==========================================

            function checkoutRedirectHome() {
                window.location.href = '{{ route('home') }}';
            }

            function formatCheckoutAmount(value) {
                const amount = Math.max(0, Number(value) || 0);
                const currency = '{{ $displayCurrency }}';
                const fractionDigits = new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: currency
                }).resolvedOptions().maximumFractionDigits;
                return '{{ $currencySymbol }} ' + amount.toLocaleString('en-US', {
                    minimumFractionDigits: currency === 'LKR' ? 0 : Math.min(2, fractionDigits),
                    maximumFractionDigits: fractionDigits
                });
            }

            function updateCheckoutCartTotals(cart) {
                if (!cart || !cart.totals) {
                    return;
                }

                const totals = cart.totals;
                checkoutTotal = Math.max(0, Number(totals.total) || 0);
                $('[data-summary-field="pickup_total"]').text(formatCheckoutAmount(checkoutTotal + pickupDepositTotal));
                const normalFields = [
                    'subtotal',
                    'addon_charges',
                    'extra_km_charges',
                    'service_fee',
                    'tax',
                    'vat',
                    'total'
                ];
                const discountFields = ['coupon_discount'];

                normalFields.forEach(function(field) {
                    const value = Number(totals[field] || 0);
                    $('[data-summary-field="' + field + '"]').text(formatCheckoutAmount(value));
                    $('[data-summary-row="' + field + '"]').toggle(value > 0);
                });

                discountFields.forEach(function(field) {
                    const value = Number(totals[field] || 0);
                    $('[data-summary-field="' + field + '"]').text('-' + formatCheckoutAmount(value));
                    $('[data-summary-row="' + field + '"]').toggle(value > 0);
                });

                const paymentType = $('input[name="payment_type"]:checked').val() || '{{ $paymentType }}';
                if (paymentType === 'advance') {
                    const advanceAmount = calculateAdvanceAmount(totals.total);
                    $('[data-summary-field="payment_amount"]').text(formatCheckoutAmount(advanceAmount));
                    $('[data-advance-payment-amount]').text(Math.floor(advanceAmount).toFixed(0));
                    $('[data-advance-percentage]').text(formatAdvancePercentage(totals.total));
                } else if (paymentType === 'checkin') {
                    $('[data-summary-field="payment_amount"]').text(formatCheckoutAmount(totals.total));
                }

                // Promo/charge changes can happen without changing payment type.
                // Re-run the selected type handler so the submit button uses
                // the latest discounted total instead of its initial amount.
                const $selectedPaymentType = $('input[name="payment_type"]:checked');
                if ($selectedPaymentType.length) {
                    $selectedPaymentType.trigger('change');
                }

                const itemCount = Number(cart.item_count || Object.keys(cart.items || {}).length);
                if (cart.is_empty || itemCount === 0) {
                    checkoutRedirectHome();
                }
            }

            function checkoutEscapeHtml(value) {
                return $('<div>').text(value || '').html();
            }

            function checkoutAddonAmount(addon) {
                if (addon.rate_type === 'percentage' && addon.calculated_amount == null) {
                    return Number(addon.amount || 0).toLocaleString('en-US') + '%';
                }
                return formatCheckoutAmount(addon.calculated_amount ?? addon.amount ?? 0);
            }

            function selectedAddonMap(selectedAddons) {
                const map = {};
                if (Array.isArray(selectedAddons)) {
                    selectedAddons.forEach(function(addon) {
                        map[addon.addon_id] = Number(addon.qty || 0);
                    });
                }
                return map;
            }

            function updateCheckoutAddonCount(cartKey, selectedCount) {
                $('.checkout-item-addons[data-cart-key="' + cartKey + '"] .checkout-addon-count').text(selectedCount);
            }

            function renderSelectedAddonSummary(cartKey, selectedAddons) {
                const summary = $('.checkout-item-addons[data-cart-key="' + cartKey + '"] .checkout-selected-addons');
                if (!summary.length) {
                    return;
                }

                const selected = Array.isArray(selectedAddons) ? selectedAddons.filter(function(addon) {
                    return Number(addon.qty || 0) > 0;
                }) : [];

                updateCheckoutAddonCount(cartKey, selected.length);
                if (!selected.length) {
                    summary.html('<div class="checkout-no-addons">No add-ons selected</div>');
                    return;
                }

                summary.html(selected.map(function(addon) {
                    return `
                        <div class="checkout-selected-addon-row" data-addon-id="${checkoutEscapeHtml(addon.addon_id || addon.id)}">
                            <div>
                                <strong>${checkoutEscapeHtml(addon.name || 'Add-on')}</strong>
                                <small>Qty: ${Number(addon.qty || 1)}</small>
                            </div>
                            <span>${checkoutAddonAmount(addon)}</span>
                        </div>
                    `;
                }).join(''));
            }

            function loadCheckoutAddons(cartKey, serviceType, forceReload) {
                const panel = $('#checkoutAddonModalPanel');
                if (!panel.length) {
                    return;
                }
                if (panel.data('loaded') && panel.data('cart-key') === cartKey && !forceReload) {
                    return;
                }

                panel.data('cart-key', cartKey).data('service-type', serviceType || '');
                panel.html('<div class="checkout-addon-loading">Loading add-ons...</div>');

                $.when(
                    $.ajax({
                        url: '{{ route('cart.addons.available') }}',
                        method: 'GET',
                        data: {
                            service_type: serviceType || '',
                            cart_key: cartKey
                        }
                    }),
                    $.ajax({
                        url: '{{ route('cart.addons.get', ['cartKey' => ':cartKey']) }}'.replace(':cartKey', encodeURIComponent(cartKey)),
                        method: 'GET'
                    })
                ).done(function(availableResponse, selectedResponse) {
                    const available = availableResponse[0]?.data || [];
                    const selected = selectedResponse[0]?.data || [];
                    renderCheckoutAddons(cartKey, available, selected);
                    renderSelectedAddonSummary(cartKey, selected);
                    panel.data('loaded', true);
                }).fail(function() {
                    panel.html(`
                        <div class="checkout-addon-error">
                            Unable to load add-ons.
                            <button type="button" class="checkout-option-retry checkout-addon-load-retry"
                                data-cart-key="${checkoutEscapeHtml(cartKey)}"
                                data-service-type="${checkoutEscapeHtml(serviceType)}">Retry</button>
                        </div>
                    `);
                });
            }

            function loadCheckoutAddonAvailability(wrapper) {
                const serviceType = wrapper.data('service-type') || '';
                const cartKey = wrapper.data('cart-key');
                const selectedCount = Number(wrapper.find('.checkout-addon-count').text() || 0);

                wrapper.find('.checkout-option-availability-error').remove();
                $.ajax({
                    url: '{{ route('cart.addons.available') }}',
                    method: 'GET',
                    data: {
                        service_type: serviceType,
                        cart_key: cartKey
                    },
                    success: function(response) {
                        const hasAvailableAddons = response.success && Array.isArray(response.data) && response.data.length > 0;
                        wrapper.toggle(hasAvailableAddons || selectedCount > 0)
                            .removeClass('checkout-option-pending');
                    },
                    error: function() {
                        // Preserve selected add-ons and keep recovery available when
                        // eligibility could not be verified.
                        wrapper.show()
                            .removeClass('checkout-option-pending')
                            .append(`
                                <div class="checkout-option-availability-error">
                                    Add-on availability could not be checked.
                                    <button type="button" class="checkout-option-retry checkout-addon-availability-retry">
                                        Retry
                                    </button>
                                </div>
                            `);
                    }
                });
            }

            function initializeCheckoutAddonAvailability() {
                $('.checkout-item-addons').each(function() {
                    loadCheckoutAddonAvailability($(this));
                });
            }

            function renderCheckoutAddons(cartKey, addons, selectedAddons) {
                const panel = $('#checkoutAddonModalPanel');
                const selected = selectedAddonMap(selectedAddons);
                const safeCartKey = checkoutEscapeHtml(cartKey).replace(/"/g, '&quot;').replace(/'/g, '&#39;');

                if (!Array.isArray(addons) || addons.length === 0) {
                    panel.html('<div class="checkout-addon-empty">No add-ons available for this item.</div>');
                    renderSelectedAddonSummary(cartKey, selectedAddons);
                    return;
                }

                const html = addons.map(function(addon) {
                    const qty = Number(selected[addon.id] || 0);
                    const configuredMax = Number(addon.max_qty);
                    const maxQty = Number.isInteger(configuredMax) && configuredMax > 0 ? configuredMax : 999;
                    const safeId = checkoutEscapeHtml(addon.id).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    const safeName = checkoutEscapeHtml(addon.name || 'Add-on');
                    const safeNameAttr = safeName.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    const icon = addon.is_child_seat === true ? 'bi-shield-check' : 'bi-stars';
                    return `
                        <div class="checkout-addon-card ${qty > 0 ? 'is-selected' : ''}" data-addon-id="${safeId}">
                            ${qty > 0 ? '<span class="v2-addon-active">Selected</span>' : ''}
                            <div class="v2-addon-icon" aria-hidden="true"><i class="bi ${icon}"></i></div>
                            <div class="checkout-addon-info">
                                <strong>${safeName}</strong>
                                <small>${checkoutEscapeHtml(addon.description || 'Add-on')}</small>
                                <span class="v2-addon-price">${checkoutAddonAmount(addon)}</span>
                            </div>
                            <div class="checkout-addon-actions">
                                <div class="checkout-addon-qty" ${qty > 0 ? '' : 'hidden'}>
                                    <button type="button" class="v2-addon-step" data-step="-1" aria-label="Decrease ${safeNameAttr} quantity">−</button>
                                    <input type="number" min="0" max="${maxQty}" step="1" value="${qty}" data-selected-qty="${qty}"
                                        data-addon-id="${safeId}" data-cart-key="${safeCartKey}" aria-label="${safeNameAttr} quantity">
                                    <button type="button" class="v2-addon-step" data-step="1" aria-label="Increase ${safeNameAttr} quantity">+</button>
                                </div>
                                <button type="button" class="checkout-addon-apply" data-addon-id="${safeId}" data-cart-key="${safeCartKey}">${qty > 0 ? 'Update' : '+ Add'}</button>
                                ${qty > 0 ? `<button type="button" class="checkout-addon-remove" data-addon-id="${safeId}" data-cart-key="${safeCartKey}">Remove</button>` : ''}
                            </div>
                        </div>`;
                }).join('');

                panel.html(html + `
                    <div class="checkout-addon-bulk">
                        <button type="button" class="checkout-addon-update-all" data-cart-key="${safeCartKey}">Apply all add-on quantities</button>
                    </div>`);
                renderSelectedAddonSummary(cartKey, selectedAddons);
            }

            function checkoutAddonQuantity(input) {
                const qty = Number(input.value);
                if (input.value === '' || !Number.isInteger(qty) || qty < 0 || qty > Number(input.max)) {
                    input.setCustomValidity('Enter a whole number between 0 and ' + input.max + '.');
                    input.reportValidity();
                    return null;
                }
                input.setCustomValidity('');
                return qty;
            }

            $(document).on('input', '.checkout-addon-qty input', function() {
                this.setCustomValidity('');
            });

            $(document).on('click', '.v2-addon-step', function() {
                const input = $(this).closest('.checkout-addon-qty').find('input')[0];
                const next = Math.max(0, Math.min(Number(input.max), (Number(input.value) || 0) + Number($(this).data('step'))));
                input.value = next;
                input.setCustomValidity('');
            });

            function mutateCheckoutAddon(url, data, button) {
                button.prop('disabled', true);
                $('#checkoutAddonModalStatus').prop('hidden', true).text('');

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: Object.assign({
                        _token: '{{ csrf_token() }}'
                    }, data),
                    success: function(response) {
                        if (!response.success) {
                            $('#checkoutAddonModalStatus').text(response.message || 'Unable to update add-on').prop('hidden', false);
                            button.prop('disabled', false);
                            if (response.cart) {
                                updateCheckoutCartTotals(response.cart);
                                loadCheckoutAddons(data.cart_key, $('#checkoutAddonModalPanel').data('service-type'), true);
                            }
                            return;
                        }

                        updateCheckoutCartTotals(response.cart);
                        const panel = $('#checkoutAddonModalPanel');
                        loadCheckoutAddons(data.cart_key, panel.data('service-type'), true);
                    },
                    error: function(xhr) {
                        $('#checkoutAddonModalStatus').text(xhr.responseJSON?.message || 'Unable to update add-on').prop('hidden', false);
                        button.prop('disabled', false);
                    }
                });
            }

            $(document).on('click', '.checkout-toggle-addons', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const serviceType = button.data('service-type');
                $('.checkout-addon-modal-vehicle').text(button.data('vehicle-name') || 'Selected vehicle');
                $('#checkoutAddonModalStatus').prop('hidden', true).text('');
                // Keep the dialog above Bootstrap's backdrop outside isolated theme wrappers.
                $('#checkout-v2-overlays').appendTo(document.body);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('checkoutAddonModal')).show();
                loadCheckoutAddons(cartKey, serviceType, false);
            });

            $(document).on('click', '.checkout-addon-load-retry', function() {
                loadCheckoutAddons($(this).data('cart-key'), $(this).data('service-type'), true);
            });

            $(document).on('click', '.checkout-addon-availability-retry', function() {
                loadCheckoutAddonAvailability($(this).closest('.checkout-item-addons'));
            });

            $(document).on('click', '.checkout-addon-apply', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const addonId = button.data('addon-id');
                const input = $('.checkout-addon-qty input[data-cart-key="' + cartKey + '"][data-addon-id="' + addonId + '"]');
                const selectedQty = Number(input.data('selected-qty') || 0);
                if (!selectedQty && Number(input.val()) === 0) input.val(1);
                const qty = checkoutAddonQuantity(input[0]);
                if (qty === null) return;

                if (qty <= 0) {
                    mutateCheckoutAddon('{{ route('cart.addon.remove') }}', {
                        cart_key: cartKey,
                        addon_id: addonId
                    }, button);
                    return;
                }

                mutateCheckoutAddon(selectedQty > 0 ? '{{ route('cart.addon.update-qty') }}' : '{{ route('cart.addon.add') }}', {
                    cart_key: cartKey,
                    addon_id: addonId,
                    qty: qty
                }, button);
            });

            $(document).on('click', '.checkout-addon-remove', function() {
                const button = $(this);
                mutateCheckoutAddon('{{ route('cart.addon.remove') }}', {
                    cart_key: button.data('cart-key'),
                    addon_id: button.data('addon-id')
                }, button);
            });

            $(document).on('click', '.checkout-addon-update-all', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const inputs = $('.checkout-addon-qty input[data-cart-key="' + cartKey + '"]');
                let invalid = false;
                const updates = inputs.map(function() {
                    const qty = checkoutAddonQuantity(this);
                    if (qty === null) { invalid = true; return null; }
                    return { cart_key: cartKey, addon_id: $(this).data('addon-id'), qty: qty };
                }).get();

                if (invalid || !updates.length) {
                    return;
                }

                mutateCheckoutAddon('{{ route('cart.addons.update-all') }}', {
                    cart_key: cartKey,
                    updates: updates
                }, button);
            });

            function loadCheckoutExtraKm(cartKey, forceReload) {
                const panel = $('.checkout-extra-km-panel[data-cart-key="' + cartKey + '"]');
                const wrapper = $('.checkout-item-extra-km[data-cart-key="' + cartKey + '"]');
                if (!panel.length) {
                    return;
                }
                if (panel.data('loaded') && !forceReload) {
                    return;
                }

                panel.html('<div class="checkout-extra-km-loading">Loading extra KM options...</div>');

                $.ajax({
                    url: '{{ url('/cart/extra-km') }}/' + encodeURIComponent(cartKey),
                    method: 'GET',
                    success: function(response) {
                        if (!response.success || !response.data || !response.data.has_slab || !response.data.rate) {
                            wrapper.hide().removeClass('checkout-option-pending');
                            return;
                        }

                        wrapper.removeClass('checkout-option-pending').show();
                        renderCheckoutExtraKm(cartKey, response.data);
                        panel.data('loaded', true);
                    },
                    error: function() {
                        wrapper.show().removeClass('checkout-option-pending');
                        panel.html(`
                            <div class="checkout-extra-km-error">
                                Unable to load extra KM options.
                                <button type="button" class="checkout-option-retry checkout-extra-km-load-retry"
                                    data-cart-key="${checkoutEscapeHtml(cartKey)}">Retry</button>
                            </div>
                        `);
                    }
                });
            }

            function renderCheckoutExtraKm(cartKey, data) {
                const panel = $('.checkout-extra-km-panel[data-cart-key="' + cartKey + '"]');
                const rate = Number(data.rate?.rate || 0);
                const currentKm = Number(data.current_extra_km?.km || 0);
                const safeCartKey = checkoutEscapeHtml(cartKey).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                const presets = [50, 150, 300, 500];
                panel.data('rate', rate);
                panel.html(`
                    <div class="v2-km-rate">Rate: <strong>${formatCheckoutAmount(rate)}</strong> per km</div>
                    <div class="v2-km-title">Popular kilometer bundles</div>
                    <div class="v2-km-presets">${presets.map(km => `
                        <button type="button" class="v2-km-preset" data-km="${km}" aria-pressed="${currentKm === km}">
                            <strong>+${km} km</strong><small>${formatCheckoutAmount(km * rate)}</small>
                        </button>`).join('')}
                    </div>
                    <div class="v2-km-custom">
                        <label for="checkout-extra-km-${safeCartKey}">Custom Extra Distance <span>Unit: Kilometers</span></label>
                        <input type="number" id="checkout-extra-km-${safeCartKey}" class="checkout-extra-km-value"
                            min="0" max="10000" step="1" value="${currentKm}" data-cart-key="${safeCartKey}">
                        <input type="range" class="v2-km-slider" min="0" max="${Math.max(600, currentKm)}" step="1" value="${currentKm}" aria-label="Extra kilometers">
                        <div class="v2-km-slider-labels"><span>0 km</span><span class="v2-km-slider-max">${Math.max(600, currentKm)} km</span></div>
                    </div>
                    <div class="v2-km-breakdown"><div><span>Base rate per km</span><strong>${formatCheckoutAmount(rate)}</strong></div>
                        <div><span>Calculation</span><span><span class="v2-km-count">${currentKm}</span> km × ${formatCheckoutAmount(rate)}</span></div>
                        <div><strong>Subtotal extra mileage</strong><strong class="checkout-extra-km-total-value">${formatCheckoutAmount(currentKm * rate)}</strong></div>
                    </div>
                    <div class="checkout-extra-km-actions">
                        <button type="button" class="checkout-extra-km-apply" data-cart-key="${safeCartKey}">Apply Extra KM</button>
                        ${currentKm > 0 ? `<button type="button" class="checkout-extra-km-remove" data-cart-key="${safeCartKey}">Remove</button>` : ''}
                    </div>
                `);
                $('.checkout-item-extra-km[data-cart-key="' + cartKey + '"] .checkout-extra-km-count').text(currentKm.toLocaleString('en-US'));
            }

            function mutateCheckoutExtraKm(url, data, button) {
                button.prop('disabled', true);

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: Object.assign({
                        _token: '{{ csrf_token() }}'
                    }, data),
                    success: function(response) {
                        if (!response.success) {
                            showCheckoutPromoError(response.message || 'Unable to update extra KM');
                            button.prop('disabled', false);
                            return;
                        }

                        updateCheckoutCartTotals(response.cart);
                        loadCheckoutExtraKm(data.cart_key, true);
                    },
                    error: function(xhr) {
                        showCheckoutPromoError(xhr.responseJSON?.message || 'Unable to update extra KM');
                        button.prop('disabled', false);
                    }
                });
            }

            $(document).on('click', '.checkout-toggle-extra-km', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const panel = $('.checkout-extra-km-panel[data-cart-key="' + cartKey + '"]');
                $('#checkoutExtraKmModalPanel').append(panel.show());
                $('.checkout-extra-km-modal-vehicle').text(button.data('vehicle-name') || 'Selected vehicle');
                $('#checkout-v2-overlays').appendTo(document.body);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('checkoutExtraKmModal')).show();
                loadCheckoutExtraKm(cartKey, false);
            });

            $('#checkoutExtraKmModal').on('hidden.bs.modal', function() {
                const panel = $(this).find('.checkout-extra-km-panel');
                const cartKey = panel.data('cart-key');
                $('.checkout-item-extra-km[data-cart-key="' + cartKey + '"]').append(panel.hide());
            });

            $(document).on('click', '.checkout-extra-km-load-retry', function() {
                loadCheckoutExtraKm($(this).data('cart-key'), true);
            });

            function updateExtraKmPreview(panel, value) {
                const km = Math.max(0, Math.min(10000, Number(value) || 0));
                const slider = panel.find('.v2-km-slider');
                const max = Math.max(600, km);
                slider.attr('max', max).val(km);
                panel.find('.v2-km-slider-max').text(max.toLocaleString('en-US') + ' km');
                panel.find('.v2-km-count').text(km.toLocaleString('en-US'));
                panel.find('.checkout-extra-km-total-value').text(formatCheckoutAmount(km * Number(panel.data('rate') || 0)));
                panel.find('.v2-km-preset').each(function() {
                    $(this).attr('aria-pressed', Number($(this).data('km')) === km ? 'true' : 'false');
                });
            }

            $(document).on('input', '.checkout-extra-km-value', function() {
                this.setCustomValidity('');
                updateExtraKmPreview($(this).closest('.checkout-extra-km-panel'), this.value);
            });

            $(document).on('input', '.v2-km-slider', function() {
                const panel = $(this).closest('.checkout-extra-km-panel');
                panel.find('.checkout-extra-km-value').val(this.value).trigger('input');
            });

            $(document).on('click', '.v2-km-preset', function() {
                const panel = $(this).closest('.checkout-extra-km-panel');
                panel.find('.checkout-extra-km-value').val($(this).data('km')).trigger('input');
            });

            $(document).on('click', '.checkout-extra-km-apply', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const input = $('.checkout-extra-km-value[data-cart-key="' + cartKey + '"]')[0];
                const km = Number(input.value);
                if (!input.value || !Number.isInteger(km) || km < 0 || km > 10000) {
                    input.setCustomValidity('Enter a whole number between 0 and 10,000.');
                    input.reportValidity();
                    return;
                }
                input.setCustomValidity('');

                mutateCheckoutExtraKm('{{ route('cart.extra-km.add') }}', {
                    cart_key: cartKey,
                    extra_km: km
                }, button);
            });

            $(document).on('click', '.checkout-extra-km-remove', function() {
                const button = $(this);
                mutateCheckoutExtraKm('{{ route('cart.extra-km.remove') }}', {
                    cart_key: button.data('cart-key')
                }, button);
            });

            function initializeCheckoutOptionalServices() {
                initializeCheckoutAddonAvailability();

                $('.checkout-item-extra-km').each(function() {
                    loadCheckoutExtraKm($(this).data('cart-key'), false);
                });
            }

            initializeCheckoutOptionalServices();

            function setCheckoutItemExpanded(item, expanded) {
                item.toggleClass('is-expanded', expanded).toggleClass('is-collapsed', !expanded);
                item.find('> .checkout-item-toolbar > .checkout-item-toggle')
                    .attr('aria-expanded', expanded ? 'true' : 'false')
                    .attr('title', expanded ? 'Hide booking details' : 'Show booking details')
                    .find('.checkout-item-toggle-label')
                    .text(expanded ? 'Less' : 'Details');
            }

            $(document).on('click', '.checkout-item-toggle', function() {
                const item = $(this).closest('.checkout-cart-item');
                const willExpand = !item.hasClass('is-expanded');

                if (willExpand) {
                    $('.checkout-cart-item.is-expanded').not(item).each(function() {
                        setCheckoutItemExpanded($(this), false);
                    });
                }

                setCheckoutItemExpanded(item, willExpand);
            });

            $(document).on('click', '.checkout-remove-item-btn', function() {
                if (!confirm('Remove this item from checkout?')) {
                    return;
                }

                const btn = $(this);
                const cartKey = btn.data('cart-key');
                btn.prop('disabled', true);
                btn.html('<i class="bi bi-hourglass-split"></i><span>Removing</span>');

                $.ajax({
                    url: '{{ route('cart.remove') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey
                    },
                    success: function(response) {
                        if (!response.success) {
                            showCheckoutPromoError(response.message || 'Error removing item');
                            btn.prop('disabled', false);
                            btn.html('<i class="bi bi-trash"></i><span>Remove</span>');
                            return;
                        }

                        $('.checkout-cart-item[data-cart-key="' + cartKey + '"]').slideUp(150, function() {
                            const removedExpandedItem = $(this).hasClass('is-expanded');
                            $(this).remove();
                            if (removedExpandedItem && !$('.checkout-cart-item.is-expanded').length) {
                                setCheckoutItemExpanded($('.checkout-cart-item').first(), true);
                            }
                            updateCheckoutCartTotals(response.cart);
                        });
                    },
                    error: function(xhr) {
                        showCheckoutPromoError(xhr.responseJSON?.message || 'Error removing item');
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-trash"></i><span>Remove</span>');
                    }
                });
            });

            $(document).on('click', '.checkout-clear-cart-btn', function() {
                if (!confirm('Clear your entire cart?')) {
                    return;
                }

                const btn = $(this);
                btn.prop('disabled', true);
                btn.html('<i class="bi bi-hourglass-split"></i><span>Clearing</span>');

                $.ajax({
                    url: '{{ route('cart.clear') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.success) {
                            checkoutRedirectHome();
                            return;
                        }

                        showCheckoutPromoError(response.message || 'Error clearing cart');
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-trash"></i><span>Clear</span>');
                    },
                    error: function(xhr) {
                        showCheckoutPromoError(xhr.responseJSON?.message || 'Error clearing cart');
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-trash"></i><span>Clear</span>');
                    }
                });
            });

            // Apply promo code button click
            $(document).on('click', '#apply-promo-checkout-btn', function() {
                const promoCode = $('#checkout-promo-input').val().trim();
                if (!promoCode) {
                    showCheckoutPromoError('Please enter a promo code');
                    return;
                }

                // Show loading state
                const btn = $(this);
                btn.prop('disabled', true);
                btn.find('.btn-text').hide();
                btn.find('.btn-loading').show();
                clearCheckoutPromoMessage();

                $.ajax({
                    url: '{{ route('cart.apply-promo-code') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        promo_code: promoCode
                    },
                    success: function(response) {
                        if (response.success) {
                            updateCheckoutCartTotals(response.cart);
                            const appliedPromo = response.promo_code;
                            const appliedPromoCode = typeof appliedPromo === 'string'
                                ? appliedPromo
                                : appliedPromo?.code;
                            renderCheckoutAppliedPromo(appliedPromoCode || promoCode);
                            showCheckoutPromoSuccess(response.message || 'Promo code applied!');
                        } else {
                            showCheckoutPromoError(response.message || 'Invalid promo code');
                            resetCheckoutApplyButton();
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.message ||
                            'Error applying promo code';
                        showCheckoutPromoError(errorMsg);
                        resetCheckoutApplyButton();
                    }
                });
            });

            // Allow Enter key to apply promo code
            $(document).on('keypress', '#checkout-promo-input', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#apply-promo-checkout-btn').click();
                }
            });

            // Remove promo code button click
            $(document).on('click', '.remove-promo-checkout-btn', function() {
                const btn = $(this);
                btn.prop('disabled', true);
                btn.html('<i class="bi bi-hourglass-split"></i>');

                $.ajax({
                    url: '{{ route('cart.remove-promo-code') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateCheckoutCartTotals(response.cart);
                            renderCheckoutPromoInput();
                            showCheckoutPromoSuccess('Promo code removed');
                        } else {
                            showCheckoutPromoError(response.message ||
                                'Error removing promo code');
                            btn.prop('disabled', false);
                            btn.html('<i class="bi bi-x-lg"></i>');
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.message ||
                            'Error removing promo code';
                        showCheckoutPromoError(errorMsg);
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-x-lg"></i>');
                    }
                });
            });

            // Helper functions for checkout promo code UI
            function renderCheckoutAppliedPromo(promoCode) {
                $('#checkout-promo-body').html(
                    '<div class="flex items-center justify-between gap-2 rounded-lg bg-emerald-50 p-3 text-emerald-700">' +
                    '<span class="promo-code-value">' + checkoutEscapeHtml(promoCode) + '</span>' +
                    '<button type="button" class="remove-promo-checkout-btn font-semibold" aria-label="Remove promo code">' +
                    'Remove' +
                    '</button>' +
                    '</div>'
                );
            }

            function renderCheckoutPromoInput() {
                $('#checkout-promo-body').html(
                    '<div class="flex gap-2">' +
                    '<input type="text" id="checkout-promo-input" placeholder="Enter code" autocomplete="off" class="min-w-0 flex-1 rounded-lg border-slate-200 px-3 py-2 text-xs">' +
                    '<button type="button" id="apply-promo-checkout-btn" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white">' +
                    '<span class="btn-text">Apply</span>' +
                    '<span class="btn-loading" style="display: none;">Applying…</span>' +
                    '</button>' +
                    '</div>'
                );
            }

            function showCheckoutPromoError(message) {
                $('#checkout-promo-message').html(
                    '<div class="rounded-lg bg-red-50 p-2 text-xs text-red-700">' +
                    checkoutEscapeHtml(message) + '</div>'
                );
            }

            function showCheckoutPromoSuccess(message) {
                $('#checkout-promo-message').html(
                    '<div class="rounded-lg bg-emerald-50 p-2 text-xs text-emerald-700">' +
                    checkoutEscapeHtml(message) + '</div>'
                );
            }

            function clearCheckoutPromoMessage() {
                $('#checkout-promo-message').empty();
            }

            function resetCheckoutApplyButton() {
                const btn = $('#apply-promo-checkout-btn');
                btn.prop('disabled', false);
                btn.find('.btn-text').show();
                btn.find('.btn-loading').hide();
            }
        });
    </script>

    <!-- Intl Tel Input Library -->
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
    <!-- LibPhoneNumber for validation -->
    <script src="https://cdn.jsdelivr.net/npm/libphonenumber-js@1/bundle/libphonenumber-js.min.js"></script>

    <script>
        $(document).ready(function() {
            // Country to calling code mapping - dynamically generated
            const countryCodeMap = {
                @foreach ($countries as $country)
                    '{{ $country->name }}': '{{ strtolower($country->code ?? 'us') }}',
                @endforeach
            };

            // Initialize intl-tel-input
            const phoneInput = document.querySelector('#phone-input');
            const phoneCountrySelect = document.querySelector('#country-select');
            const phoneCountryCodeField = document.querySelector('#phone-country-code');
            const phoneInternationalField = document.querySelector('#phone-international');
            const phoneErrorDiv = document.querySelector('#phone-error');
            const phoneValidDiv = document.querySelector('#phone-valid');
            if (!phoneInput) return;

            // Generate preferred countries from available countries
            const preferredCountryCodes = ['lk', 'in', 'us', 'gb', 'ca', 'au'];
            const availablePreferredCountries = preferredCountryCodes.filter(code =>
                @json($countries->pluck('code')->map(fn($c) => strtolower($c))->toArray()).includes(code.toLowerCase())
            );

            const lookupPhoneInput = document.querySelector('#customer-lookup-mobile');
            if (lookupPhoneInput) {
                window.checkoutLookupIntlTelInput = window.intlTelInput(lookupPhoneInput, {
                    initialCountry: @json($checkoutDefaultCountry),
                    preferredCountries: availablePreferredCountries,
                    separateDialCode: true,
                    formatAsYouType: true,
                    utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
                });
            }

            const iti = window.intlTelInput(phoneInput, {
                initialCountry: @json($checkoutDefaultCountry),
                preferredCountries: availablePreferredCountries,
                separateDialCode: true,
                formatAsYouType: true,
                utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
            });

            // Function to update country and validate
            function validatePhoneNumber() {
                const phoneNumber = phoneInput.value.trim();

                if (!phoneNumber) {
                    phoneErrorDiv.style.display = 'none';
                    phoneValidDiv.style.display = 'none';
                    return false;
                }

                if (!window.intlTelInputUtils) {
                    phoneErrorDiv.textContent = 'Phone validation is loading. Please try again.';
                    phoneErrorDiv.style.display = 'block';
                    phoneValidDiv.style.display = 'none';
                    return false;
                }

                // Check if number is valid
                if (iti.isValidNumber()) {
                    const countryData = iti.getSelectedCountryData();
                    const internationalNumber = iti.getNumber(intlTelInputUtils.numberFormat.INTERNATIONAL);
                    const e164Number = iti.getNumber(intlTelInputUtils.numberFormat.E164);

                    // Store formatted numbers
                    phoneCountryCodeField.value = countryData.dialCode;
                    phoneInternationalField.value = e164Number; // Store E164 international format.

                    phoneErrorDiv.style.display = 'none';
                    phoneValidDiv.textContent = `✓ Valid ${countryData.name} number`;
                    phoneValidDiv.style.display = 'block';

                    return true;
                } else {
                    phoneCountryCodeField.value = '';
                    phoneInternationalField.value = '';

                    const countryData = iti.getSelectedCountryData();
                    const errorMsg = iti.getValidationError();
                    const errorMessages = {
                        0: 'Invalid number',
                        1: 'Too short',
                        2: 'Too long',
                        3: 'Not a number'
                    };

                    phoneErrorDiv.textContent =
                        `✗ ${errorMessages[errorMsg] || 'Invalid phone number for ' + countryData.name}`;
                    phoneErrorDiv.style.display = 'block';
                    phoneValidDiv.style.display = 'none';

                    return false;
                }
            }

            window.checkoutIntlTelInput = iti;
            window.validateCheckoutPhoneNumber = validatePhoneNumber;

            // Update country selection when select dropdown changes
            $(phoneCountrySelect).on('change', function() {
                const selectedCountry = $(this).val();
                const countryCode = countryCodeMap[selectedCountry];

                if (countryCode) {
                    iti.setCountry(countryCode);
                    // Focus and validate
                    setTimeout(() => {
                        validatePhoneNumber();
                    }, 100);
                }
            });

            // Validate on input
            $(phoneInput).on('input change blur', function() {
                validatePhoneNumber();
            });

            document.querySelector('#checkout-form')?.addEventListener('submit', function(e) {
                if (phoneInput.disabled) return;
                if (!validatePhoneNumber()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    phoneInput.focus();
                }
            }, true);

            // Set default country from select
            const defaultCountry = $(phoneCountrySelect).val();
            if (defaultCountry && countryCodeMap[defaultCountry]) {
                const defaultCountryCode = countryCodeMap[defaultCountry];
                iti.setCountry(defaultCountryCode);
            }

            // Restore phone value if it exists (for form re-submission)
            iti.promise.then(() => {
                if (phoneInput.value) validatePhoneNumber();
            });
        });
    </script>

    <!-- Select2 JS for searchable country dropdown -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- Checkout Payment Type Handler -->
    <script src="{{ assetVersion('assets/js/checkout-payment-type.js') }}"></script>
    <script>
        $(document).ready(() => {
            const form = document.getElementById('checkout-form');
            const emailInput = form?.querySelector('input[name="email"]');
            const phoneInput = document.getElementById('phone-input');
            const phoneInternationalInput = document.getElementById('phone-international');
            const lookupEmailInput = document.getElementById('customer-lookup-email');
            const lookupMobileInput = document.getElementById('customer-lookup-mobile');
            const sendCodeButton = document.getElementById('send-customer-code');
            const verifyButton = document.getElementById('verify-customer-email-code');
            const resetButton = document.getElementById('reset-customer-details');
            const codePanel = document.getElementById('customer-email-code-panel');
            const codeInput = document.getElementById('customer-email-code');
            const status = document.getElementById('customer-email-code-status');
            const customerChoice = document.getElementById('checkout-customer-choice');
            const customerKindInputs = document.querySelectorAll('input[name="checkout_customer_kind"]');
            const checkoutPhoneField = document.getElementById('checkout-phone-field');
            const checkoutEmailField = document.getElementById('checkout-email-field');
            const returningPanel = document.getElementById('returning-customer-panel');
            const detailsLayout = document.getElementById('checkout-details-layout');
            const paymentLayout = document.getElementById('checkout-payment-layout');
            const setCheckoutDetailsVisible = visible => {
                if (detailsLayout) detailsLayout.hidden = !visible;
                if (paymentLayout) paymentLayout.hidden = !visible;
            };
            const lookupChannelInputs = document.querySelectorAll('input[name="checkout_lookup_channel"]');
            const lookupEmailField = document.getElementById('customer-lookup-email-field');
            const lookupMobileField = document.getElementById('customer-lookup-mobile-field');
            const lookupMobileError = document.getElementById('customer-lookup-mobile-error');
            const verifiedStatus = document.getElementById('verified-customer-status');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            let activeChannel = 'email';
            let hasVerifiedCustomer = @json((bool) $verifiedCheckoutCustomer);
            const accountContactMethod = @json(Auth::user()?->email ? 'email' : null);
            const accountHasPhone = @json(filled(Auth::user()?->phone));
            const onlinePaymentAvailable = @json($paymentMethods->has('online'));
            const paymentContactRequirement = document.getElementById('payment-contact-requirement');
            const checkoutSubmitButton = document.getElementById('checkout-submit-btn');
            const cooldownUntil = { email: 0, mobile: 0 };
            let cooldownTimer = null;

            if (!form || !emailInput || !phoneInput || !phoneInternationalInput || !resetButton) return;

            const identityValue = channel => channel === 'email'
                ? (lookupEmailInput?.value || '').trim().toLowerCase()
                : window.checkoutLookupIntlTelInput?.getNumber() || '';
            const syncCustomerChoice = () => {
                if (!customerChoice) return;
                const selected = selectedCustomerKind();
                customerKindInputs.forEach(input => {
                    input.closest('label')?.classList.toggle('border-primary', input.checked);
                });
                if (returningPanel) returningPanel.hidden = hasVerifiedCustomer || selected !== 'returning';
                setCheckoutDetailsVisible(hasVerifiedCustomer || selected === 'new');
            };
            const showMessage = (message, success = false) => {
                if (!status) return;
                status.textContent = message;
                status.className = `small mt-1 ${success ? 'text-success' : 'text-muted'}`;
            };
            const showSavedDetailsMessage = (message, isError = false) => {
                if (!verifiedStatus) return;
                verifiedStatus.textContent = message;
                verifiedStatus.className = `small mb-0 ${isError ? 'text-danger' : 'text-success'}`;
                verifiedStatus.hidden = false;
            };
            const showBookingForm = () => {
                hasVerifiedCustomer = true;
                if (returningPanel) returningPanel.hidden = true;
                setCheckoutDetailsVisible(true);
            };
            const selectedCustomerKind = () => document.querySelector('input[name="checkout_customer_kind"]:checked')?.value;
            const syncPaymentContactRequirement = () => {
                const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'full';
                const requiresOnlineContact = onlinePaymentAvailable && ['full', 'advance'].includes(paymentType);
                const hasEmail = Boolean((emailInput.value.trim() && emailInput.checkValidity()) || accountContactMethod);
                const hasPhone = Boolean(window.checkoutIntlTelInput?.isValidNumber() || (accountContactMethod && accountHasPhone));
                const blocked = requiresOnlineContact && !(hasEmail && hasPhone);
                if (paymentContactRequirement) paymentContactRequirement.hidden = !blocked;
                if (checkoutSubmitButton) checkoutSubmitButton.disabled = blocked;
            };
            const syncContactFields = () => {
                if (checkoutPhoneField) checkoutPhoneField.hidden = false;
                if (checkoutEmailField) checkoutEmailField.hidden = false;
                phoneInput.disabled = false;
                emailInput.disabled = false;
                phoneInput.required = true;
                emailInput.required = true;
                phoneInternationalInput.disabled = false;
                const countryCode = document.getElementById('phone-country-code');
                if (countryCode) countryCode.disabled = false;
                syncPaymentContactRequirement();
            };
            const renderCooldown = () => {
                if (!sendCodeButton) return;
                const remaining = Math.max(0, Math.ceil((cooldownUntil[activeChannel] - Date.now()) / 1000));
                sendCodeButton.disabled = remaining > 0;
                sendCodeButton.textContent = remaining > 0
                    ? `Resend code in ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`
                    : 'Send code';
                if (remaining && !cooldownTimer) cooldownTimer = setInterval(renderCooldown, 1000);
                if (!remaining && cooldownTimer) {
                    clearInterval(cooldownTimer);
                    cooldownTimer = null;
                }
            };
            const startCooldown = (channel, seconds) => {
                cooldownUntil[channel] = Date.now() + Math.max(1, Number(seconds) || 60) * 1000;
                renderCooldown();
            };
            const syncLookupChannel = () => {
                const selected = document.querySelector('input[name="checkout_lookup_channel"]:checked')?.value || 'email';
                const changed = selected !== activeChannel;
                activeChannel = selected;
                lookupChannelInputs.forEach(input => input.closest('label')?.classList.toggle('border-primary', input.checked));
                if (lookupEmailField) lookupEmailField.hidden = activeChannel !== 'email';
                if (lookupMobileField) lookupMobileField.hidden = activeChannel !== 'mobile';
                if (changed && codePanel) codePanel.hidden = true;
                if (changed && codeInput) codeInput.value = '';
                if (lookupMobileError) lookupMobileError.textContent = '';
                renderCooldown();
            };
            const post = async (url, data) => {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify(data),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const error = new Error(payload?.errors?.code?.[0] || payload?.errors?.email?.[0] || payload?.message || 'Please try again.');
                    error.retryAfter = payload?.retry_after;
                    throw error;
                }
                return payload;
            };

            const fillCustomer = customer => {
                for (const field of ['first_name', 'last_name', 'address', 'city', 'country', 'identification']) {
                    const input = form.querySelector(`[name="${field}"]`);
                    if (input && customer[field] != null) {
                        input.value = customer[field];
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                emailInput.value = customer.email || '';
                emailInput.readOnly = Boolean(customer.email);
                const phone = window.checkoutIntlTelInput;
                if (phone && customer.phone) phone.setNumber(customer.phone);
                else phoneInput.value = customer.phone || '';
                phoneInput.readOnly = Boolean(customer.phone);
                if (window.validateCheckoutPhoneNumber) window.validateCheckoutPhoneNumber();
                syncContactFields();
                resetButton.hidden = false;
                showSavedDetailsMessage('Saved details loaded. Check them and update anything that has changed.');
            };

            const clearVerifiedCustomer = async () => {
                await post(@json(route('checkout.customer-email.reset')), {});
                hasVerifiedCustomer = false;
                for (const field of ['first_name', 'last_name', 'address', 'city', 'country', 'identification']) {
                    const input = form.querySelector(`[name="${field}"]`);
                    if (input) {
                        input.value = '';
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                emailInput.value = '';
                emailInput.readOnly = false;
                phoneInput.readOnly = false;
                if (window.checkoutIntlTelInput) window.checkoutIntlTelInput.setNumber('');
                else phoneInput.value = '';
                syncContactFields();
                resetButton.hidden = true;
                if (verifiedStatus) verifiedStatus.hidden = true;
                const newCustomerOption = document.querySelector('input[name="checkout_customer_kind"][value="new"]');
                if (newCustomerOption) newCustomerOption.checked = true;
                syncCustomerChoice();
            };

            const requestCode = async () => {
                if (!sendCodeButton || !codePanel || !codeInput) return;
                const channel = activeChannel;
                const value = identityValue(channel);
                if (!value) {
                    const input = channel === 'email' ? lookupEmailInput : lookupMobileInput;
                    if (!input) return;
                    input.focus();
                    if (channel === 'email') {
                        input.reportValidity();
                        showMessage('Enter your email address first.');
                    } else {
                        showMessage('Choose your country and enter a valid mobile number.');
                    }
                    return;
                }
                if (channel === 'email' && lookupEmailInput && !lookupEmailInput.checkValidity()) { lookupEmailInput.reportValidity(); return; }
                if (channel === 'mobile' && !window.checkoutLookupIntlTelInput?.isValidNumber()) {
                    if (lookupMobileError) lookupMobileError.textContent = 'Enter a valid mobile number for the selected country.';
                    lookupMobileInput?.focus();
                    return;
                }
                sendCodeButton.disabled = true;
                sendCodeButton.textContent = 'Sending…';
                sendCodeButton.setAttribute('aria-busy', 'true');
                status?.setAttribute('aria-busy', 'true');
                showMessage('Sending your code…');
                try {
                    const result = await post(@json(route('checkout.customer-email.send-code')), { channel, value });
                    codePanel.hidden = false;
                    showMessage(`If these details match an earlier booking, check your ${channel === 'email' ? 'inbox' : 'phone'} for a code. It expires in 10 minutes.`, true);
                    startCooldown(channel, result.resend_after || @json($verificationResendAfter ?? 60));
                    codeInput.focus();
                } catch (error) {
                    showMessage(error.message || 'Could not send a code. Please try again.');
                    if (error.retryAfter) startCooldown(channel, error.retryAfter);
                } finally {
                    sendCodeButton.removeAttribute('aria-busy');
                    status?.removeAttribute('aria-busy');
                    renderCooldown();
                }
            };

            customerKindInputs.forEach(input => input.addEventListener('change', async () => {
                if (input.value === 'new' && hasVerifiedCustomer) {
                    try {
                        await clearVerifiedCustomer();
                    } catch (error) {
                        showSavedDetailsMessage(error.message || 'Could not switch customer details. Please refresh the page and try again.', true);
                    }
                    return;
                }
                syncCustomerChoice();
            }));
            lookupChannelInputs.forEach(input => input.addEventListener('change', syncLookupChannel));
            document.querySelectorAll('input[name="payment_type"]').forEach(input => input.addEventListener('change', () => {
                syncContactFields();
                syncPaymentContactRequirement();
            }));
            emailInput.addEventListener('input', syncPaymentContactRequirement);
            phoneInput.addEventListener('input', syncPaymentContactRequirement);
            phoneInternationalInput.addEventListener('change', syncPaymentContactRequirement);
            syncLookupChannel();
            syncContactFields();
            syncCustomerChoice();
            sendCodeButton?.addEventListener('click', requestCode);

            verifyButton?.addEventListener('click', async () => {
                if (!codeInput || !codePanel) return;
                const value = identityValue(activeChannel);
                verifyButton.disabled = true;
                try {
                    const result = await post(@json(route('checkout.customer-email.verify-code')), { channel: activeChannel, value, code: codeInput.value.trim() });
                    fillCustomer(result.data);
                    showBookingForm();
                } catch (error) {
                    showMessage(error.message || 'The code is invalid or expired.');
                } finally {
                    verifyButton.disabled = false;
                }
            });

            resetButton.addEventListener('click', async () => {
                resetButton.disabled = true;
                try {
                    await clearVerifiedCustomer();
                } catch (error) {
                    showSavedDetailsMessage(error.message || 'Could not clear the saved details. Refresh this page to start again.', true);
                } finally {
                    resetButton.disabled = false;
                }
            });
            const verifiedCustomer = @json($verifiedCheckoutCustomer);
            if (verifiedCustomer) {
                fillCustomer(verifiedCustomer);
                showBookingForm();
            }
        });
    </script>
@endpush
