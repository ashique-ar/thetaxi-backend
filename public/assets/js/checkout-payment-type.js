/**
 * Checkout Payment Type Handler
 * 
 * Manages payment type selection and shows/hides payment method fields based on selection.
 * Tracks all payment types: quotation, advance, full, checkin
 * Logs to console for debugging
 */

document.addEventListener('DOMContentLoaded', function () {
    const paymentTypeRadios = document.querySelectorAll('input[name="payment_type"]');
    const paymentMethodSection = document.querySelector('.choose-payment-method');
    const paymentTypeAlert = document.getElementById('payment-type-alert');
    const alertContent = document.getElementById('alert-content');
    const checkoutForm = document.getElementById('checkout-form');

    // Payment type configurations
    const paymentTypeConfig = {
        'full': {
            label: 'Full Payment',
            icon: 'bi bi-credit-card-fill text-success',
            requiresMethod: true,
            description: 'Complete payment now'
        },
        'advance': {
            label: 'Advance Payment',
            icon: 'bi bi-credit-card text-warning',
            requiresMethod: true,
            description: 'Pay percentage now, remainder at pickup'
        },
        'checkin': {
            label: 'Pay on Check-in',
            icon: 'bi bi-cash-coin text-primary',
            requiresMethod: false,
            description: 'Pay when collecting vehicle'
        },
        'quotation': {
            label: 'Request Quotation',
            icon: 'bi bi-file-text text-info',
            requiresMethod: false,
            description: 'Get detailed pricing'
        }
    };

    /**
     * Log payment type selection to console
     */
    function logPaymentTypeSelection(paymentType) {
        const config = paymentTypeConfig[paymentType];
        console.log('%c=== PAYMENT TYPE SELECTION ===', 'color: #2ecc71; font-weight: bold; font-size: 14px;');
        console.log(`%cType: ${paymentType}`, 'color: #3498db; font-weight: bold;');
        console.log(`%cLabel: ${config.label}`, 'color: #9b59b6;');
        console.log(`%cDescription: ${config.description}`, 'color: #e74c3c;');
        console.log(`%cRequires Payment Method: ${config.requiresMethod}`, 'color: #f39c12;');
        console.log('%c============================', 'color: #2ecc71; font-weight: bold; font-size: 14px;');
    }

    /**
     * Update alert content based on selected payment type
     */
    function updateAlertContent(paymentType) {
        const alertMessages = {
            'full': `
                <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                <p class="mb-0">You are making full payment for your booking.</p>
            `,
            'advance': `
                <h6><i class="bi bi-credit-card"></i> Advance Payment</h6>
                <p class="mb-0">You are paying the advance percentage. The remaining amount will be collected at the time of vehicle pickup.</p>
            `,
            'checkin': `
                <h6><i class="bi bi-cash-coin"></i> Pay on Check-in</h6>
                <p class="mb-0">No payment is required now. You will pay the full amount when you check-in to collect the vehicle.</p>
            `,
            'quotation': `
                <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                <p class="mb-0">You are requesting a quotation. Our team will contact you with detailed pricing and booking information.</p>
            `
        };

        if (alertContent && alertMessages[paymentType]) {
            alertContent.innerHTML = alertMessages[paymentType];
        }
    }

    /**
     * Set payment method based on payment type
     */
    function setPaymentMethodForType(paymentType) {
        const paymentMethodField = document.querySelector('input[name="payment_method"]');
        if (!paymentMethodField) return;

        let method = null;
        switch (paymentType) {
            case 'full':
            case 'advance':
                method = 'online'; // Default to WebXPay
                break;
            case 'checkin':
                method = 'offline';
                break;
            case 'quotation':
                method = null;
                break;
            default:
                method = 'online';
        }

        paymentMethodField.value = method || '';
        console.log(`%c→ Payment method set to: ${method || 'none'}`, 'color: #2ecc71; font-weight: bold;');
    }

    /**
     * Handle payment type radio change
     */
    function handlePaymentTypeChange(event) {
        const selectedType = event.target.value;

        logPaymentTypeSelection(selectedType);
        updateAlertContent(selectedType);
        setPaymentMethodForType(selectedType);

        // Trigger custom event for other scripts to listen to
        const event_obj = new CustomEvent('paymentTypeChanged', {
            detail: {
                paymentType: selectedType,
                config: paymentTypeConfig[selectedType]
            }
        });
        document.dispatchEvent(event_obj);
    }

    /**
     * Initialize payment type handlers
     */
    function init() {
        console.log('%c🚀 Initializing Checkout Payment Type Handler', 'color: #e74c3c; font-weight: bold; font-size: 16px;');

        // Add event listeners to all payment type radios
        paymentTypeRadios.forEach(radio => {
            radio.addEventListener('change', handlePaymentTypeChange);
        });

        // Initialize with currently selected payment type
        const initialPaymentType = document.querySelector('input[name="payment_type"]:checked')?.value;
        if (initialPaymentType) {
            console.log(`%cInitializing with payment type: ${initialPaymentType}`, 'color: #16a085;');
            handlePaymentTypeChange({
                target: document.querySelector(`input[name="payment_type"][value="${initialPaymentType}"]`)
            });
        }

        console.log('%c✓ Initialization complete', 'color: #27ae60; font-weight: bold;');
    }

    // Initialize on DOM ready
    init();

    // Expose global functions for debugging
    window.checkoutPaymentTypeDebug = {
        logPaymentTypeSelection,
        updateAlertContent,
        setPaymentMethodForType,
        getPaymentTypeConfig: () => paymentTypeConfig,
        getSelectedPaymentType: () => document.querySelector('input[name="payment_type"]:checked')?.value,
        getPaymentMethod: () => document.querySelector('input[name="payment_method"]')?.value
    };

    console.log('%c💡 Debug commands available at window.checkoutPaymentTypeDebug', 'color: #3498db; font-style: italic;');
});
