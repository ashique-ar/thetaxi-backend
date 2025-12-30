/**
 * Popup Display Engine
 * 
 * Handles fetching, displaying, and managing marketing popups on the public website.
 * Supports display frequency enforcement (always, once per session, once per day)
 * and priority-based popup selection.
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6
 */

(function () {
    'use strict';

    // Configuration
    const CONFIG = {
        apiEndpoint: '/api/popups/highest-priority',
        storageKeyPrefix: 'thetaxi_popup_',
        sessionStorageKey: 'thetaxi_popup_session_shown',
        dayInMs: 24 * 60 * 60 * 1000, // 24 hours in milliseconds
        displayDelay: 1500, // Delay before showing popup (ms)
        animationDuration: 300 // Animation duration (ms)
    };

    // Display frequency constants (must match backend)
    const FREQUENCY = {
        ALWAYS: 'always',
        ONCE_PER_SESSION: 'once_per_session',
        ONCE_PER_DAY: 'once_per_day'
    };

    /**
     * PopupDisplayEngine class
     * Manages popup display logic including frequency enforcement and priority selection
     */
    class PopupDisplayEngine {
        constructor() {
            this.currentPopup = null;
            this.popupElement = null;
            this.isInitialized = false;
        }

        /**
         * Initialize the popup display engine
         * @param {string} currentPage - The current page identifier (homepage, checkout, etc.)
         */
        async init(currentPage = 'all') {
            if (this.isInitialized) {
                return;
            }

            this.isInitialized = true;
            this.currentPage = currentPage;

            // Wait for DOM to be ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.loadAndDisplayPopup());
            } else {
                // Add a small delay to let the page render first
                setTimeout(() => this.loadAndDisplayPopup(), CONFIG.displayDelay);
            }
        }

        /**
         * Fetch active popups from the API and display the highest priority one
         */
        async loadAndDisplayPopup() {
            try {
                const popup = await this.fetchHighestPriorityPopup();

                if (!popup) {
                    console.debug('PopupDisplayEngine: No active popup found');
                    return;
                }

                // Check if popup should be displayed based on frequency
                if (!this.shouldDisplayPopup(popup)) {
                    console.debug('PopupDisplayEngine: Popup display skipped due to frequency rules', popup.id);
                    return;
                }

                // Display the popup
                this.displayPopup(popup);

            } catch (error) {
                console.error('PopupDisplayEngine: Error loading popup', error);
            }
        }

        /**
         * Fetch the highest priority popup from the API
         * @returns {Promise<Object|null>} The popup data or null
         */
        async fetchHighestPriorityPopup() {
            try {
                const url = `${CONFIG.apiEndpoint}?page=${encodeURIComponent(this.currentPage)}`;
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (result.status === 'success' && result.data) {
                    return result.data;
                }

                return null;
            } catch (error) {
                console.error('PopupDisplayEngine: Failed to fetch popup', error);
                return null;
            }
        }

        /**
         * Check if a popup should be displayed based on its frequency setting
         * @param {Object} popup - The popup data
         * @returns {boolean} Whether the popup should be displayed
         */
        shouldDisplayPopup(popup) {
            const frequency = popup.display_frequency || FREQUENCY.ALWAYS;
            const popupId = popup.id;

            switch (frequency) {
                case FREQUENCY.ALWAYS:
                    return true;

                case FREQUENCY.ONCE_PER_SESSION:
                    return !this.hasShownInSession(popupId);

                case FREQUENCY.ONCE_PER_DAY:
                    return !this.hasShownToday(popupId);

                default:
                    console.warn('PopupDisplayEngine: Unknown frequency', frequency);
                    return true;
            }
        }

        /**
         * Check if a popup has been shown in the current session
         * @param {string} popupId - The popup ID
         * @returns {boolean}
         */
        hasShownInSession(popupId) {
            try {
                const sessionData = sessionStorage.getItem(CONFIG.sessionStorageKey);
                if (!sessionData) {
                    return false;
                }

                const shownPopups = JSON.parse(sessionData);
                return Array.isArray(shownPopups) && shownPopups.includes(popupId);
            } catch (error) {
                console.error('PopupDisplayEngine: Error reading session storage', error);
                return false;
            }
        }

        /**
         * Check if a popup has been shown today (within the last 24 hours)
         * @param {string} popupId - The popup ID
         * @returns {boolean}
         */
        hasShownToday(popupId) {
            try {
                const storageKey = CONFIG.storageKeyPrefix + popupId;
                const lastShown = localStorage.getItem(storageKey);

                if (!lastShown) {
                    return false;
                }

                const lastShownTime = parseInt(lastShown, 10);
                const now = Date.now();

                // Check if less than 24 hours have passed
                return (now - lastShownTime) < CONFIG.dayInMs;
            } catch (error) {
                console.error('PopupDisplayEngine: Error reading local storage', error);
                return false;
            }
        }

        /**
         * Record that a popup has been shown (for frequency tracking)
         * @param {Object} popup - The popup data
         */
        recordPopupShown(popup) {
            const frequency = popup.display_frequency || FREQUENCY.ALWAYS;
            const popupId = popup.id;

            try {
                switch (frequency) {
                    case FREQUENCY.ONCE_PER_SESSION:
                        this.recordSessionShown(popupId);
                        break;

                    case FREQUENCY.ONCE_PER_DAY:
                        this.recordDayShown(popupId);
                        break;

                    // FREQUENCY.ALWAYS doesn't need recording
                }
            } catch (error) {
                console.error('PopupDisplayEngine: Error recording popup shown', error);
            }
        }

        /**
         * Record popup shown in session storage
         * @param {string} popupId - The popup ID
         */
        recordSessionShown(popupId) {
            try {
                let shownPopups = [];
                const sessionData = sessionStorage.getItem(CONFIG.sessionStorageKey);

                if (sessionData) {
                    shownPopups = JSON.parse(sessionData);
                }

                if (!shownPopups.includes(popupId)) {
                    shownPopups.push(popupId);
                }

                sessionStorage.setItem(CONFIG.sessionStorageKey, JSON.stringify(shownPopups));
            } catch (error) {
                console.error('PopupDisplayEngine: Error writing to session storage', error);
            }
        }

        /**
         * Record popup shown in local storage with timestamp
         * @param {string} popupId - The popup ID
         */
        recordDayShown(popupId) {
            try {
                const storageKey = CONFIG.storageKeyPrefix + popupId;
                localStorage.setItem(storageKey, Date.now().toString());
            } catch (error) {
                console.error('PopupDisplayEngine: Error writing to local storage', error);
            }
        }

        /**
         * Display a popup on the page
         * @param {Object} popup - The popup data
         */
        displayPopup(popup) {
            this.currentPopup = popup;

            // Create popup HTML
            const popupHtml = this.createPopupHtml(popup);

            // Insert into DOM
            document.body.insertAdjacentHTML('beforeend', popupHtml);

            // Get the popup element
            this.popupElement = document.getElementById(`popup-modal-${popup.id}`);

            if (!this.popupElement) {
                console.error('PopupDisplayEngine: Failed to create popup element');
                return;
            }

            // Bind event handlers
            this.bindEventHandlers();

            // Show the popup with animation
            requestAnimationFrame(() => {
                this.popupElement.style.display = 'flex';
                requestAnimationFrame(() => {
                    this.popupElement.classList.add('active');
                });
            });

            // Record that popup was shown
            this.recordPopupShown(popup);

            // Prevent body scroll when popup is open
            document.body.style.overflow = 'hidden';

            console.debug('PopupDisplayEngine: Popup displayed', popup.id);
        }

        /**
         * Create the popup HTML string
         * @param {Object} popup - The popup data
         * @returns {string} The HTML string
         */
        createPopupHtml(popup) {
            // Prefer server-generated S3 URL if provided (popup.image_url), otherwise fall back
            const imageSrc = popup.image_url || this.getImageUrl(popup.image);

            const imageHtml = popup.image ? `
                <div class="popup-image-wrapper">
                    <img src="${this.escapeHtml(imageSrc || '')}" 
                         alt="${this.escapeHtml(popup.title || 'Promotional popup')}" 
                         class="popup-image"
                         loading="lazy">
                </div>
            ` : '';
            const titleHtml = popup.title ? `
                <h3 class="popup-title">${this.escapeHtml(popup.title)}</h3>
            ` : '';

            const contentHtml = popup.content ? `
                <div class="popup-content">${popup.content}</div>
            ` : '';

            const ctaHtml = (popup.cta_text && popup.cta_link) ? `
                <div class="popup-cta-wrapper">
                    <a href="${this.escapeHtml(popup.cta_link)}" 
                       class="popup-cta-btn"
                       data-popup-cta="true">
                        ${this.escapeHtml(popup.cta_text)}
                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"></path>
                        </svg>
                    </a>
                </div>
            ` : '';

            return `
                <div id="popup-modal-${popup.id}" 
                     class="popup-modal-overlay" 
                     data-popup-id="${popup.id}"
                     data-display-frequency="${popup.display_frequency || 'always'}"
                     style="display: none;">
                    <div class="popup-modal-container">
                        <div class="popup-modal-content">
                            <button type="button" class="popup-close-btn" aria-label="Close popup">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            ${imageHtml}
                            <div class="popup-body">
                                ${titleHtml}
                                ${contentHtml}
                                ${ctaHtml}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Get the full image URL
         * @param {string} imagePath - The image path
         * @returns {string} The full URL
         */
        getImageUrl(imagePath) {
            if (!imagePath) return '';

            // If it's already a full URL, return as-is
            if (imagePath.startsWith('http://') || imagePath.startsWith('https://')) {
                return imagePath;
            }

            // Check if s3_asset function result (already processed)
            if (imagePath.includes('s3.amazonaws.com') || imagePath.includes('cloudfront.net')) {
                return imagePath;
            }

            // Assume it's an S3 path - construct URL
            // This should match the s3_asset helper behavior
            return imagePath;
        }

        /**
         * Escape HTML to prevent XSS
         * @param {string} text - The text to escape
         * @returns {string} The escaped text
         */
        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Bind event handlers for the popup
         */
        bindEventHandlers() {
            if (!this.popupElement) return;

            // Close button click
            const closeBtn = this.popupElement.querySelector('.popup-close-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.closePopup();
                });
            }

            // Click outside to close
            this.popupElement.addEventListener('click', (e) => {
                if (e.target === this.popupElement) {
                    this.closePopup();
                }
            });

            // CTA button click - close popup after navigation
            const ctaBtn = this.popupElement.querySelector('[data-popup-cta="true"]');
            if (ctaBtn) {
                ctaBtn.addEventListener('click', () => {
                    // Let the link navigate, but close the popup
                    this.closePopup(false); // Don't prevent default
                });
            }

            // Escape key to close
            this.escapeHandler = (e) => {
                if (e.key === 'Escape') {
                    this.closePopup();
                }
            };
            document.addEventListener('keydown', this.escapeHandler);
        }

        /**
         * Close the popup
         * @param {boolean} animate - Whether to animate the close
         */
        closePopup(animate = true) {
            if (!this.popupElement) return;

            // Remove escape key handler
            if (this.escapeHandler) {
                document.removeEventListener('keydown', this.escapeHandler);
            }

            // Restore body scroll
            document.body.style.overflow = '';

            if (animate) {
                // Add closing animation
                this.popupElement.classList.add('closing');
                this.popupElement.classList.remove('active');

                // Remove element after animation
                setTimeout(() => {
                    this.removePopupElement();
                }, CONFIG.animationDuration);
            } else {
                this.removePopupElement();
            }

            console.debug('PopupDisplayEngine: Popup closed', this.currentPopup?.id);
        }

        /**
         * Remove the popup element from DOM
         */
        removePopupElement() {
            if (this.popupElement && this.popupElement.parentNode) {
                this.popupElement.parentNode.removeChild(this.popupElement);
            }
            this.popupElement = null;
            this.currentPopup = null;
        }

        /**
         * Clear all popup display records (useful for testing)
         */
        clearDisplayRecords() {
            try {
                // Clear session storage
                sessionStorage.removeItem(CONFIG.sessionStorageKey);

                // Clear local storage popup records
                const keysToRemove = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (key && key.startsWith(CONFIG.storageKeyPrefix)) {
                        keysToRemove.push(key);
                    }
                }
                keysToRemove.forEach(key => localStorage.removeItem(key));

                console.debug('PopupDisplayEngine: Display records cleared');
            } catch (error) {
                console.error('PopupDisplayEngine: Error clearing display records', error);
            }
        }
    }

    // Create global instance
    window.PopupDisplayEngine = new PopupDisplayEngine();

    // Auto-initialize on page load if data attribute is present
    document.addEventListener('DOMContentLoaded', function () {
        const pageElement = document.querySelector('[data-popup-page]');
        const currentPage = pageElement ? pageElement.getAttribute('data-popup-page') : 'all';

        // Check if popups should be enabled (can be disabled via data attribute)
        const disablePopups = document.querySelector('[data-disable-popups="true"]');
        if (!disablePopups) {
            window.PopupDisplayEngine.init(currentPage);
        }
    });

})();
