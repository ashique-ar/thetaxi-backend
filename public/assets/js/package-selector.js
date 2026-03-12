/**
 * Package Selector - Handle button-style package selection
 */
(function() {
    'use strict';

    function initPackageSelector() {
        // Handle package button clicks
        const packageButtons = document.querySelectorAll('.package-button');
        
        packageButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                // Get the radio input inside this button
                const radio = this.querySelector('input[type="radio"]');
                
                if (radio) {
                    // Check the radio
                    radio.checked = true;
                    
                    // Get the parent wrapper to find all buttons in this group
                    const wrapper = this.closest('.package-buttons-wrapper');
                    
                    if (wrapper) {
                        // Remove active class from all buttons in this group
                        wrapper.querySelectorAll('.package-button').forEach(btn => {
                            btn.classList.remove('active');
                        });
                        
                        // Add active class to clicked button
                        this.classList.add('active');
                    }
                    
                    // Trigger change event for any listeners
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
        
        // Handle radio input changes (for keyboard navigation)
        const packageRadios = document.querySelectorAll('.package-radio-input');
        
        packageRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.checked) {
                    // Get the parent wrapper
                    const wrapper = this.closest('.package-buttons-wrapper');
                    
                    if (wrapper) {
                        // Remove active class from all buttons
                        wrapper.querySelectorAll('.package-button').forEach(btn => {
                            btn.classList.remove('active');
                        });
                        
                        // Add active class to the parent label
                        const parentLabel = this.closest('.package-button');
                        if (parentLabel) {
                            parentLabel.classList.add('active');
                        }
                    }
                }
            });
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPackageSelector);
    } else {
        initPackageSelector();
    }

    // Re-initialize when booking form tabs change
    document.addEventListener('click', function(e) {
        if (e.target.closest('.single-item')) {
            // Tab was clicked, re-initialize after a short delay
            setTimeout(initPackageSelector, 100);
        }
    });
})();
