/**
 * Company Enhanced Booking Form - Complete Implementation
 * Handles all service types with Google Maps integration and fallbacks
 * 
 * IMPORTANT FIX (2026-02-10):
 * - Fixed search results not persisting when making subsequent searches
 * - Issue: Default values were stored only once on page load, preventing search result
 *   coordinates from being recognized as the new "defaults" when the page reloads
 * - Solution: Always update data-default-value attributes to current input values,
 *   allowing search results (e.g., Jaffna to Trincomalee) to become the new baseline
 * - This ensures that when user changes only one field, the other field's coordinates
 *   from the search results are preserved instead of reverting to hardcoded defaults
 */

(function () {
    "use strict";

    // Configuration
    const CONFIG = {
        googleMapsApiKey: "AIzaSyCznzr9vdiHj812MY99eDQsHOt8PeKSQCg",
        sriLankaCities: [
            // "Colombo, Sri Lanka",
            // "Galle, Sri Lanka",
            // "Kandy, Sri Lanka",
            // "Negombo, Sri Lanka",
            // "Jaffna, Sri Lanka",
            // "Trincomalee, Sri Lanka",
            // "Anuradhapura, Sri Lanka",
            // "Polonnaruwa, Sri Lanka",
            // "Nuwara Eliya, Sri Lanka",
            // "Bentota, Sri Lanka",
            // "Hikkaduwa, Sri Lanka",
            // "Mirissa, Sri Lanka",
            // "Ella, Sri Lanka",
            // "Sigiriya, Sri Lanka",
            // "Dambulla, Sri Lanka",
            "Colombo BIA Airport",
            "Mattala Rajapaksa Airport",
            "Jaffna International Airport",
        ],
        cityCoordinates: {
            "Colombo, Sri Lanka": { lat: 6.9271, lng: 79.8612 },
            "Galle, Sri Lanka": { lat: 6.0535, lng: 80.221 },
            "Kandy, Sri Lanka": { lat: 7.2906, lng: 80.6337 },
            "Negombo, Sri Lanka": { lat: 7.2084, lng: 79.8358 },
            "Jaffna, Sri Lanka": { lat: 9.6615, lng: 80.0255 },
            "Trincomalee, Sri Lanka": { lat: 8.5874, lng: 81.2152 },
            "Anuradhapura, Sri Lanka": { lat: 8.3114, lng: 80.4037 },
            "Polonnaruwa, Sri Lanka": { lat: 7.9403, lng: 81.0188 },
            "Nuwara Eliya, Sri Lanka": { lat: 6.9497, lng: 80.7891 },
            "Bentota, Sri Lanka": { lat: 6.4267, lng: 79.9951 },
            "Hikkaduwa, Sri Lanka": { lat: 6.1373, lng: 80.1034 },
            "Mirissa, Sri Lanka": { lat: 5.9487, lng: 80.4565 },
            "Ella, Sri Lanka": { lat: 6.8721, lng: 81.0488 },
            "Sigiriya, Sri Lanka": { lat: 7.9568, lng: 80.7599 },
            "Dambulla, Sri Lanka": { lat: 7.8731, lng: 80.6511 },
            "Colombo BIA Airport": { lat: 7.1808, lng: 79.8841 },
            "Mattala Rajapaksa Airport": { lat: 6.2847, lng: 81.1242 },
            "Jaffna International Airport": { lat: 9.7923, lng: 80.0701 },
        },
    };

    // State management
    const state = {
        currentService: "airport_transfers",
        mapsLoaded: false,
        useLeafletFallback: false,
        customTourDestinations: [],
        routeWaypoints: [],
    };

    // Global map objects
    let map; // Leaflet map instance (OpenStreetMap - FREE)

    /**
     * Initialize the booking form
     */
    function init() {
        setupServiceSwitcher();
        setupAirportTransfer();
        setupDropPickup();
        setupCustomTour();
        setupFormValidation();
        setupDateTimePickers();
        setupFormAnimations();
        initializeLocationPlaceholderExamples();
        loadMapsAPI();
        initializeDatePickers();
        setDefaultDatesAndLocations();
        hideFreshDynamicFormDefaults();
    }

    /**
     * Fresh homepage forms show guidance only. Configured values remain in data
     * attributes/data-field-defaults and are applied immediately before submit.
     * Search/results forms keep their submitted values visible.
     */
    function hideFreshDynamicFormDefaults() {
        document.querySelectorAll('.filter-input[data-has-search-context="false"]').forEach((form) => {
            form.querySelectorAll([
                'input.location-search',
                'input.custom-datepicker',
                'input[type="date"]',
                'input[type="time"]',
                'input[data-default-value]:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])'
            ].join(',')).forEach((input) => {
                if (input.classList.contains('airport-select')) return;
                input.value = '';
                if (input.classList.contains('location-search')) {
                    input.setAttribute('data-place-selected', 'false');
                    input.setAttribute('data-is-default', 'false');
                }
            });
        });
    }

    /**
     * Rotate configured examples without putting a value into the location input.
     * Airport dropdowns are selects and are intentionally excluded.
     */
    function initializeLocationPlaceholderExamples() {
        const reduceMotion = window.matchMedia &&
            window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        document.querySelectorAll('input.location-search[data-placeholder-examples]').forEach((input) => {
            if (input.dataset.placeholderRotationInitialized === "true") return;

            let examples = [];
            try {
                const json = decodeURIComponent(escape(window.atob(input.dataset.placeholderExamples)));
                examples = JSON.parse(json).filter((example) => typeof example === "string" && example.trim());
            } catch (error) {
                console.warn("Invalid location placeholder examples", error);
            }

            if (!examples.length) return;

            input.dataset.placeholderRotationInitialized = "true";
            let index = 0;
            let timer = null;

            const showExample = () => {
                if (!input.value) input.placeholder = examples[index % examples.length];
            };
            const start = () => {
                if (reduceMotion || timer || input.value) return;
                timer = window.setInterval(() => {
                    index = (index + 1) % examples.length;
                    showExample();
                }, 2800);
            };
            const stop = () => {
                if (timer) window.clearInterval(timer);
                timer = null;
            };

            showExample();
            start();
            input.addEventListener("focus", stop);
            input.addEventListener("input", () => input.value ? stop() : start());
            input.addEventListener("blur", () => {
                if (!input.value) {
                    showExample();
                    start();
                }
            });
        });
    }

    /**
     * Set default dates and locations for all forms
     */
    function setDefaultDatesAndLocations() {
        // Get today's date
        const today = new Date();
        const todayFormatted = formatDate(today);

        // Get date 3 days from now
        const threeDaysLater = new Date();
        threeDaysLater.setDate(today.getDate() + 3);
        const threeDaysFormatted = formatDate(threeDaysLater);

        // Airport Transfer - set today's date and initialize airport logic
        const airportForm = document.getElementById("airport_transfers-form");
        if (airportForm) {
            const airportDateInput =
                airportForm.querySelector('input[name="date"]');
            const airportTimeInput =
                airportForm.querySelector('input[name="time"]');

            if (airportDateInput && !airportDateInput.value) {
                airportDateInput.value = todayFormatted;
            }
            if (airportTimeInput && !airportTimeInput.value) {
                airportTimeInput.value = "12:00";
            }

            // Set proper defaults based on transfer type
            const transferTypeChecked = airportForm.querySelector(
                'input[name="transfer_type"]:checked'
            );
            if (transferTypeChecked) {
                // Initialize locations based on selected transfer type
                updateAirportTransferLocations(transferTypeChecked.value);
            } else {
                // Default to "from-airport" if none checked
                const fromAirportRadio = airportForm.querySelector(
                    'input[name="transfer_type"][value="from-airport"]'
                );
                if (fromAirportRadio) {
                    fromAirportRadio.checked = true;
                    updateAirportTransferLocations("from-airport");
                }
            }
        }

        // Drop & Pickup - set today's date and default locations
        const dropPickupForm = document.getElementById("point_to_point-form");
        if (dropPickupForm) {
            const dateInput =
                dropPickupForm.querySelector('input[name="date"]');
            const timeSelect = dropPickupForm.querySelector(
                'select[name="time"]'
            );

            if (dateInput && !dateInput.value) {
                dateInput.value = todayFormatted;
            }
            if (timeSelect && !timeSelect.value) {
                timeSelect.value = "12:00";
            }

            // Set default locations: Colombo to Galle (point-to-point)
            const pickupInput = dropPickupForm.querySelector(
                'input[name="pickup"]'
            );
            const dropoffInput = dropPickupForm.querySelector(
                'input[name="dropoff"]'
            );
            const pickupLat = dropPickupForm.querySelector(
                'input[name="pickup_lat"]'
            );
            const pickupLng = dropPickupForm.querySelector(
                'input[name="pickup_lng"]'
            );
            const dropoffLat = dropPickupForm.querySelector(
                'input[name="dropoff_lat"]'
            );
            const dropoffLng = dropPickupForm.querySelector(
                'input[name="dropoff_lng"]'
            );

            if (pickupInput && !pickupInput.value) {
                pickupInput.value = "Colombo, Sri Lanka";
                if (pickupLat)
                    pickupLat.value =
                        CONFIG.cityCoordinates["Colombo, Sri Lanka"].lat;
                if (pickupLng)
                    pickupLng.value =
                        CONFIG.cityCoordinates["Colombo, Sri Lanka"].lng;
            }
            if (dropoffInput && !dropoffInput.value) {
                dropoffInput.value = "Galle, Sri Lanka";
                if (dropoffLat)
                    dropoffLat.value =
                        CONFIG.cityCoordinates["Galle, Sri Lanka"].lat;
                if (dropoffLng)
                    dropoffLng.value =
                        CONFIG.cityCoordinates["Galle, Sri Lanka"].lng;
            }
        }

        // Rental Packages - set today and 3 days later
        const rideNowForm = document.getElementById("ride_now-form");
        if (rideNowForm) {
            const pickupDateInput = rideNowForm.querySelector(
                'input[name="pickup_date"]'
            );
            // const dropoffDateInput = rideNowForm.querySelector(
            //     'input[name="dropoff_date"]'
            // );
            const pickupTimeInput = rideNowForm.querySelector(
                'input[name="pickup_time"]'
            );
            // const dropoffTimeInput = rideNowForm.querySelector(
            //     'input[name="dropoff_time"]'
            // );

            if (pickupDateInput && !pickupDateInput.value) {
                pickupDateInput.value = todayFormatted;
            }
            // if (dropoffDateInput && !dropoffDateInput.value) {
            //     dropoffDateInput.value = threeDaysFormatted;
            // }
            if (pickupTimeInput && !pickupTimeInput.value) {
                pickupTimeInput.value = "12:00";
            }
            // if (dropoffTimeInput && !dropoffTimeInput.value) {
            //     dropoffTimeInput.value = "12:00";
            // }

            // Set default locations: Colombo to Galle (for rentals)
            const pickupInput = rideNowForm.querySelector(
                'input[name="pickup"]'
            );
            const dropoffInput = rideNowForm.querySelector(
                'input[name="dropoff"]'
            );
            const pickupLat = rideNowForm.querySelector(
                'input[name="pickup_lat"]'
            );
            const pickupLng = rideNowForm.querySelector(
                'input[name="pickup_lng"]'
            );
            const dropoffLat = rideNowForm.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = rideNowForm.querySelector('input[name="dropoff_lng"]');

            if (pickupInput && !pickupInput.value) {
                pickupInput.value = "Colombo, Sri Lanka";
                if (pickupLat)
                    pickupLat.value =
                        CONFIG.cityCoordinates["Colombo, Sri Lanka"].lat;
                if (pickupLng)
                    pickupLng.value =
                        CONFIG.cityCoordinates["Colombo, Sri Lanka"].lng;
            }
            if (dropoffInput && !dropoffInput.value) {
                dropoffInput.value = "Galle, Sri Lanka";
                if (dropoffLat)
                    dropoffLat.value =
                        CONFIG.cityCoordinates["Galle, Sri Lanka"].lat;
                if (dropoffLng)
                    dropoffLng.value =
                        CONFIG.cityCoordinates["Galle, Sri Lanka"].lng;
            }
        }

        const dayRentalForm = document.getElementById("day_rental-form");
        if (dayRentalForm) {
            const pickupDateInput = dayRentalForm.querySelector(
                'input[name="pickup_date"]'
            );
            const dropoffDateInput = dayRentalForm.querySelector(
                'input[name="dropoff_date"]'
            );
            const pickupTimeInput = dayRentalForm.querySelector(
                'input[name="pickup_time"]'
            );
            const dropoffTimeInput = dayRentalForm.querySelector(
                'input[name="dropoff_time"]'
            );

            if (pickupDateInput && !pickupDateInput.value) {
                pickupDateInput.value = todayFormatted;
            }
            if (dropoffDateInput && !dropoffDateInput.value) {
                dropoffDateInput.value = threeDaysFormatted;
            }
            if (pickupTimeInput && !pickupTimeInput.value) {
                pickupTimeInput.value = "12:00";
            }
            if (dropoffTimeInput && !dropoffTimeInput.value) {
                dropoffTimeInput.value = "12:00";
            }

            // Set default locations: Colombo to Galle (for rentals)
            const pickupInput = dayRentalForm.querySelector(
                'input[name="pickup"]'
            );
            const dropoffInput = dayRentalForm.querySelector(
                'input[name="dropoff"]'
            );
            const pickupLat = dayRentalForm.querySelector(
                'input[name="pickup_lat"]'
            );
            const pickupLng = dayRentalForm.querySelector(
                'input[name="pickup_lng"]'
            );
            const dropoffLat = dayRentalForm.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = dayRentalForm.querySelector('input[name="dropoff_lng"]');

            if (pickupInput && !pickupInput.value) {
                pickupInput.value = "Colombo, Sri Lanka";
                if (pickupLat)
                    pickupLat.value =
                        CONFIG.cityCoordinates["Colombo, Sri Lanka"].lat;
                if (pickupLng)
                    pickupLng.value =
                        CONFIG.cityCoordinates["Colombo, Sri Lanka"].lng;
            }
            if (dropoffInput && !dropoffInput.value) {
                dropoffInput.value = "Galle, Sri Lanka";
                if (dropoffLat)
                    dropoffLat.value =
                        CONFIG.cityCoordinates["Galle, Sri Lanka"].lat;
                if (dropoffLng)
                    dropoffLng.value =
                        CONFIG.cityCoordinates["Galle, Sri Lanka"].lng;
            }
        }

        // Custom Tour - set today and 3 days later
        const customTourForm = document.getElementById("custom-tour-form");
        if (customTourForm) {
            const startDateInput = customTourForm.querySelector(
                'input[name="start_date"]'
            );
            const endDateInput = customTourForm.querySelector(
                'input[name="end_date"]'
            );

            if (startDateInput && !startDateInput.value) {
                startDateInput.value = todayFormatted;
            }
            if (endDateInput && !endDateInput.value) {
                endDateInput.value = threeDaysFormatted;
            }
        }

        // Populate address fields from coordinates if address is missing
        populateAddressFromCoords();

        /**
         * Fill visible location inputs when server provided coordinates but no address.
         * It attempts to map coordinates to known city/airport names, otherwise falls back
         * to a lat,lng string so the user sees a usable value.
         */
        function populateAddressFromCoords() {
            const locInputs = document.querySelectorAll('.location-search, #from-location-input, #to-location-input');
            locInputs.forEach(input => {
                try {
                    if (!input) return;
                    // Skip if already has a value
                    if (input.value && input.value.trim() !== '') return;

                    const form = input.closest('form');
                    if (!form) return;

                    let latInput = null, lngInput = null;
                    if (input.name === 'pickup' || input.getAttribute('id') === 'from-location-input') {
                        latInput = form.querySelector('input[name="pickup_lat"]');
                        lngInput = form.querySelector('input[name="pickup_lng"]');
                    } else if (input.name === 'dropoff' || input.getAttribute('id') === 'to-location-input') {
                        latInput = form.querySelector('input[name="dropoff_lat"]');
                        lngInput = form.querySelector('input[name="dropoff_lng"]');
                    }

                    if (!latInput || !lngInput) return;
                    const lat = parseFloat(latInput.value);
                    const lng = parseFloat(lngInput.value);
                    if (!isFinite(lat) || !isFinite(lng)) return;

                    // Do not set coordinates as visible value - leave empty so user can enter location name
                    // The coordinates are already stored in hidden fields (pickup_lat/pickup_lng)
                    // input.value = lat.toFixed(4) + ', ' + lng.toFixed(4);
                } catch (e) {
                    console.warn('populateAddressFromCoords error', e);
                }
            });
        }
    }

    /**
     * Load Maps API with fallback
     */
    function loadMapsAPI() {
        // First try Google Maps
        const script = document.createElement("script");
        script.src = `https://maps.googleapis.com/maps/api/js?key=${CONFIG.googleMapsApiKey}&libraries=places&callback=initializeGoogleMaps`;
        script.async = true;
        script.defer = true;
        script.onerror = function () {
            console.warn("Google Maps failed to load, using fallback");
            initializeLocationSearch();
        };

        document.head.appendChild(script);

        // Timeout fallback
        setTimeout(() => {
            if (!state.mapsLoaded) {
                console.warn("Google Maps timeout, using fallback");
                state.useLeafletFallback = true;
                initializeLocationSearch();
            }
        }, 10000);
    }

    /**
     * Initialize Google Maps services (ONLY FOR LOCATION SEARCH)
     */
    window.initializeGoogleMaps = function () {
        if (!window.google || !window.google.maps) return;

        state.mapsLoaded = true;

        // NOTE: Google Maps is ONLY used for location autocomplete
        // NOT used for directions/routing (that's Leaflet/OSM - FREE)

        initializeLocationSearch();
    };

    /**
     * Initialize location search functionality
     */
    function initializeLocationSearch() {
        const locationInputs = document.querySelectorAll(".location-search");

        locationInputs.forEach((input) => {
            if (input.getAttribute("readonly")) return;

            // Skip if already initialized to prevent duplicate dropdowns
            if (input.getAttribute("data-autocomplete-initialized") === "true")
                return;

            if (
                state.mapsLoaded &&
                window.google &&
                window.google.maps &&
                window.google.maps.places
            ) {
                initializeGooglePlacesAutocomplete(input);
            } else {
                initializeBasicAutocomplete(input);
            }
        });

        // Attach focus handlers to reset values and refresh autocomplete instances
        attachLocationFocusReset();
    }

    /**
     * Attach focus/click handlers to visible location inputs so user can clear and start fresh
     */
    function attachLocationFocusReset() {
        try {
            // Select common location inputs including airport-specific ones
            const selector = '.location-search, #from-location-input, #to-location-input, input[name="pickup"], input[name="dropoff"]';
            const inputs = document.querySelectorAll(selector);

            inputs.forEach((input) => {
                if (!input) return;

                // Avoid adding handlers multiple times
                if (input.__hasFocusReset) return;
                input.__hasFocusReset = true;

                // On focus: clear the visible value so user can type fresh,
                // but preserve default data attributes for reset-on-blur.
                input.addEventListener('focus', function () {
                    try {
                        // Only clear visible value when focus is user-initiated
                        if (this.value && this.value.trim() !== '') {
                            this.value = '';
                            // Only mark as not-selected when we actually clear the value
                            this.setAttribute('data-place-selected', 'false');
                        }

                        // Remove inline error while user is actively editing
                        removeInlineError(this);

                        // For jQuery UI Autocomplete, close to reset suggestions
                        if (typeof $ !== 'undefined' && $.fn.autocomplete && $(this).data('ui-autocomplete')) {
                            try { $(this).autocomplete('close'); } catch (e) { }
                        }
                    } catch (e) {
                        console.warn('attachLocationFocusReset focus handler error', e);
                    }
                });

                // Also respond to click to support emptying via single click
                input.addEventListener('click', function () {
                    try { this.focus(); } catch (e) { }
                });
            });
        } catch (e) {
            console.warn('attachLocationFocusReset init error', e);
        }
    }

    /**
     * Get the lat/lng hidden inputs for a given location input
     * Handles the field name mapping (from→pickup_lat, to→dropoff_lat, etc.)
     */
    function getCoordInputs(input) {
        const form = input.closest("form");
        if (!form) return { latInput: null, lngInput: null };

        let latName, lngName;

        if (input.name === "from" || input.name === "pickup") {
            latName = "pickup_lat";
            lngName = "pickup_lng";
        } else if (input.name === "to" || input.name === "dropoff") {
            latName = "dropoff_lat";
            lngName = "dropoff_lng";
        } else {
            // Fallback: try input.name + _lat/_lng
            latName = input.name + "_lat";
            lngName = input.name + "_lng";
        }

        return {
            latInput: form.querySelector(`input[name="${latName}"]`),
            lngInput: form.querySelector(`input[name="${lngName}"]`)
        };
    }

    /**
     * Initialize Google Places Autocomplete for regular form inputs
     */
    function initializeGooglePlacesAutocomplete(input) {
        // Prevent duplicate initialization
        if (input.getAttribute("data-autocomplete-initialized") === "true") {
            return;
        }

        // Destroy any existing jQuery autocomplete to prevent conflicts
        if (
            typeof $ !== "undefined" &&
            $.fn.autocomplete &&
            $(input).data("ui-autocomplete")
        ) {
            $(input).autocomplete("destroy");
        }

        // Clear any existing Google Places autocomplete listeners
        // This prevents duplicate dropdowns when re-initializing
        google.maps.event.clearInstanceListeners(input);

        // Check if this input should be filtered for airports only
        const isAirportField = input.classList.contains("airport-search-field");

        const autocompleteOptions = {
            componentRestrictions: { country: "lk" },
            fields: ["place_id", "geometry", "name", "formatted_address"],
        };

        // Add airport filter if this is an airport search field
        if (isAirportField) {
            autocompleteOptions.types = ["airport"];
        }

        const autocomplete = new google.maps.places.Autocomplete(
            input,
            autocompleteOptions
        );

        // Get the correct lat/lng inputs using the mapping helper
        const { latInput, lngInput } = getCoordInputs(input);

        // Service Type defaults remain metadata on a fresh homepage. Search/results
        // values are visible because the server rendered them into input.value.
        if (!input.hasAttribute("data-default-value")) {
            input.setAttribute("data-default-value", "");
        }
        // For conditional location fields, prefer data-default-lat/lng already set on the input by server
        var serverDefaultLat = input.getAttribute("data-default-lat") || "";
        var serverDefaultLng = input.getAttribute("data-default-lng") || "";
        if (latInput) {
            latInput.setAttribute("data-default-lat", serverDefaultLat || latInput.value || "");
        }
        if (lngInput) {
            lngInput.setAttribute("data-default-lng", serverDefaultLng || lngInput.value || "");
        }
        // Also store on the input itself for conditional variants that share lat/lng inputs
        if (!input.hasAttribute("data-default-lat")) {
            input.setAttribute("data-default-lat", latInput?.value || "");
        }
        if (!input.hasAttribute("data-default-lng")) {
            input.setAttribute("data-default-lng", lngInput?.value || "");
        }

        // If field has default value with valid coordinates, mark as selected
        var hasValidCoords = false;
        if (input.value.trim() !== "") {
            // Check input's own data-default-lat/lng first (for conditional variants)
            var ownLat = input.getAttribute("data-default-lat") || "";
            var ownLng = input.getAttribute("data-default-lng") || "";
            if (ownLat && ownLng) {
                hasValidCoords = true;
            } else if (latInput?.value && lngInput?.value && latInput.value !== "" && lngInput.value !== "") {
                hasValidCoords = true;
            }
        }
        if (hasValidCoords) {
            input.setAttribute("data-place-selected", "true");
            input.setAttribute("data-is-default", "true");
        } else {
            input.setAttribute("data-place-selected", "false");
            input.setAttribute("data-is-default", "false");
        }

        const form = input.closest("form");

        // Check form validity after initialization
        setTimeout(() => checkFormValidity(form), 100);

        autocomplete.addListener("place_changed", function () {
            const place = autocomplete.getPlace();
            if (place && place.geometry) {
                updateLocationData(input, place);
                // Mark that a valid selection HAS been made
                input.setAttribute("data-place-selected", "true");
                input.setAttribute("data-is-default", "false");
                // Remove any error styling and message
                input.classList.remove("error");
                input.style.borderColor = "";
                removeInlineError(input);
                // Re-check form validity to enable submit button
                checkFormValidity(form);
            }
        });

        // When user starts typing, clear coordinates and mark as not selected
        // NOTE: Do NOT show inline errors here — errors are shown on blur only.
        // Showing errors while typing creates a bad UX and causes the "still showing error
        // after selection" bug because the input event fires before place_changed.
        input.addEventListener("input", function () {
            const currentValue = this.value.trim();
            const defaultValue = this.getAttribute("data-default-value") || "";

            if (currentValue === "") {
                this.setAttribute("data-place-selected", "false");
                removeInlineError(this);
                this.classList.remove("error");
                this.style.borderColor = "";
            } else if (currentValue !== defaultValue) {
                // User is typing something different from default - clear coordinates
                if (latInput) latInput.value = "";
                if (lngInput) lngInput.value = "";

                this.setAttribute("data-place-selected", "false");
                this.setAttribute("data-is-default", "false");

                // Don't show error while typing — wait for blur
            }

            // Don't call checkFormValidity while typing — it disables the button
            // prematurely. Validity is checked on blur and on place_changed.
        });

        // Handle blur - reset to default if empty, validate if has value
        input.addEventListener("blur", function () {
            const self = this;
            // 400ms delay: when user clicks a Google suggestion, blur fires first,
            // then place_changed fires ~200ms later. We wait long enough for that.
            setTimeout(function () {
                const currentValue = self.value.trim();
                const defaultValue = self.getAttribute("data-default-value") || "";
                // Prefer defaults from the input itself (set by server for conditional variants)
                const defaultLat = self.getAttribute("data-default-lat") || latInput?.getAttribute("data-default-lat") || "";
                const defaultLng = self.getAttribute("data-default-lng") || lngInput?.getAttribute("data-default-lng") || "";

                // Re-read state AFTER the delay (place_changed may have updated it)
                const placeSelected = self.getAttribute("data-place-selected") === "true";

                if (currentValue === "") {
                    // Keep fresh/cleared location inputs empty so their placeholder
                    // remains visible. Submit applies metadata defaults later.
                    self.setAttribute("data-place-selected", "false");
                    self.setAttribute("data-is-default", "false");
                    self.classList.remove("error");
                    self.style.borderColor = "";
                    removeInlineError(self);
                } else if (placeSelected) {
                    // Valid selection — clear any leftover errors
                    self.classList.remove("error");
                    self.style.borderColor = "";
                    removeInlineError(self);
                } else {
                    // User typed but didn't select from dropdown
                    self.classList.add("error");
                    self.style.borderColor = "#dc3545";
                    showInlineError(self, "Please select a location from the dropdown");
                }

                checkFormValidity(form);
            }, 400);
        });

        // Store the autocomplete instance on the input element for later cleanup
        input.googleAutocomplete = autocomplete;

        // Mark as initialized
        input.setAttribute("data-autocomplete-initialized", "true");
    }

    /**
     * Initialize Google Places Autocomplete for modal location inputs
     */
    function initializeGooglePlacesAutocompleteForModal(input) {
        const autocomplete = new google.maps.places.Autocomplete(input, {
            componentRestrictions: { country: "lk" },
            fields: ["place_id", "geometry", "name", "formatted_address"],
        });

        autocomplete.addListener("place_changed", function () {
            const place = autocomplete.getPlace();
            const index = parseInt(input.getAttribute("data-index"));

            if (place.geometry && !isNaN(index)) {
                // Update state with new location
                state.customTourDestinations[index].name =
                    place.formatted_address || place.name;
                state.customTourDestinations[index].lat =
                    place.geometry.location.lat();
                state.customTourDestinations[index].lng =
                    place.geometry.location.lng();

                // Update the input value
                input.value = place.formatted_address || place.name;

                // Sync to form and refresh map
                updateFormFromState();
                setTimeout(() => initializeRouteMap(), 200);
            }
        });
    }

    /**
     * Initialize basic autocomplete for modal inputs
     */
    function initializeBasicAutocompleteForModal(input) {
        if (typeof $ !== "undefined" && $.fn.autocomplete) {
            $(input).autocomplete({
                source: CONFIG.sriLankaCities,
                minLength: 2,
                select: function (event, ui) {
                    const index = parseInt(input.getAttribute("data-index"));
                    const coordinates = CONFIG.cityCoordinates[ui.item.value];

                    if (!isNaN(index) && coordinates) {
                        // Update state
                        state.customTourDestinations[index].name =
                            ui.item.value;
                        state.customTourDestinations[index].lat =
                            coordinates.lat;
                        state.customTourDestinations[index].lng =
                            coordinates.lng;

                        // Sync to form and refresh map
                        updateFormFromState();
                        setTimeout(() => initializeRouteMap(), 200);
                    }
                },
            });
        }
    }

    /**
     * Initialize basic autocomplete with predefined cities
     */
    function initializeBasicAutocomplete(input) {
        // Don't use jQuery autocomplete if Google Maps is available
        // This prevents duplicate dropdowns
        if (
            state.mapsLoaded &&
            window.google &&
            window.google.maps &&
            window.google.maps.places
        ) {
            return; // Google Places will handle it
        }

        if (typeof $ !== "undefined" && $.fn.autocomplete) {
            // Prevent duplicate initialization
            if ($(input).data("ui-autocomplete")) {
                return;
            }

            // Check if this input should be filtered for airports only
            const isAirportField = input.classList.contains(
                "airport-search-field"
            );

            // Use airports API if it's an airport field, otherwise use all cities
            let sourceList = isAirportField
                ? [
                    "Colombo BIA Airport",
                    "Mattala Rajapaksa Airport",
                    "Jaffna International Airport",
                ] // Fallback
                : CONFIG.sriLankaCities;

            // Setup autocomplete source based on field type
            if (isAirportField) {
                // Use our custom airport API for airport fields
                $(input).autocomplete({
                    source: function (request, response) {
                        fetch(
                            `/api/places/airports?query=${encodeURIComponent(
                                request.term
                            )}`
                        )
                            .then((res) => res.json())
                            .then((data) => {
                                if (data.status === "success" && data.data) {
                                    response(
                                        data.data.map((airport) => ({
                                            label: airport.description,
                                            value: airport.description,
                                            data: airport,
                                        }))
                                    );
                                } else {
                                    // Fallback to hardcoded airports
                                    response(
                                        sourceList
                                            .filter((airport) =>
                                                airport
                                                    .toLowerCase()
                                                    .includes(
                                                        request.term.toLowerCase()
                                                    )
                                            )
                                            .map((airport) => ({
                                                label: airport,
                                                value: airport,
                                            }))
                                    );
                                }
                            })
                            .catch((err) => {
                                console.error("Airport search failed:", err);
                                // Fallback to hardcoded airports
                                response(
                                    sourceList
                                        .filter((airport) =>
                                            airport
                                                .toLowerCase()
                                                .includes(
                                                    request.term.toLowerCase()
                                                )
                                        )
                                        .map((airport) => ({
                                            label: airport,
                                            value: airport,
                                        }))
                                );
                            });
                    },
                    minLength: 0, // Show all airports on focus
                    select: function (event, ui) {
                        const airportData = ui.item.data;
                        if (
                            airportData &&
                            airportData.latitude &&
                            airportData.longitude
                        ) {
                            // Use API data coordinates
                            updateLocationData(input, {
                                formatted_address: airportData.description,
                                geometry: {
                                    location: {
                                        lat: () => airportData.latitude,
                                        lng: () => airportData.longitude,
                                    },
                                },
                            });
                        } else {
                            // Fallback to hardcoded coordinates
                            const coordinates =
                                CONFIG.cityCoordinates[ui.item.value];
                            updateLocationData(input, {
                                formatted_address: ui.item.value,
                                geometry: coordinates
                                    ? {
                                        location: {
                                            lat: () => coordinates.lat,
                                            lng: () => coordinates.lng,
                                        },
                                    }
                                    : null,
                            });
                        }
                    },
                });
            } else {
                // Use predefined cities for regular location fields
                $(input).autocomplete({
                    source: sourceList,
                    minLength: 2,
                    select: function (event, ui) {
                        const coordinates =
                            CONFIG.cityCoordinates[ui.item.value];
                        updateLocationData(input, {
                            formatted_address: ui.item.value,
                            geometry: coordinates
                                ? {
                                    location: {
                                        lat: () => coordinates.lat,
                                        lng: () => coordinates.lng,
                                    },
                                }
                                : null,
                        });
                        // Mark that a valid selection HAS been made
                        input.setAttribute("data-place-selected", "true");
                        input.setAttribute("data-is-default", "false");
                        // Remove any error styling and inline error message
                        input.classList.remove("error");
                        input.style.borderColor = "";
                        removeInlineError(input);
                        checkFormValidity(input.closest("form"));
                    },
                });

                // Store default values — use getCoordInputs for correct field mapping
                const form = input.closest("form");
                const { latInput, lngInput } = getCoordInputs(input);

                if (!input.hasAttribute("data-default-value")) {
                    input.setAttribute("data-default-value", "");
                }
                if (latInput) {
                    latInput.setAttribute("data-default-lat", latInput.value || "");
                }
                if (lngInput) {
                    lngInput.setAttribute("data-default-lng", lngInput.value || "");
                }

                // If field has default value with valid coordinates, mark as selected
                if (input.value.trim() !== "" && latInput?.value && lngInput?.value && latInput.value !== "" && lngInput.value !== "") {
                    input.setAttribute("data-place-selected", "true");
                    input.setAttribute("data-is-default", "true");
                } else {
                    input.setAttribute("data-place-selected", "false");
                    input.setAttribute("data-is-default", "false");
                }

                // Check form validity after initialization
                setTimeout(() => checkFormValidity(form), 100);

                // When user starts typing — only clear coords, don't show errors
                $(input).on("input", function () {
                    const currentValue = this.value.trim();
                    const defaultValue = this.getAttribute("data-default-value") || "";

                    if (currentValue === "") {
                        this.setAttribute("data-place-selected", "false");
                        removeInlineError(this);
                        this.classList.remove("error");
                        this.style.borderColor = "";
                    } else if (currentValue !== defaultValue) {
                        // Clear coordinates immediately
                        if (latInput) latInput.value = "";
                        if (lngInput) lngInput.value = "";

                        this.setAttribute("data-place-selected", "false");
                        this.setAttribute("data-is-default", "false");
                        // Don't show error while typing — wait for blur
                    }
                    // Don't call checkFormValidity while typing
                });

                // Handle blur — validate after delay to allow autocomplete select to fire
                $(input).on("blur", function () {
                    const self = this;
                    setTimeout(function () {
                        const currentValue = self.value.trim();
                        const defaultValue = self.getAttribute("data-default-value") || "";
                        const defaultLat = latInput?.getAttribute("data-default-lat") || "";
                        const defaultLng = lngInput?.getAttribute("data-default-lng") || "";
                        const placeSelected = self.getAttribute("data-place-selected") === "true";

                        if (currentValue === "") {
                            self.setAttribute("data-place-selected", "false");
                            self.setAttribute("data-is-default", "false");
                            self.classList.remove("error");
                            self.style.borderColor = "";
                            removeInlineError(self);
                        } else if (placeSelected) {
                            // Valid selection — clear any leftover errors
                            self.classList.remove("error");
                            self.style.borderColor = "";
                            removeInlineError(self);
                        } else {
                            // User typed but didn't select from dropdown
                            self.classList.add("error");
                            self.style.borderColor = "#dc3545";
                            showInlineError(self, "Please select a location from the dropdown");
                        }

                        checkFormValidity(form);
                    }, 400);
                });
            }

            // Show dropdown on focus for airport fields
            if (isAirportField) {
                $(input).on("focus", function () {
                    $(this).autocomplete("search", "");
                });
            }

            // For airport fields, also handle selection tracking
            if (isAirportField) {
                const form = input.closest("form");
                const { latInput, lngInput } = getCoordInputs(input);

                if (!input.hasAttribute("data-default-value")) {
                    input.setAttribute("data-default-value", "");
                }
                if (latInput) {
                    latInput.setAttribute("data-default-lat", latInput.value || "");
                }
                if (lngInput) {
                    lngInput.setAttribute("data-default-lng", lngInput.value || "");
                }

                // Mark initial state
                if (input.value.trim() !== "" && latInput?.value && lngInput?.value && latInput.value !== "" && lngInput.value !== "") {
                    input.setAttribute("data-place-selected", "true");
                    input.setAttribute("data-is-default", "true");
                } else {
                    input.setAttribute("data-place-selected", "false");
                    input.setAttribute("data-is-default", "false");
                }

                // Check form validity after initialization
                setTimeout(() => checkFormValidity(form), 100);

                $(input).on("autocompleteselect", function () {
                    this.setAttribute("data-place-selected", "true");
                    this.setAttribute("data-is-default", "false");
                    this.classList.remove("error");
                    this.style.borderColor = "";
                    removeInlineError(this);
                    checkFormValidity(form);
                });

                $(input).on("input", function () {
                    const currentValue = this.value.trim();
                    const defaultValue = this.getAttribute("data-default-value") || "";

                    if (currentValue === "") {
                        this.setAttribute("data-place-selected", "false");
                        removeInlineError(this);
                        this.classList.remove("error");
                        this.style.borderColor = "";
                    } else if (currentValue !== defaultValue) {
                        if (latInput) latInput.value = "";
                        if (lngInput) lngInput.value = "";
                        this.setAttribute("data-place-selected", "false");
                        this.setAttribute("data-is-default", "false");
                        // Don't show error while typing — wait for blur
                    }
                    // Don't call checkFormValidity while typing
                });

                $(input).on("blur", function () {
                    const self = this;
                    setTimeout(function () {
                        const currentValue = self.value.trim();
                        const defaultValue = self.getAttribute("data-default-value") || "";
                        const defaultLat = latInput?.getAttribute("data-default-lat") || "";
                        const defaultLng = lngInput?.getAttribute("data-default-lng") || "";
                        const placeSelected = self.getAttribute("data-place-selected") === "true";

                        if (currentValue === "") {
                            self.setAttribute("data-place-selected", "false");
                            self.setAttribute("data-is-default", "false");
                            self.classList.remove("error");
                            self.style.borderColor = "";
                            removeInlineError(self);
                        } else if (placeSelected) {
                            // Valid selection — clear any leftover errors
                            self.classList.remove("error");
                            self.style.borderColor = "";
                            removeInlineError(self);
                        } else {
                            self.classList.add("error");
                            self.style.borderColor = "#dc3545";
                            showInlineError(self, "Please select a location from the dropdown");
                        }

                        checkFormValidity(form);
                    }, 400);
                });
            }
        }
    }

    /**
     * Update location data in hidden fields
     */
    function updateLocationData(input, place) {


        // For airport transfer form FROM
        if (input.name === "from") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="pickup_lat"]');
            const lngInput = form.querySelector('input[name="pickup_lng"]');

            if (place.geometry && latInput && lngInput) {
                const lat = typeof place.geometry.location.lat === 'function' ? place.geometry.location.lat() : parseFloat(place.geometry.location.lat);
                const lng = typeof place.geometry.location.lng === 'function' ? place.geometry.location.lng() : parseFloat(place.geometry.location.lng);
                latInput.value = lat;
                lngInput.value = lng;

                // Always prefer formatted_address over coordinates for user-visible input
                if ((!input.value || input.value.trim() === '') && place.formatted_address) {
                    input.value = place.formatted_address;
                }
            }
        } else if (input.name === "to") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="dropoff_lat"]');
            const lngInput = form.querySelector('input[name="dropoff_lng"]');

            if (place.geometry && latInput && lngInput) {
                const lat = typeof place.geometry.location.lat === 'function' ? place.geometry.location.lat() : parseFloat(place.geometry.location.lat);
                const lng = typeof place.geometry.location.lng === 'function' ? place.geometry.location.lng() : parseFloat(place.geometry.location.lng);
                latInput.value = lat;
                lngInput.value = lng;

                // Always prefer formatted_address over coordinates for user-visible input
                if ((!input.value || input.value.trim() === '') && place.formatted_address) {
                    input.value = place.formatted_address;
                }
            }
        } else if (input.name === "pickup") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="pickup_lat"]');
            const lngInput = form.querySelector('input[name="pickup_lng"]');

            if (place.geometry && latInput && lngInput) {
                const lat = typeof place.geometry.location.lat === 'function' ? place.geometry.location.lat() : parseFloat(place.geometry.location.lat);
                const lng = typeof place.geometry.location.lng === 'function' ? place.geometry.location.lng() : parseFloat(place.geometry.location.lng);
                latInput.value = lat;
                lngInput.value = lng;

                // Always prefer formatted_address over coordinates for user-visible input
                if ((!input.value || input.value.trim() === '') && place.formatted_address) {
                    input.value = place.formatted_address;
                }
            } else {
                console.error('Could not find pickup coordinate fields or place geometry');
            }
        } else if (input.name === "dropoff") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="dropoff_lat"]');
            const lngInput = form.querySelector('input[name="dropoff_lng"]');

            if (place.geometry && latInput && lngInput) {
                const lat = typeof place.geometry.location.lat === 'function' ? place.geometry.location.lat() : parseFloat(place.geometry.location.lat);
                const lng = typeof place.geometry.location.lng === 'function' ? place.geometry.location.lng() : parseFloat(place.geometry.location.lng);
                latInput.value = lat;
                lngInput.value = lng;

                // Always prefer formatted_address over coordinates for user-visible input
                if ((!input.value || input.value.trim() === '') && place.formatted_address) {
                    input.value = place.formatted_address;
                }
            }
        }
    }

    /**
     * Setup service type switcher
     */
    function setupServiceSwitcher() {
        const serviceItems = document.querySelectorAll(
            ".filter-item-list .single-item"
        );
        const forms = document.querySelectorAll(".filter-input");

        serviceItems.forEach((item) => {
            item.addEventListener("click", function () {
                const serviceType = this.getAttribute("data-service");
                const formServiceType =
                    this.getAttribute("data-form-service") || serviceType;
                switchService(
                    serviceType,
                    formServiceType,
                    serviceItems,
                    forms
                );
            });
        });

        // Ensure initial active tab always maps to the correct form on first render.
        const initialItem =
            document.querySelector(".filter-item-list .single-item.active") ||
            serviceItems[0];
        if (initialItem) {
            const serviceType = initialItem.getAttribute("data-service");
            const formServiceType =
                initialItem.getAttribute("data-form-service") || serviceType;
            switchService(serviceType, formServiceType, serviceItems, forms);
        }
    }

    /**
     * Switch between service types
     */
    function switchService(serviceType, formServiceType, items, forms) {
        state.currentService = serviceType;

        // Update active state
        items.forEach((item) => item.classList.remove("active"));
        const activeItem = document.querySelector(
            `.filter-item-list .single-item[data-service="${serviceType}"]`
        );
        if (activeItem) {
            activeItem.classList.add("active");
        }

        // Show corresponding form
        forms.forEach((form) => {
            if (form.getAttribute("data-service") === formServiceType) {
                form.classList.add("show");
            } else {
                form.classList.remove("show");
            }
        });

        // Re-initialize location search for new form
        setTimeout(initializeLocationSearch, 100);
    }

    /**
     * Setup Airport Transfer functionality
     */
    function setupAirportTransfer() {
        const transferTypeRadios = document.querySelectorAll(
            'input[name="transfer_type"]'
        );

        transferTypeRadios.forEach((radio) => {
            radio.addEventListener("change", function () {
                updateAirportTransferLocations(this.value);
            });
        });

        // Initialize defaults - ensure coordinates are set immediately
        const defaultRadio = document.querySelector(
            'input[name="transfer_type"]:checked'
        );
        if (defaultRadio) {
            updateAirportTransferLocations(defaultRadio.value);
        } else {
            // Default to from-airport if no radio is checked
            const fromAirportRadio = document.querySelector('input[name="transfer_type"][value="from-airport"]');
            if (fromAirportRadio) {
                fromAirportRadio.checked = true;
                updateAirportTransferLocations('from-airport');
            }
        }

        // Ensure airport select handlers are setup after a delay
        setTimeout(() => {
            setupAirportSelectHandlers();
            // Force another coordinate update after handlers are ready
            ensureAirportCoordinatesAreSet();
        }, 500);
    }

    /**
     * Update Airport Transfer locations
     */
    function updateAirportTransferLocations(type) {
        const form = document.getElementById("airport_transfers-form");
        if (!form) {
            console.error('Airport transfers form not found');
            return;
        }

        // Get all form elements
        const fromAirportSelect = form.querySelector('#from-airport-select');
        const fromAirportWrapper = form.querySelector('#from-airport-select_wrapper');
        const fromLocationInput = form.querySelector('#from-location-input');
        const toAirportSelect = form.querySelector('#to-airport-select');
        const toAirportWrapper = form.querySelector('#to-airport-select_wrapper');
        const toLocationInput = form.querySelector('#to-location-input');
        const fromLat = form.querySelector('input[name="pickup_lat"]');
        const fromLng = form.querySelector('input[name="pickup_lng"]');
        const toLat = form.querySelector('input[name="dropoff_lat"]');
        const toLng = form.querySelector('input[name="dropoff_lng"]');

        if (!fromAirportSelect || !fromLocationInput || !toAirportSelect || !toLocationInput ||
            !fromLat || !fromLng || !toLat || !toLng) {
            console.error('Missing airport transfer form elements:', {
                fromAirportSelect: !!fromAirportSelect,
                fromLocationInput: !!fromLocationInput,
                toAirportSelect: !!toAirportSelect,
                toLocationInput: !!toLocationInput,
                fromLat: !!fromLat,
                fromLng: !!fromLng,
                toLat: !!toLat,
                toLng: !!toLng
            });
            return;
        }

        const colomboCoords = CONFIG.cityCoordinates["Colombo, Sri Lanka"];
        const defaultAirport = "Colombo BIA Airport";
        const defaultAirportCoords = CONFIG.cityCoordinates[defaultAirport];

        // Helper to toggle wrapper + select visibility
        function showElement(el, wrapper) {
            if (wrapper) { wrapper.classList.remove('hidden'); wrapper.classList.add('visible'); }
            el.classList.remove('hidden');
            el.classList.add('visible');
        }
        function hideElement(el, wrapper) {
            if (wrapper) { wrapper.classList.remove('visible'); wrapper.classList.add('hidden'); }
            el.classList.remove('visible');
            el.classList.add('hidden');
        }

        // Clear any existing Google Places autocomplete instances
        [fromLocationInput, toLocationInput].forEach(input => {
            if (input.googleAutocomplete) {
                if (window.google && google.maps && google.maps.event) {
                    google.maps.event.clearInstanceListeners(input);
                }
                input.googleAutocomplete = null;
            }
            input.removeAttribute("data-autocomplete-initialized");

            // Destroy any existing jQuery autocomplete
            if (typeof $ !== "undefined" && $.fn.autocomplete && $(input).data("ui-autocomplete")) {
                $(input).autocomplete("destroy");
            }
        });

        if (type === "from-airport") {
            // Show FROM airport select, hide FROM location input
            showElement(fromAirportSelect, fromAirportWrapper);
            hideElement(fromLocationInput, null);
            fromAirportSelect.required = true;
            fromLocationInput.required = false;
            fromAirportSelect.disabled = false;
            fromLocationInput.disabled = true;

            // Show TO location input, hide TO airport select  
            showElement(toLocationInput, null);
            hideElement(toAirportSelect, toAirportWrapper);
            toLocationInput.required = true;
            toAirportSelect.required = false;
            toLocationInput.disabled = false;
            toAirportSelect.disabled = true;

            // Set default values ONLY if empty
            if (!fromAirportSelect.value || fromAirportSelect.value === '') {
                fromAirportSelect.value = defaultAirport;
                fromAirportSelect.dispatchEvent(new Event('airport-pls-sync'));
                // Only set default coordinates when setting default airport
                if (defaultAirportCoords && defaultAirportCoords.lat && defaultAirportCoords.lng) {
                    fromLat.value = defaultAirportCoords.lat;
                    fromLng.value = defaultAirportCoords.lng;
                }
            }
            if (!toLocationInput.value || toLocationInput.value.trim() === '') {
                toLocationInput.value = "Colombo, Sri Lanka";
                // Only set default coordinates when setting default location
                if (colomboCoords && colomboCoords.lat && colomboCoords.lng) {
                    toLat.value = colomboCoords.lat;
                    toLng.value = colomboCoords.lng;
                }
            }
            // If values exist (from search results), preserve their coordinates - don't reset

            // Sync PLS dropdown display for the visible airport select
            fromAirportSelect.dispatchEvent(new Event('airport-pls-sync'));

            // Initialize autocomplete for TO location input
            setTimeout(() => {
                initializeLocationInputAutocomplete(toLocationInput);
            }, 100);

        } else {
            // Show FROM location input, hide FROM airport select
            showElement(fromLocationInput, null);
            hideElement(fromAirportSelect, fromAirportWrapper);
            fromLocationInput.required = true;
            fromAirportSelect.required = false;
            fromLocationInput.disabled = false;
            fromAirportSelect.disabled = true;

            // Show TO airport select, hide TO location input
            showElement(toAirportSelect, toAirportWrapper);
            hideElement(toLocationInput, null);
            toAirportSelect.required = true;
            toLocationInput.required = false;
            toAirportSelect.disabled = false;
            toLocationInput.disabled = true;

            // Set default values ONLY if empty
            if (!fromLocationInput.value || fromLocationInput.value.trim() === '') {
                fromLocationInput.value = "Colombo, Sri Lanka";
                // Only set default coordinates when setting default location
                if (colomboCoords && colomboCoords.lat && colomboCoords.lng) {
                    fromLat.value = colomboCoords.lat;
                    fromLng.value = colomboCoords.lng;
                }
            }
            if (!toAirportSelect.value || toAirportSelect.value === '') {
                toAirportSelect.value = defaultAirport;
                toAirportSelect.dispatchEvent(new Event('airport-pls-sync'));
                // Only set default coordinates when setting default airport
                if (defaultAirportCoords && defaultAirportCoords.lat && defaultAirportCoords.lng) {
                    toLat.value = defaultAirportCoords.lat;
                    toLng.value = defaultAirportCoords.lng;
                }
            }
            // If values exist (from search results), preserve their coordinates - don't reset

            // Sync PLS dropdown display for the visible airport select
            toAirportSelect.dispatchEvent(new Event('airport-pls-sync'));

            // Initialize autocomplete for FROM location input
            setTimeout(() => {
                initializeLocationInputAutocomplete(fromLocationInput);
            }, 100);
        }

        // Setup airport select change handlers
        setupAirportSelectHandlers();

        // Force coordinate update for any pre-selected airports
        setTimeout(() => {
            if (fromAirportSelect && fromAirportSelect.value && fromAirportWrapper && !fromAirportWrapper.classList.contains('hidden')) {
                fromAirportSelect.dispatchEvent(new Event('change'));
            }
            if (toAirportSelect && toAirportSelect.value && toAirportWrapper && !toAirportWrapper.classList.contains('hidden')) {
                toAirportSelect.dispatchEvent(new Event('change'));
            }
        }, 200);
    }

    /**
     * Setup airport select change handlers
     */
    function setupAirportSelectHandlers() {
        const form = document.getElementById("airport_transfers-form");
        if (!form) {
            return;
        }

        const fromAirportSelect = form.querySelector('#from-airport-select');
        const toAirportSelect = form.querySelector('#to-airport-select');
        const fromLat = form.querySelector('input[name="pickup_lat"]');
        const fromLng = form.querySelector('input[name="pickup_lng"]');
        const toLat = form.querySelector('input[name="dropoff_lat"]');
        const toLng = form.querySelector('input[name="dropoff_lng"]');

        // FROM airport select handler
        if (fromAirportSelect && !fromAirportSelect.hasAirportHandler) {
            fromAirportSelect.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];

                if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                    if (fromLat && fromLng) {
                        fromLat.value = selectedOption.dataset.lat;
                        fromLng.value = selectedOption.dataset.lng;

                    }
                }
            });
            fromAirportSelect.hasAirportHandler = true;

            // Trigger change event if there's already a selected value
            if (fromAirportSelect.value) {
                fromAirportSelect.dispatchEvent(new Event('change'));
            }
        }

        // TO airport select handler  
        if (toAirportSelect && !toAirportSelect.hasAirportHandler) {
            toAirportSelect.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];

                if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                    if (toLat && toLng) {
                        toLat.value = selectedOption.dataset.lat;
                        toLng.value = selectedOption.dataset.lng;
                    }
                }
            });
            toAirportSelect.hasAirportHandler = true;

            // Trigger change event if there's already a selected value
            if (toAirportSelect.value) {
                toAirportSelect.dispatchEvent(new Event('change'));
            }
        }
    }

    /**
     * Initialize location autocomplete for a specific input
     */
    function initializeLocationInputAutocomplete(input) {
        if (!input || input.getAttribute("data-autocomplete-initialized") === "true") {
            return;
        }

        if (state.mapsLoaded && window.google && window.google.maps && window.google.maps.places) {
            // Use Google Places Autocomplete
            const autocomplete = new google.maps.places.Autocomplete(input, {
                componentRestrictions: { country: "lk" },
                fields: ["place_id", "geometry", "name", "formatted_address"],
            });

            autocomplete.addListener("place_changed", function () {
                const place = autocomplete.getPlace();
                if (place && place.geometry) {
                    updateLocationDataForInput(input, place);
                    input.setAttribute("data-place-selected", "true");
                    input.setAttribute("data-is-default", "false");
                    input.classList.remove("error");
                    input.style.borderColor = "";
                    removeInlineError(input);
                    checkFormValidity(input.closest("form"));
                }
            });

            input.googleAutocomplete = autocomplete;
            input.setAttribute("data-autocomplete-initialized", "true");
        } else {
            // Fallback to basic autocomplete
            if (typeof $ !== "undefined" && $.fn.autocomplete && !$(input).data("ui-autocomplete")) {
                $(input).autocomplete({
                    source: CONFIG.sriLankaCities,
                    minLength: 2,
                    select: function (event, ui) {
                        const coordinates = CONFIG.cityCoordinates[ui.item.value];
                        updateLocationDataForInput(input, {
                            formatted_address: ui.item.value,
                            geometry: coordinates ? {
                                location: {
                                    lat: () => coordinates.lat,
                                    lng: () => coordinates.lng,
                                },
                            } : null,
                        });
                        input.setAttribute("data-place-selected", "true");
                        input.setAttribute("data-is-default", "false");
                        input.classList.remove("error");
                        input.style.borderColor = "";
                        removeInlineError(input);
                        checkFormValidity(input.closest("form"));
                    },
                });
            }
        }
    }

    /**
     * Update location data for a specific input
     */
    function updateLocationDataForInput(input, place) {
        // Use the main updateLocationData function for consistency
        updateLocationData(input, place);
    }

    /**
     * Setup Drop & Pickup functionality
     */
    function setupDropPickup() {
        const needReturnCheckbox = document.getElementById("need-return");
        const returnTransferFields = document.getElementById(
            "return-transfer-fields"
        );

        if (needReturnCheckbox && returnTransferFields) {
            needReturnCheckbox.addEventListener("change", function () {
                toggleReturnTransfer(this.checked, returnTransferFields);
            });
        }
    }

    /**
     * Toggle return transfer fields
     */
    function toggleReturnTransfer(enabled, container) {
        if (enabled) {
            container.style.display = "grid";
            container.classList.add("show");
            container
                .querySelectorAll('input[type="text"]:not([readonly])')
                .forEach((input) => {
                    input.setAttribute("required", "required");
                });

            // Set return date to +3 days from today
            const returnDateInput = container.querySelector(
                'input[name="return_date"]'
            );
            if (returnDateInput && !returnDateInput.value) {
                const today = new Date();
                const threeDaysLater = new Date();
                threeDaysLater.setDate(today.getDate() + 3);
                returnDateInput.value = formatDate(threeDaysLater);
            }
        } else {
            container.style.display = "none";
            container.classList.remove("show");
            container.querySelectorAll("input").forEach((input) => {
                input.removeAttribute("required");
                input.value = "";
            });
        }

        // Re-initialize location search for return fields
        setTimeout(initializeLocationSearch, 100);
    }

    /**
     * Setup Custom Tour functionality
     */
    function setupCustomTour() {
        let destinationCounter = 0;

        // Add destination button (top)
        const addButton = document.getElementById("add-destination");
        if (addButton) {
            addButton.addEventListener("click", function () {
                destinationCounter++;
                addDestination(destinationCounter);
            });
        }

        // Add destination button (bottom)
        const addButtonBottom = document.getElementById(
            "add-destination-bottom"
        );
        if (addButtonBottom) {
            addButtonBottom.addEventListener("click", function () {
                destinationCounter++;
                addDestination(destinationCounter);

                // Scroll to the new destination
                setTimeout(() => {
                    const container = document.getElementById(
                        "destinations-container"
                    );
                    const lastDestination = container.lastElementChild;
                    if (lastDestination) {
                        lastDestination.scrollIntoView({
                            behavior: "smooth",
                            block: "center",
                        });
                    }
                }, 100);
            });
        }

        // Check route button
        const checkRouteBtn = document.getElementById("check-route-btn");
        if (checkRouteBtn) {
            checkRouteBtn.addEventListener("click", handleCheckRoute);
        }

        // Confirm route button
        const confirmRouteBtn = document.getElementById("confirmRoute");
        if (confirmRouteBtn) {
            confirmRouteBtn.addEventListener("click", handleConfirmRoute);
        }

        // Initialize drag-and-drop for existing destinations
        setupFormDragAndDrop();
    }

    /**
     * Add a new destination to custom tour
     */
    function addDestination(index) {
        const container = document.getElementById("destinations-container");
        if (!container) return;

        const destinationElement = createDestinationElement(index);
        container.appendChild(destinationElement);

        // Initialize location search, date pickers, and drag-and-drop for new destination
        setTimeout(() => {
            initializeLocationSearch();
            initializeDatePickers();
            setupFormDragAndDrop();
        }, 100);
    }

    /**
     * Create destination element HTML
     */
    function createDestinationElement(index) {
        const div = document.createElement("div");
        div.className = "destination-item";
        div.setAttribute("draggable", "true");
        div.setAttribute("data-index", index);

        div.innerHTML = `
            <div class="destination-item-header">
                <div class="d-flex align-items-center">
                    <div class="drag-handle me-2" style="cursor: grab;">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h6 class="destination-title mb-0">Destination ${index + 1
            }</h6>
                </div>
                <button type="button" class="destination-remove">
                    <svg width="12" height="12" viewBox="0 0 12 12" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 1L1 11M1 1L11 11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Remove
                </button>
            </div>
            
            <div class="destination-fields">
                <div class="destination-location">
                    <div class="single-search-box location-search-box">
                        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                                <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                            </g>
                        </svg>
                        <div class="custom-select-dropdown">
                            <input type="text" name="destinations[${index}][location]" placeholder="Destination Location" class="location-search" required>
                            <input type="hidden" name="destinations[${index}][lat]" class="location-lat">
                            <input type="hidden" name="destinations[${index}][lng]" class="location-lng">
                        </div>
                    </div>
                </div>
                <div class="destination-datetime">
                    <div class="single-search-box date-field">
                        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                        </svg>
                        <div class="custom-select-dropdown">
                            <input type="text" name="destinations[${index}][visit_date]" placeholder="Visit Date" class="custom-datepicker" required>
                        </div>
                    </div>
                    <div class="single-search-box">
                        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                        </svg>
                        <div class="custom-select-dropdown">
                            <input type="time" name="destinations[${index}][visit_time]" value="09:00" required>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="destination-notes">
                <textarea name="destinations[${index}][notes]" placeholder="Special notes for this destination (optional)" rows="2"></textarea>
            </div>
        `;

        // Add remove functionality
        div.querySelector(".destination-remove").addEventListener(
            "click",
            function () {
                div.remove();
                updateDestinationNumbers();
            }
        );

        return div;
    }

    /**
     * Update destination numbers after removal
     */
    function updateDestinationNumbers() {
        const destinations = document.querySelectorAll(".destination-item");
        destinations.forEach((dest, index) => {
            const title = dest.querySelector(".destination-title");
            if (title) {
                title.textContent = `Destination ${index + 1}`;
            }
        });
    }

    /**
     * Handle check route button click
     */
    function handleCheckRoute() {
        collectRouteData();

        if (state.customTourDestinations.length < 2) {
            alert(
                "Please add at least a starting location and one destination."
            );
            return;
        }

        if (state.mapsLoaded || window.L) {
            showRouteModal();
        } else {
            showSimpleRouteSummary();
        }
    }

    /**
     * Collect route data from form
     */
    function collectRouteData() {
        state.customTourDestinations = [];
        state.routeWaypoints = [];

        // Get starting location
        const startingLocation = document.querySelector(
            'input[name="starting_location"]'
        );
        const startingLat = document.querySelector(
            'input[name="starting_lat"]'
        );
        const startingLng = document.querySelector(
            'input[name="starting_lng"]'
        );

        if (
            startingLocation &&
            startingLocation.value &&
            startingLat &&
            startingLat.value
        ) {
            state.customTourDestinations.push({
                name: startingLocation.value,
                lat: parseFloat(startingLat.value),
                lng: parseFloat(startingLng.value),
                type: "start",
            });
        }

        // Get all destinations
        const destinationItems = document.querySelectorAll(".destination-item");
        destinationItems.forEach((item, index) => {
            const location = item.querySelector('input[name*="[location]"]');
            const lat = item.querySelector('input[name*="[lat]"]');
            const lng = item.querySelector('input[name*="[lng]"]');
            const date = item.querySelector('input[name*="[visit_date]"]');
            const time = item.querySelector('input[name*="[visit_time]"]');
            const notes = item.querySelector('textarea[name*="[notes]"]');

            if (location && location.value && lat && lat.value) {
                const destination = {
                    name: location.value,
                    lat: parseFloat(lat.value),
                    lng: parseFloat(lng.value),
                    date: date ? date.value : "",
                    time: time ? time.value : "",
                    notes: notes ? notes.value : "",
                    type: "destination",
                };

                state.customTourDestinations.push(destination);

                if (state.mapsLoaded && window.google) {
                    state.routeWaypoints.push({
                        location: new google.maps.LatLng(
                            destination.lat,
                            destination.lng
                        ),
                        stopover: true,
                    });
                }
            }
        });
    }

    /**
     * Show route modal
     */
    function showRouteModal() {
        const modal = new bootstrap.Modal(
            document.getElementById("routeModal")
        );
        modal.show();

        // Initialize map after modal is shown
        setTimeout(() => {
            initializeRouteMap();
            setupModalAddDestinationButton();
        }, 300);
    }

    /**
     * Setup "Add Destination" button in modal
     */
    function setupModalAddDestinationButton() {
        const addBtn = document.getElementById("addDestinationModal");
        if (!addBtn) return;

        // Remove existing listener to avoid duplicates
        const newBtn = addBtn.cloneNode(true);
        addBtn.parentNode.replaceChild(newBtn, addBtn);

        newBtn.addEventListener("click", function () {
            // Add new destination to state
            const newDestination = {
                name: "",
                lat: 0,
                lng: 0,
                date: "",
                time: "09:00",
                notes: "",
            };

            state.customTourDestinations.push(newDestination);

            // Update modal list
            updateDestinationList();

            // Update form
            updateFormFromState();

            // Initialize autocomplete for the newly added input
            setTimeout(() => {
                initializeModalLocationAutocomplete();

                // Scroll to the new destination in modal
                const destinationList =
                    document.getElementById("destinationList");
                if (destinationList) {
                    destinationList.scrollTop = destinationList.scrollHeight;
                }
            }, 100);
        });
    }

    /**
     * Initialize route map - Always use Leaflet/OpenStreetMap (FREE)
     */
    function initializeRouteMap() {
        const mapElement = document.getElementById("routeMap");
        if (!mapElement) return;

        // Always use Leaflet (OpenStreetMap) for route visualization - it's free!
        if (window.L) {
            initializeLeafletRouteMap(mapElement);
        } else {
            mapElement.innerHTML =
                '<div style="padding: 20px; text-align: center;">Loading map...</div>';
        }

        updateDestinationList();
    }

    /**
     * Google Maps is only used for location search autocomplete
     * NOT used for route visualization (to keep it free)
     */

    /**
     * Initialize Leaflet route map with numbered markers and proper route
     */
    function initializeLeafletRouteMap(mapElement) {
        // Clear existing map if any
        if (map) {
            map.remove();
        }

        map = L.map(mapElement).setView([7.8731, 80.7718], 8);

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "© OpenStreetMap contributors",
            maxZoom: 19,
        }).addTo(map);

        const markers = [];
        const routeCoordinates = [];
        let totalDistance = 0;

        state.customTourDestinations.forEach((dest, index) => {
            routeCoordinates.push([dest.lat, dest.lng]);

            // Create custom numbered marker
            let markerIcon;
            const isStart = index === 0;
            const isEnd = index === state.customTourDestinations.length - 1;

            if (isStart) {
                // Start marker (green)
                markerIcon = L.divIcon({
                    className: "custom-marker start-marker",
                    html: `<div class="marker-pin start-pin">
                            <span class="marker-label">START</span>
                        </div>`,
                    iconSize: [40, 40],
                    iconAnchor: [20, 40],
                    popupAnchor: [0, -40],
                });
            } else if (isEnd && state.customTourDestinations.length > 2) {
                // End marker (red) - only if there are more than 2 stops
                markerIcon = L.divIcon({
                    className: "custom-marker end-marker",
                    html: `<div class="marker-pin end-pin">
                            <span class="marker-label">END</span>
                        </div>`,
                    iconSize: [40, 40],
                    iconAnchor: [20, 40],
                    popupAnchor: [0, -40],
                });
            } else {
                // Numbered markers (blue)
                const stopNumber = isStart ? 1 : index;
                markerIcon = L.divIcon({
                    className: "custom-marker numbered-marker",
                    html: `<div class="marker-pin numbered-pin">
                            <span class="marker-number">${stopNumber}</span>
                        </div>`,
                    iconSize: [36, 36],
                    iconAnchor: [18, 36],
                    popupAnchor: [0, -36],
                });
            }

            const marker = L.marker([dest.lat, dest.lng], {
                icon: markerIcon,
            }).addTo(map);

            // Create popup content
            const popupContent = `
                <div class="route-marker-popup">
                    <h6>${isStart
                    ? "Starting Point"
                    : isEnd
                        ? "Final Destination"
                        : `Stop ${index}`
                }</h6>
                    <p><strong>${dest.name}</strong></p>
                    ${dest.date
                    ? `<p>📅 ${dest.date} ${dest.time || ""}</p>`
                    : ""
                }
                    ${dest.notes ? `<p class="notes">📝 ${dest.notes}</p>` : ""}
                </div>
            `;
            marker.bindPopup(popupContent);
            markers.push(marker);

            // Calculate distance from previous point
            if (index > 0) {
                const prevDest = state.customTourDestinations[index - 1];
                const distance = calculateDistance(
                    prevDest.lat,
                    prevDest.lng,
                    dest.lat,
                    dest.lng
                );
                totalDistance += distance;
            }
        });

        // Draw the route polyline connecting all points in order with segment numbers
        if (routeCoordinates.length > 1) {
            // Draw lines between each consecutive pair of points
            for (let i = 0; i < routeCoordinates.length - 1; i++) {
                const segmentStart = routeCoordinates[i];
                const segmentEnd = routeCoordinates[i + 1];

                // Draw the line segment
                L.polyline([segmentStart, segmentEnd], {
                    color: "#1a73e8",
                    weight: 4,
                    opacity: 0.8,
                    smoothFactor: 1,
                    lineJoin: "round",
                    lineCap: "round",
                }).addTo(map);

                // Add numbered label at the midpoint of each segment
                const midpoint = [
                    (segmentStart[0] + segmentEnd[0]) / 2,
                    (segmentStart[1] + segmentEnd[1]) / 2,
                ];

                const segmentNumber = i + 1; // Segment 1, 2, 3...
                const numberIcon = L.divIcon({
                    className: "route-segment-number",
                    html: `<div class="segment-number-label">${segmentNumber}</div>`,
                    iconSize: [28, 28],
                    iconAnchor: [14, 14],
                });

                L.marker(midpoint, { icon: numberIcon }).addTo(map);
            }
        }

        // Fit bounds to show all markers
        if (markers.length > 0) {
            const group = new L.featureGroup(markers);
            map.fitBounds(group.getBounds().pad(0.15));
        }

        displayLeafletRouteInformation(totalDistance);
    }

    /**
     * Add direction arrows along the route
     */
    function addDirectionArrows(coordinates) {
        for (let i = 0; i < coordinates.length - 1; i++) {
            const start = coordinates[i];
            const end = coordinates[i + 1];
            const midpoint = [(start[0] + end[0]) / 2, (start[1] + end[1]) / 2];

            // Calculate angle
            const angle =
                (Math.atan2(end[1] - start[1], end[0] - start[0]) * 180) /
                Math.PI;

            const arrowIcon = L.divIcon({
                className: "route-arrow",
                html: `<div style="transform: rotate(${angle + 90
                    }deg);">→</div>`,
                iconSize: [20, 20],
                iconAnchor: [10, 10],
            });

            L.marker(midpoint, { icon: arrowIcon }).addTo(map);
        }
    }

    /**
     * Display Google route information
     */
    function displayGoogleRouteInformation(result) {
        const route = result.routes[0];
        let totalDistance = 0;
        let totalDuration = 0;

        route.legs.forEach((leg) => {
            totalDistance += leg.distance.value;
            totalDuration += leg.duration.value;
        });

        document.getElementById("totalDistance").textContent =
            (totalDistance / 1000).toFixed(1) + " km";
        document.getElementById("totalDuration").textContent =
            Math.round(totalDuration / 60) + " minutes";
        document.getElementById("totalStops").textContent =
            state.customTourDestinations.length;
    }

    /**
     * Display Leaflet route information
     */
    function displayLeafletRouteInformation(totalDistance) {
        document.getElementById("totalDistance").textContent =
            totalDistance.toFixed(1) + " km";
        document.getElementById("totalDuration").textContent =
            Math.round(totalDistance * 1.5) + " minutes";
        document.getElementById("totalStops").textContent =
            state.customTourDestinations.length;
    }

    /**
     * Display fallback route information
     */
    function displayFallbackRouteInformation() {
        let totalDistance = 0;

        for (let i = 1; i < state.customTourDestinations.length; i++) {
            const prev = state.customTourDestinations[i - 1];
            const curr = state.customTourDestinations[i];
            totalDistance += calculateDistance(
                prev.lat,
                prev.lng,
                curr.lat,
                curr.lng
            );
        }

        document.getElementById("totalDistance").textContent =
            totalDistance.toFixed(1) + " km (estimated)";
        document.getElementById("totalDuration").textContent =
            Math.round(totalDistance * 1.5) + " minutes (estimated)";
        document.getElementById("totalStops").textContent =
            state.customTourDestinations.length;
    }

    /**
     * Calculate distance between two points
     */
    function calculateDistance(lat1, lng1, lat2, lng2) {
        const R = 6371;
        const dLat = ((lat2 - lat1) * Math.PI) / 180;
        const dLng = ((lng2 - lng1) * Math.PI) / 180;
        const a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLng / 2) *
            Math.sin(dLng / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    /**
     * Update destination list in modal with editable form fields and drag-and-drop support
     */
    function updateDestinationList() {
        const listElement = document.getElementById("destinationList");
        if (!listElement) return;

        listElement.innerHTML = "";

        state.customTourDestinations.forEach((dest, index) => {
            const item = document.createElement("div");
            const isStart = index === 0;
            const isEnd = index === state.customTourDestinations.length - 1;

            // Start location is NOT draggable (locked)
            item.className = isStart
                ? "destination-item-modal start-location-locked start-card mb-3"
                : "destination-item-modal mb-3";

            // Only set draggable for non-start items
            if (!isStart) {
                item.setAttribute("draggable", "true");
            }
            item.setAttribute("data-index", index);

            item.innerHTML = `
                <div class="modal-destination-card">
                    <div class="modal-destination-header">
                        <div class="d-flex align-items-center">
                            <div class="${isStart
                    ? "drag-handle-locked"
                    : "drag-handle-modal"
                } me-2">${isStart ? "🔒" : "☰"}</div>
                            <strong>${isStart
                    ? "🚀 Starting Point"
                    : isEnd
                        ? "🏁 Final Destination"
                        : `📍 Stop ${index}`
                }</strong>
                        </div>
                        <span class="badge ${isStart
                    ? "bg-success"
                    : isEnd
                        ? "bg-danger"
                        : "bg-primary"
                }">${index + 1}</span>
                    </div>
                    
                    <div class="modal-destination-form mt-2">
                        <div class="mb-2">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control form-control-sm location-input-modal" 
                                   data-index="${index}" value="${dest.name
                }" placeholder="Enter location">
                        </div>
                        
                        ${!isStart
                    ? `
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <label class="form-label">Visit Date</label>
                                <input type="text" class="form-control form-control-sm date-input-modal" 
                                       data-index="${index}" value="${dest.date || ""
                    }" placeholder="Select date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Visit Time</label>
                                <input type="time" class="form-control form-control-sm time-input-modal" 
                                       data-index="${index}" value="${dest.time || "09:00"
                    }">
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea class="form-control form-control-sm notes-input-modal" 
                                      data-index="${index}" rows="2" placeholder="Add notes...">${dest.notes || ""
                    }</textarea>
                        </div>
                        `
                    : ""
                }
                        
                        ${index > 0
                    ? `
                        <button type="button" class="btn btn-sm btn-danger remove-dest-modal" data-index="${index}">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                        `
                    : ""
                }
                    </div>
                </div>
            `;

            // Add event listeners for form inputs
            setupModalFormListeners(item, index);

            // Add drag event listeners for modal list (but NOT for start location)
            if (!isStart) {
                setupDragAndDrop(item, "modal");
            }

            listElement.appendChild(item);
        });

        // Initialize autocomplete for all modal location inputs
        // Use longer delay to ensure modal is fully rendered
        setTimeout(() => {
            initializeModalLocationAutocomplete();
        }, 300);
    }

    /**
     * Initialize Google Places Autocomplete specifically for modal inputs
     * This ensures the autocomplete dropdown appears correctly
     * Call this whenever new inputs are added to the modal
     */
    function initializeModalLocationAutocomplete() {
        const modalLocationInputs = document.querySelectorAll(
            ".location-input-modal"
        );

        modalLocationInputs.forEach((input, idx) => {
            const dataIndex = input.getAttribute("data-index");

            // Skip if already initialized
            if (input.dataset.googleAutocompleteInitialized === "true") {
                return;
            }

            // Check if Google Maps API is loaded
            if (
                window.google &&
                window.google.maps &&
                window.google.maps.places
            ) {
                const autocomplete = new google.maps.places.Autocomplete(
                    input,
                    {
                        componentRestrictions: { country: "lk" },
                        fields: [
                            "place_id",
                            "geometry",
                            "name",
                            "formatted_address",
                        ],
                    }
                );

                autocomplete.addListener("place_changed", function () {
                    const place = autocomplete.getPlace();
                    const index = parseInt(input.getAttribute("data-index"));


                    if (place.geometry && !isNaN(index)) {
                        // Update state with new location
                        state.customTourDestinations[index].name =
                            place.formatted_address || place.name;
                        state.customTourDestinations[index].lat =
                            place.geometry.location.lat();
                        state.customTourDestinations[index].lng =
                            place.geometry.location.lng();

                        // Update the input value
                        input.value = place.formatted_address || place.name;

                        // Sync to form and refresh map
                        updateFormFromState();
                        setTimeout(() => initializeRouteMap(), 200);
                    }
                });

                // Mark as initialized
                input.dataset.googleAutocompleteInitialized = "true";

            } else {

                // Fallback to basic autocomplete if available
                if (typeof $ !== "undefined" && $.fn.autocomplete) {
                    $(input).autocomplete({
                        source: CONFIG.sriLankaCities,
                        minLength: 2,
                        select: function (event, ui) {
                            const index = parseInt(
                                input.getAttribute("data-index")
                            );
                            const coordinates =
                                CONFIG.cityCoordinates[ui.item.value];

                            if (!isNaN(index) && coordinates) {
                                state.customTourDestinations[index].name =
                                    ui.item.value;
                                state.customTourDestinations[index].lat =
                                    coordinates.lat;
                                state.customTourDestinations[index].lng =
                                    coordinates.lng;

                                updateFormFromState();
                                setTimeout(() => initializeRouteMap(), 200);
                            }
                        },
                    });

                    input.dataset.googleAutocompleteInitialized = "true";
                }
            }
        });
    }

    /**
     * Setup event listeners for modal form inputs
     */
    function setupModalFormListeners(itemElement, index) {
        // Location input - Initialize autocomplete and handle changes
        const locationInput = itemElement.querySelector(
            ".location-input-modal"
        );
        if (locationInput) {
            // Manual input change (not from autocomplete)
            locationInput.addEventListener("blur", function () {
                if (this.value !== state.customTourDestinations[index].name) {
                    state.customTourDestinations[index].name = this.value;
                    updateFormFromState();

                    // If location changed, update map
                    setTimeout(() => initializeRouteMap(), 200);
                }
            });
        }

        // Date input
        const dateInput = itemElement.querySelector(".date-input-modal");
        if (dateInput) {
            // Initialize datepicker on modal date inputs
            if (typeof $.fn.datepicker !== "undefined") {
                $(dateInput)
                    .datepicker({
                        format: "dd/mm/yyyy",
                        startDate: new Date(),
                        autoclose: true,
                        todayHighlight: true,
                        beforeShowDay: function (date) {
                            // Disable dates before today
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);
                            // Return [enabled, css_class] - true to enable, false to disable
                            return [date >= today, ""];
                        },
                    })
                    .on("changeDate", function (e) {
                        const formattedDate = formatDate(e.date);
                        state.customTourDestinations[index].date =
                            formattedDate;
                        this.value = formattedDate;
                        updateFormFromState();
                    });
            }
        }

        // Time input
        const timeInput = itemElement.querySelector(".time-input-modal");
        if (timeInput) {
            timeInput.addEventListener("change", function () {
                state.customTourDestinations[index].time = this.value;
                updateFormFromState();
            });
        }

        // Notes input
        const notesInput = itemElement.querySelector(".notes-input-modal");
        if (notesInput) {
            notesInput.addEventListener("blur", function () {
                state.customTourDestinations[index].notes = this.value;
                updateFormFromState();
            });
        }

        // Remove button
        const removeBtn = itemElement.querySelector(".remove-dest-modal");
        if (removeBtn) {
            removeBtn.addEventListener("click", function () {
                const idx = parseInt(this.getAttribute("data-index"));

                // Don't allow removing the starting point
                if (idx === 0) {
                    alert("Cannot remove the starting point");
                    return;
                }

                // Remove from state
                state.customTourDestinations.splice(idx, 1);

                // Update everything
                updateDestinationList();
                updateFormFromState();
                setTimeout(() => initializeRouteMap(), 150);
            });
        }
    }

    /**
     * Update form fields from state (sync modal changes to main form)
     */
    function updateFormFromState() {
        // Update starting location if changed
        if (state.customTourDestinations.length > 0) {
            const startDest = state.customTourDestinations[0];
            const startingLocation = document.querySelector(
                'input[name="starting_location"]'
            );
            const startingLat = document.querySelector(
                'input[name="starting_lat"]'
            );
            const startingLng = document.querySelector(
                'input[name="starting_lng"]'
            );

            if (startingLocation && startDest.name) {
                startingLocation.value = startDest.name;
            }
            if (startingLat && startDest.lat) {
                startingLat.value = startDest.lat;
            }
            if (startingLng && startDest.lng) {
                startingLng.value = startDest.lng;
            }
        }

        // Rebuild destinations in the main form
        updateFormDestinations();
    }

    /**
     * Setup drag-and-drop functionality - FIXED VERSION with start location lock
     */
    function setupDragAndDrop(element, context) {
        element.addEventListener("dragstart", function (e) {
            const index = parseInt(this.getAttribute("data-index"));

            // PREVENT dragging of start location (index 0)
            if (index === 0) {
                e.preventDefault();
                return false;
            }

            e.dataTransfer.effectAllowed = "move";
            e.dataTransfer.setData("text/plain", index);
            this.classList.add("dragging");

            // Store the dragged index globally
            this.dataset.dragging = "true";
        });

        element.addEventListener("dragend", function (e) {
            this.classList.remove("dragging");
            delete this.dataset.dragging;

            // Remove all drag-over classes
            document.querySelectorAll(".drag-over").forEach((el) => {
                el.classList.remove("drag-over");
            });
        });

        element.addEventListener("dragover", function (e) {
            e.preventDefault();

            const dropIndex = parseInt(this.getAttribute("data-index"));

            // PREVENT dropping on start location (index 0)
            if (dropIndex === 0) {
                e.dataTransfer.dropEffect = "none";
                return false;
            }

            e.dataTransfer.dropEffect = "move";

            // Only add drag-over class if not dragging this element
            const isDragging = this.dataset.dragging === "true";
            if (!isDragging) {
                this.classList.add("drag-over");
            }
        });

        element.addEventListener("dragleave", function (e) {
            // Only remove if we're actually leaving this element
            if (e.target === this) {
                this.classList.remove("drag-over");
            }
        });

        element.addEventListener("drop", function (e) {
            e.preventDefault();
            e.stopPropagation();

            this.classList.remove("drag-over");

            const dropIndex = parseInt(this.getAttribute("data-index"));

            // PREVENT dropping on start location (index 0)
            if (dropIndex === 0) {
                return false;
            }

            // Don't drop on itself
            if (this.dataset.dragging === "true") {
                return false;
            }

            const draggedIndex = parseInt(e.dataTransfer.getData("text/plain"));

            // PREVENT moving start location (index 0)
            if (draggedIndex === 0) {
                return false;
            }

            if (
                !isNaN(draggedIndex) &&
                !isNaN(dropIndex) &&
                draggedIndex !== dropIndex
            ) {
                // Reorder the destinations array
                reorderDestinations(draggedIndex, dropIndex);

                // Update both modal and form
                updateDestinationList();
                updateFormDestinations();

                // Refresh map after reordering
                setTimeout(() => {
                    initializeRouteMap();
                }, 150);
            }

            return false;
        });
    }

    /**
     * Reorder destinations in the state array
     */
    function reorderDestinations(fromIndex, toIndex) {
        const item = state.customTourDestinations[fromIndex];
        state.customTourDestinations.splice(fromIndex, 1);
        state.customTourDestinations.splice(toIndex, 0, item);
    }

    /**
     * Update form destinations after reordering
     */
    function updateFormDestinations() {
        const destinationItems = document.querySelectorAll(
            "#destinations-container .destination-item"
        );

        // Rebuild the destinations in the form based on the state
        const container = document.getElementById("destinations-container");
        if (!container) return;

        container.innerHTML = "";

        // Skip the first item (starting location is separate)
        state.customTourDestinations.slice(1).forEach((dest, index) => {
            const destElement = createDestinationElementFromData(dest, index);
            container.appendChild(destElement);
        });

        // Reinitialize location search and date pickers
        setTimeout(() => {
            initializeLocationSearch();
            initializeDatePickers();
            setupFormDragAndDrop();
        }, 100);
    }

    /**
     * Create destination element from existing data
     */
    function createDestinationElementFromData(destData, index) {
        const div = document.createElement("div");
        div.className = "destination-item";
        div.setAttribute("draggable", "true");
        div.setAttribute("data-index", index + 1); // +1 because start is index 0

        div.innerHTML = `
            <div class="destination-item-header">
                <div class="d-flex align-items-center">
                    <div class="drag-handle me-2" style="cursor: grab;">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h6 class="destination-title mb-0">Destination ${index + 1
            }</h6>
                </div>
                <button type="button" class="destination-remove">
                    <svg width="12" height="12" viewBox="0 0 12 12" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 1L1 11M1 1L11 11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Remove
                </button>
            </div>
            
            <div class="destination-fields">
                <div class="destination-location">
                    <div class="single-search-box location-search-box">
                        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                                <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                            </g>
                        </svg>
                        <div class="custom-select-dropdown">
                            <input type="text" name="destinations[${index}][location]" placeholder="Destination Location" class="location-search" value="${destData.name
            }" required>
                            <input type="hidden" name="destinations[${index}][lat]" class="location-lat" value="${destData.lat
            }">
                            <input type="hidden" name="destinations[${index}][lng]" class="location-lng" value="${destData.lng
            }">
                        </div>
                    </div>
                </div>
                <div class="destination-datetime">
                    <div class="single-search-box date-field">
                        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                        </svg>
                        <div class="custom-select-dropdown">
                            <input type="text" name="destinations[${index}][visit_date]" placeholder="Visit Date" class="custom-datepicker" value="${destData.date || ""
            }" required>
                        </div>
                    </div>
                    <div class="single-search-box">
                        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                        </svg>
                        <div class="custom-select-dropdown">
                            <input type="time" name="destinations[${index}][visit_time]" value="${destData.time || "09:00"
            }" required>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="destination-notes">
                <textarea name="destinations[${index}][notes]" placeholder="Special notes for this destination (optional)" rows="2">${destData.notes || ""
            }</textarea>
            </div>
        `;

        // Add remove functionality
        div.querySelector(".destination-remove").addEventListener(
            "click",
            function () {
                div.remove();
                // Remove from state
                state.customTourDestinations.splice(index + 1, 1);
                updateDestinationNumbers();
                updateFormDestinations();
            }
        );

        return div;
    }

    /**
     * Setup drag-and-drop for form destinations
     */
    function setupFormDragAndDrop() {
        const destinationItems = document.querySelectorAll(
            "#destinations-container .destination-item"
        );
        destinationItems.forEach((item) => {
            setupDragAndDrop(item, "form");
        });
    }

    /**
     * Show simple route summary when maps are not available
     */
    function showSimpleRouteSummary() {
        let summary = "Custom Tour Route Summary:\n\n";

        state.customTourDestinations.forEach((dest, index) => {
            summary += `${index === 0 ? "Start" : `Stop ${index}`}: ${dest.name
                }\n`;
            if (dest.date) summary += `Date: ${dest.date} ${dest.time || ""}\n`;
            if (dest.notes) summary += `Notes: ${dest.notes}\n`;
            summary += "\n";
        });

        summary += `Total destinations: ${state.customTourDestinations.length}\n`;
        summary +=
            "Note: Map visualization is not available. Route details will be calculated by our team.";

        alert(summary);
    }

    /**
     * Handle confirm route
     */
    function handleConfirmRoute() {
        const modal = bootstrap.Modal.getInstance(
            document.getElementById("routeModal")
        );
        if (modal) modal.hide();

        alert("Route confirmed! You can now proceed with the booking.");
    }

    /**
     * Initialize date pickers with proper value binding and validation
     */
    function initializeDatePickers() {
        // Use Litepicker for better UX with month/year selectors
        if (typeof Litepicker !== 'undefined') {
            $(".custom-datepicker").each(function () {
                // Skip if already initialized
                if (this._litepicker) {
                    return;
                }

                const input = this;
                const minDate = $(input).data('min-date') || new Date();
                const maxDate = $(input).data('max-date') || null;

                const picker = new Litepicker({
                    element: input,
                    format: 'DD/MM/YYYY',
                    minDate: minDate,
                    maxDate: maxDate,
                    autoApply: true,
                    singleMode: true,
                    numberOfMonths: 1,
                    numberOfColumns: 1,
                    showTooltip: false,
                    dropdowns: {
                        minYear: new Date().getFullYear(),
                        maxYear: new Date().getFullYear() + 2,
                        months: true,
                        years: true
                    },
                    buttonText: {
                        apply: 'Select',
                        cancel: 'Cancel'
                    },
                    setup: (picker) => {
                        picker.on('selected', (date) => {
                            $(input).trigger('change');
                            validateDate(input);
                        });
                        picker.on('hide', () => {
                            validateDate(input);
                        });
                    }
                });

                // Store reference
                input._litepicker = picker;
            });

            // Add validation on blur/change
            $(".custom-datepicker").on("blur change", function () {
                validateDate(this);
            });
            return;
        }

        // Fallback to Flatpickr if Litepicker not available
        if (typeof flatpickr !== 'undefined') {
            $(".custom-datepicker").each(function () {
                if (this._flatpickr) {
                    return;
                }

                const input = this;
                const minDate = $(input).data('min-date') || 'today';
                const maxDate = $(input).data('max-date') || null;
                const dateFormat = $(input).data('date-format') || 'd/m/Y';

                flatpickr(input, {
                    dateFormat: dateFormat,
                    minDate: minDate,
                    maxDate: maxDate,
                    allowInput: false,
                    clickOpens: true,
                    disableMobile: true,
                    showMonths: 1,
                    locale: {
                        firstDayOfWeek: 1
                    },
                    onChange: function (selectedDates, dateStr, instance) {
                        $(input).trigger('change');
                        validateDate(input);
                    },
                    onClose: function (selectedDates, dateStr, instance) {
                        validateDate(input);
                    }
                });
            });

            $(".custom-datepicker").on("blur change", function () {
                validateDate(this);
            });
            return;
        }

        // Fallback to Bootstrap Datepicker if Flatpickr not available
        if (typeof $ !== "undefined" && $.fn.datepicker) {
            $(".custom-datepicker").each(function () {
                if ($(this).data("datepicker")) {
                    $(this).datepicker("destroy");
                }
            });

            // Initialize with proper settings and value binding
            $(".custom-datepicker")
                .datepicker({
                    format: "dd/mm/yyyy",
                    startDate: new Date(),
                    endDate: "+1y",
                    autoclose: true,
                    todayHighlight: true,
                    orientation: "bottom auto",
                    container: "body",
                    zIndexOffset: 1000,
                    beforeShowDay: function (date) {
                        // Disable dates before today
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        // Return [enabled, css_class] - true to enable, false to disable
                        return [date >= today, ""];
                    },
                })
                .on("changeDate", function (e) {
                    // Ensure the selected date is properly set in the input
                    const selectedDate = e.date;
                    const formattedDate = formatDate(selectedDate);
                    $(this).val(formattedDate);
                    $(this).trigger("change");

                    // Validate the date
                    validateDate(this);
                })
                .on("hide", function (e) {
                    // Validate when datepicker closes
                    validateDate(this);
                });

            // Add manual input validation
            $(".custom-datepicker").on("blur change", function () {
                validateDate(this);
            });
        } else {
            // Fallback to HTML5 date input
            $(".custom-datepicker").each(function () {
                this.type = "date";
                this.min = new Date().toISOString().split("T")[0];

                // Add validation for HTML5 date input
                $(this).on("change blur", function () {
                    validateDate(this);
                });
            });
        }
    }

    /**
     * Format date to dd/mm/yyyy
     */
    function formatDate(date) {
        const day = String(date.getDate()).padStart(2, "0");
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    }

    /**
     * Validate date input - disallow past dates
     */
    function validateDate(input) {
        const $input = $(input);
        const value = $input.val();

        if (!value) return true;

        let selectedDate;

        // Parse the date based on format
        if (input.type === "date") {
            // HTML5 date input (yyyy-mm-dd)
            selectedDate = new Date(value);
        } else {
            // Parse dd/mm/yyyy format
            const parts = value.split("/");
            if (parts.length === 3) {
                selectedDate = new Date(parts[2], parts[1] - 1, parts[0]);
            }
        }

        if (!selectedDate || isNaN(selectedDate.getTime())) {
            showDateError(input, "Invalid date format");
            return false;
        }

        // Check if date is in the past
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        selectedDate.setHours(0, 0, 0, 0);

        if (selectedDate < today) {
            showDateError(
                input,
                "Past dates are not allowed. Please select today or a future date."
            );
            $input.val(""); // Clear the invalid value
            return false;
        }

        // Clear any error
        clearDateError(input);
        return true;
    }

    /**
     * Show date validation error
     */
    function showDateError(input, message) {
        const $input = $(input);
        const $parent = $input.closest(
            ".single-search-box, .destination-datetime"
        );

        // Remove existing error
        clearDateError(input);

        // Add error styling
        $parent.addClass("has-error");

        // Add error message
        const $error = $('<div class="date-error-message"></div>').text(
            message
        );
        $parent.append($error);
    }

    /**
     * Clear date validation error
     */
    function clearDateError(input) {
        const $input = $(input);
        const $parent = $input.closest(
            ".single-search-box, .destination-datetime"
        );

        $parent.removeClass("has-error");
        $parent.find(".date-error-message").remove();
    }

    /**
     * Ensure canonical search field names exist in the form before submission.
     * Copies visible/alternate inputs into canonical names the backend expects.
     */
    function ensureCanonicalSearchFields(form) {
        function getFirstValue(names) {
            for (let i = 0; i < names.length; i++) {
                const el = form.querySelector('[name="' + names[i] + '"]');
                if (el && typeof el.value !== 'undefined' && el.value !== null && String(el.value).trim() !== '') {
                    return String(el.value).trim();
                }
            }
            return '';
        }

        function ensureHidden(name, value) {
            let input = form.querySelector('[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.appendChild(input);
            }
            input.value = value || '';
        }

        // Pickup address and coords
        const pickupAddress = getFirstValue(['pickup_location', 'pickup', 'from', 'from_location', 'pickup_address']);
        const pickupLat = getFirstValue(['pickup_lat', 'pickup_latitude', 'from_lat', 'from_latitude']);
        const pickupLng = getFirstValue(['pickup_lng', 'pickup_longitude', 'from_lng', 'from_longitude']);
        ensureHidden('pickup_location', pickupAddress);
        ensureHidden('pickup_lat', pickupLat);
        ensureHidden('pickup_lng', pickupLng);

        // Dropoff address and coords
        const dropoffAddress = getFirstValue(['dropoff_location', 'dropoff', 'to', 'to_location', 'dropoff_address']);
        const dropoffLat = getFirstValue(['dropoff_lat', 'dropoff_latitude', 'to_lat', 'to_latitude']);
        const dropoffLng = getFirstValue(['dropoff_lng', 'dropoff_longitude', 'to_lng', 'to_longitude']);
        ensureHidden('dropoff_location', dropoffAddress);
        ensureHidden('dropoff_lat', dropoffLat);
        ensureHidden('dropoff_lng', dropoffLng);

        // Dates / times - normalize to keys backend expects
        const date = getFirstValue(['date', 'from_date', 'pickup_date']);
        const toDate = getFirstValue(['to_date', 'return_date', 'dropoff_date']);
        const fromTime = getFirstValue(['time', 'from_time', 'pickup_time']);
        const toTime = getFirstValue(['to_time', 'return_time', 'dropoff_time']);
        ensureHidden('date', date);
        ensureHidden('from_date', date);
        ensureHidden('pickup_date', date);
        ensureHidden('to_date', toDate);
        ensureHidden('return_date', toDate);
        ensureHidden('time', fromTime);
        ensureHidden('from_time', fromTime);
        ensureHidden('pickup_time', fromTime);
        ensureHidden('to_time', toTime);
        ensureHidden('return_time', toTime);

        // Service type
        const svc = getFirstValue(['service_type']) || form.getAttribute('data-service') || '';
        ensureHidden('service_type', svc);

        // Package selection
        const packageId = getFirstValue(['package_id', 'service_package_id']);
        ensureHidden('package_id', packageId);
        ensureHidden('service_package_id', packageId);
    }

    /**
     * Setup form validation
     */
    function setupFormValidation() {
        const forms = document.querySelectorAll(".filter-input");

        forms.forEach((form) => {
            form.addEventListener("submit", function (e) {
                // Keep configured defaults visually hidden until Search is pressed.
                // At submission time, fill untouched controls so both custom and
                // server-side validation receive the same dynamic fallback values.
                applyConfiguredSearchDefaults(form);

                // ALWAYS validate FIRST - prevent submission if invalid
                if (!validateForm(form)) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();

                    console.error('Form submission BLOCKED - validation failed');
                    showValidationMessage("Please select valid locations from the dropdown");
                    return false;
                }

                // Debug: Log form data including coordinates before submission
                const formData = new FormData(form);
                const formDataObj = {};
                formData.forEach((value, key) => {
                    formDataObj[key] = value;
                });

                console.log("Form submission ALLOWED - All form data:", formDataObj);

                // Ensure canonical fields exist and contain values before the form is submitted.
                // This prevents disabled inputs (e.g. airport selects) or alternate field names from
                // being omitted from the request and causing the backend to fall back to defaults.
                try {
                    ensureCanonicalSearchFields(form);
                } catch (err) {
                    console.warn('ensureCanonicalSearchFields failed', err);
                }
            });

            // Add visual feedback for required fields (success/error borders)
            const requiredInputs = form.querySelectorAll("input[required], select[required]");
            requiredInputs.forEach((input) => {
                input.addEventListener("blur", validateField);
                input.addEventListener("input", validateField);
            });

            // Check form validity on load
            setTimeout(() => checkFormValidity(form), 500);
        });
    }

    function applyConfiguredSearchDefaults(form) {
        const encodedDefaults = form.dataset.fieldDefaults || '';
        if (!encodedDefaults) return;

        let defaults = {};
        try {
            const binary = atob(encodedDefaults);
            const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
            defaults = JSON.parse(new TextDecoder().decode(bytes));
        } catch (error) {
            console.warn('Unable to read configured booking defaults', error);
            return;
        }

        const resolveValue = (value, input) => {
            if (typeof value !== 'string') return value;
            const token = value.trim().toLowerCase();
            const isDate = input.classList.contains('custom-datepicker') || input.type === 'date';
            const isTime = input.type === 'time';
            const now = new Date();

            if (isDate && (token === 'today' || token === 'tomorrow' || /^\+\d+\s*days?$/.test(token))) {
                if (token === 'tomorrow') now.setDate(now.getDate() + 1);
                const dayMatch = token.match(/^\+(\d+)\s*days?$/);
                if (dayMatch) now.setDate(now.getDate() + Number(dayMatch[1]));
                const day = String(now.getDate()).padStart(2, '0');
                const month = String(now.getMonth() + 1).padStart(2, '0');
                return `${day}/${month}/${now.getFullYear()}`;
            }
            if (isTime && (token === 'now' || token === 'current_time')) {
                return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
            }
            return value;
        };

        Object.entries(defaults).forEach(([name, configuredValue]) => {
            const controls = Array.from(form.querySelectorAll(`[name="${CSS.escape(name)}"]`));
            const control = controls.find((candidate) => !candidate.disabled && candidate.offsetParent !== null)
                || controls.find((candidate) => !candidate.disabled)
                || controls[0];
            if (!control) return;

            if (control.type === 'radio' || control.type === 'checkbox') {
                const matchingControl = controls.find((candidate) => String(candidate.value) === String(configuredValue));
                if (matchingControl && !controls.some((candidate) => candidate.checked)) {
                    matchingControl.checked = true;
                }
                return;
            }

            if (control.classList.contains('airport-select') && String(control.value || '').trim() === '') {
                const configuredOption = Array.from(control.options).find(
                    (option) => String(option.value) === String(configuredValue)
                );
                const defaultAirportValue = control.dataset.defaultAirportValue || '';
                control.value = configuredOption ? configuredOption.value : defaultAirportValue;
                if (control.value) {
                    control.setAttribute('data-is-default', 'true');
                    control.dispatchEvent(new Event('change', { bubbles: true }));
                    control.dispatchEvent(new Event('airport-pls-sync'));
                }
                return;
            }

            if (String(control.value || '').trim() === '') {
                control.value = resolveValue(configuredValue, control);
                if (control.classList.contains('location-search') || ['pickup', 'dropoff', 'from', 'to'].includes(control.name)) {
                    control.setAttribute('data-is-default', 'true');
                }
                control.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    /**
     * Validate form
     */
    function validateForm(form) {
        const requiredInputs = form.querySelectorAll(
            "input[required], textarea[required]"
        );
        let isValid = true;
        let errorMessages = [];

        requiredInputs.forEach((input) => {
            // Skip disabled or hidden inputs (e.g., inactive conditional variants)
            if (input.disabled || input.offsetParent === null) {
                return;
            }
            
            if (!input.value.trim()) {
                isValid = false;
                input.classList.add("error");
                input.style.borderColor = "#dc3545";
                console.log('VALIDATION FAIL: Required field empty -', input.name, input.type, input.id);
            } else {
                input.classList.remove("error");
                input.style.borderColor = "";
            }
        });
        
        console.log('After required inputs check, isValid =', isValid);

        // Validate location inputs - ensure they were selected from autocomplete or are defaults
        // Skip this validation for airport_transfers form (uses conditional location fields)
        if (form.id !== 'airport_transfers-form') {
            const locationInputs = form.querySelectorAll('.location-search, input[name="pickup"], input[name="dropoff"], input[name="from"], input[name="to"]');
            locationInputs.forEach((input) => {
                // Skip disabled inputs (e.g. PLS custom input when predefined is selected)
                if (input.disabled) return;
                // Skip hidden inputs managed by PLS (they carry data-place-selected)
                if (input.type === 'hidden') return;
                // Skip select elements (airport selects)
                if (input.tagName === 'SELECT') return;

                const placeSelected = input.getAttribute("data-place-selected") === "true";
                const isDefault = input.getAttribute("data-is-default") === "true";
                const hasValue = input.value.trim() !== "";

                // If field has value but wasn't selected and isn't default, it's invalid
                if (hasValue && !placeSelected && !isDefault) {
                    isValid = false;
                    input.classList.add("error");
                    input.style.borderColor = "#dc3545";

                    const fieldName = input.name === "pickup" ? "pickup location" : "destination";
                    errorMessages.push(`Please select your ${fieldName} from the search results dropdown.`);
                }
            });
        }

        // Validate that coordinates are present for location fields (not empty or zero)
        // Skip this check for airport_transfers form (has its own validation below)
        if (form.id !== 'airport_transfers-form') {
            const pickupInput = form.querySelector('input[name="pickup"]:not([disabled])');
            const dropoffInput = form.querySelector('input[name="dropoff"]:not([disabled])');

            if (pickupInput && pickupInput.value.trim()) {
                // Skip coordinate check if a predefined location is selected (backend resolves coords)
                const pickupPredefined = form.querySelector('input[name="pickup_predefined"]');
                if (!pickupPredefined || !pickupPredefined.value) {
                    const pickupLat = form.querySelector('input[name="pickup_lat"]');
                    const pickupLng = form.querySelector('input[name="pickup_lng"]');

                    if (!pickupLat?.value || !pickupLng?.value ||
                        pickupLat.value === '0' || pickupLng.value === '0' ||
                        pickupLat.value === '' || pickupLng.value === '') {
                        isValid = false;
                        pickupInput.classList.add("error");
                        pickupInput.style.borderColor = "#dc3545";

                        if (!errorMessages.includes("Please select your pickup location from the search results dropdown.")) {
                            errorMessages.push("Please select your pickup location from the search results dropdown.");
                        }
                    }
                }
            }

            if (dropoffInput && dropoffInput.value.trim()) {
                // Skip coordinate check if a predefined dropoff location is selected
                const dropoffPredefined = form.querySelector('input[name="dropoff_predefined"]');
                if (!dropoffPredefined || !dropoffPredefined.value) {
                    const dropoffLat = form.querySelector('input[name="dropoff_lat"]');
                    const dropoffLng = form.querySelector('input[name="dropoff_lng"]');

                    if (!dropoffLat?.value || !dropoffLng?.value ||
                        dropoffLat.value === '0' || dropoffLng.value === '0' ||
                        dropoffLat.value === '' || dropoffLng.value === '') {
                        isValid = false;
                        dropoffInput.classList.add("error");
                        dropoffInput.style.borderColor = "#dc3545";

                        if (!errorMessages.includes("Please select your destination from the search results dropdown.")) {
                            errorMessages.push("Please select your destination from the search results dropdown.");
                        }
                    }
                }
            }
        }

        // Special validation for airport transfer coordinates
        if (form.id === 'airport_transfers-form') {
            const pickupLat = form.querySelector('input[name="pickup_lat"]');
            const pickupLng = form.querySelector('input[name="pickup_lng"]');
            const dropoffLat = form.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = form.querySelector('input[name="dropoff_lng"]');

            let missingFields = [];
            
            if (!pickupLat?.value || pickupLat.value === '' || pickupLat.value === '0') {
                isValid = false;
                missingFields.push('pickup latitude');
            }
            if (!pickupLng?.value || pickupLng.value === '' || pickupLng.value === '0') {
                isValid = false;
                missingFields.push('pickup longitude');
            }
            if (!dropoffLat?.value || dropoffLat.value === '' || dropoffLat.value === '0') {
                isValid = false;
                missingFields.push('dropoff latitude');
            }
            if (!dropoffLng?.value || dropoffLng.value === '' || dropoffLng.value === '0') {
                isValid = false;
                missingFields.push('dropoff longitude');
            }

            if (missingFields.length > 0) {
                errorMessages.push("Please select valid locations from the dropdown");
            }
        }

        // Display error messages if validation failed
        if (!isValid && errorMessages.length > 0) {
            // Show the first error message
            showValidationMessage(errorMessages[0]);
        }

        if (!isValid) {
            console.error('Form validation failed', {
                errorMessages,
                form: form.id
            });
        }

        return isValid;
    }

    /**
     * Setup enhanced date and time pickers
     */
    function setupDateTimePickers() {
        // Enhance date pickers with Litepicker
        const datePickers = document.querySelectorAll(".custom-datepicker");

        datePickers.forEach((picker) => {
            // Skip if already initialized
            if (picker._litepicker || picker._flatpickr) {
                return;
            }

            // Initialize Litepicker if available
            if (typeof Litepicker !== 'undefined') {
                const minDate = picker.dataset.minDate || new Date();
                const maxDate = picker.dataset.maxDate || null;

                const litepicker = new Litepicker({
                    element: picker,
                    format: 'DD/MM/YYYY',
                    minDate: minDate,
                    maxDate: maxDate,
                    autoApply: true,
                    singleMode: true,
                    numberOfMonths: 1,
                    numberOfColumns: 1,
                    showTooltip: false,
                    dropdowns: {
                        minYear: new Date().getFullYear(),
                        maxYear: new Date().getFullYear() + 2,
                        months: true,
                        years: true
                    },
                    setup: (pickerInstance) => {
                        pickerInstance.on('selected', (date) => {
                            $(picker).trigger('change');
                            validateDate(picker);
                        });
                        pickerInstance.on('hide', () => {
                            validateDate(picker);
                        });
                    }
                });

                picker._litepicker = litepicker;
            } else if (typeof flatpickr !== 'undefined') {
                // Fallback to Flatpickr
                const minDate = picker.dataset.minDate || 'today';
                const maxDate = picker.dataset.maxDate || null;
                const dateFormat = picker.dataset.dateFormat || 'd/m/Y';

                flatpickr(picker, {
                    dateFormat: dateFormat,
                    minDate: minDate,
                    maxDate: maxDate,
                    allowInput: false,
                    clickOpens: true,
                    disableMobile: true,
                    showMonths: 1,
                    locale: {
                        firstDayOfWeek: 1
                    },
                    onChange: function (selectedDates, dateStr, instance) {
                        $(picker).trigger('change');
                        validateDate(picker);
                    },
                    onClose: function (selectedDates, dateStr, instance) {
                        validateDate(picker);
                    }
                });
            } else if (typeof $ !== "undefined" && $.fn.datepicker) {
                // Fallback to Bootstrap Datepicker
                picker.removeAttribute("readonly");
                $(picker).datepicker({
                    format: 'dd/mm/yyyy',
                    autoclose: true,
                    todayHighlight: true,
                    startDate: new Date(),
                    orientation: 'bottom auto'
                }).on('changeDate', function () {
                    validateDate(this);
                });

                picker.addEventListener("click", function (e) {
                    e.stopPropagation();
                    $(this).datepicker("show");
                });

                picker.addEventListener("focus", function () {
                    if (typeof $ === "undefined" || !$.fn.datepicker) {
                        this.type = "date";
                        if (this.showPicker) {
                            this.showPicker();
                        }
                    }
                });

                picker.addEventListener("blur", function () {
                    if (typeof $ === "undefined" || !$.fn.datepicker) {
                        if (!this.value) {
                            this.type = "text";
                        }
                    }
                });
            } else {
                // HTML5 fallback
                picker.type = "date";
                picker.min = new Date().toISOString().split("T")[0];
                picker.addEventListener('change', function () {
                    validateDate(this);
                });
            }
        });

        // Enhance time pickers
        const timePickers = document.querySelectorAll('input[type="time"]');
        timePickers.forEach((picker) => {
            // Set default time to 12:00 if it's 00:00
            if (picker.value === "00:00") {
                picker.value = "12:00";
            }

            picker.addEventListener("focus", function () {
                this.showPicker();
            });

            // Set reasonable default time if empty
            if (!picker.value) {
                picker.value = "12:00";
            }
        });
    }

    /**
     * Setup form animations and transitions
     */
    function setupFormAnimations() {
        // Add smooth transitions when switching forms
        const forms = document.querySelectorAll(".filter-input");
        forms.forEach((form) => {
            form.style.transition = "all 0.3s ease";
        });

        // Add hover effects to search boxes
        const searchBoxes = document.querySelectorAll(".single-search-box");
        searchBoxes.forEach((box) => {
            box.addEventListener("mouseenter", function () {
                if (!this.classList.contains("error")) {
                    this.style.borderColor = "var(--primary-color1)";
                }
            });

            box.addEventListener("mouseleave", function () {
                if (
                    !this.classList.contains("error") &&
                    !this.matches(":focus-within")
                ) {
                    this.style.borderColor = "var(--borders-color)";
                }
            });
        });
    }

    /**
     * Visual feedback helper for required fields (success/error border on containers)
     */
    function validateField() {
        const container = this.closest(".single-search-box");
        if (!container) return true;

        const isValid = this.value.trim() !== "";

        container.classList.remove("error", "success");

        if (this.hasAttribute("required")) {
            if (isValid) {
                container.classList.add("success");
            } else {
                container.classList.add("error");
            }
        }

        return isValid;
    }

    function showValidationMessage(message) {
        // Remove existing message
        const existingMessage = document.querySelector(".validation-message");
        if (existingMessage) {
            existingMessage.remove();
        }

        // Create new message
        const messageEl = document.createElement("div");
        messageEl.className = "validation-message";
        messageEl.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dc3545;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            font-size: 14px;
            animation: slideInRight 0.3s ease;
        `;
        messageEl.textContent = message;

        document.body.appendChild(messageEl);

        setTimeout(() => {
            messageEl.style.animation = "slideOutRight 0.3s ease";
            setTimeout(() => messageEl.remove(), 300);
        }, 3000);
    }

    /**
     * Show inline error message below input field
     */
    function showInlineError(input, message) {
        // Remove existing error for this input
        removeInlineError(input);

        // Create error message element
        const errorEl = document.createElement("div");
        errorEl.className = "inline-error-message";
        errorEl.textContent = message;

        // Keep validation text outside the bordered control and below the field.
        const control = input.closest('.single-search-box') || input.closest('.custom-select-dropdown') || input.parentElement;
        if (control) {
            control.insertAdjacentElement('afterend', errorEl);
        }
    }

    /**
     * Remove inline error message for input field
     */
    function removeInlineError(input) {
        const container = input.closest('.booking-field') || input.parentElement;
        if (container) {
            const existingError = container.querySelector(':scope > .inline-error-message');
            if (existingError) {
                existingError.remove();
            }
        }
    }

    /**
     * Check form validity and enable/disable submit button
     */
    function checkFormValidity(form) {
        if (!form) return;

        const submitBtn = form.querySelector('button[type="submit"]');
        if (!submitBtn) return;

        let isValid = true;

        // Check all location inputs in this form
        const locationInputs = form.querySelectorAll('.location-search, input[name="pickup"], input[name="dropoff"], input[name="from"], input[name="to"]');

        locationInputs.forEach((input) => {
            // Skip if input is disabled or hidden
            if (input.disabled || input.offsetParent === null) {
                return;
            }
            // Skip hidden inputs (managed by PLS component)
            if (input.type === 'hidden') return;

            const placeSelected = input.getAttribute("data-place-selected") === "true";
            const hasValue = input.value.trim() !== "";

            // Use the mapping helper to get the correct lat/lng fields
            const { latInput, lngInput } = getCoordInputs(input);

            const hasValidCoords = latInput?.value && lngInput?.value &&
                latInput.value !== '0' && lngInput.value !== '0' &&
                latInput.value !== '' && lngInput.value !== '';

            // Skip coordinate check if a predefined location is selected
            const predefinedInput = form.querySelector('input[name="' + input.name + '_predefined"]');
            if (predefinedInput && predefinedInput.value) return;

            // Invalid if: has value but not selected AND missing valid coordinates
            if (hasValue && !placeSelected) {
                isValid = false;
            }
        });

        // Enable or disable submit button
        if (isValid) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
            submitBtn.title = '';
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
            submitBtn.title = 'Please select valid locations from the dropdown';
        }
    }

    // Add CSS animations for messages
    const style = document.createElement("style");
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    /**
     * Debug function to log current coordinate values
     */
    function logCoordinateValues() {
        // Airport Transfers Form (uses pickup_lat/pickup_lng and dropoff_lat/dropoff_lng)
        const airportForm = document.getElementById('airport_transfers-form');
        if (airportForm) {
            const fromLat = airportForm.querySelector('input[name="pickup_lat"]');
            const fromLng = airportForm.querySelector('input[name="pickup_lng"]');
            const toLat = airportForm.querySelector('input[name="dropoff_lat"]');
            const toLng = airportForm.querySelector('input[name="dropoff_lng"]');
        }

        // Ride Now Form (uses pickup_lat/pickup_lng and dropoff_lat/dropoff_lng)
        const rideNowForm = document.getElementById('ride_now-form');
        if (rideNowForm) {
            const pickupLat = rideNowForm.querySelector('input[name="pickup_lat"]');
            const pickupLng = rideNowForm.querySelector('input[name="pickup_lng"]');
            const dropoffLat = rideNowForm.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = rideNowForm.querySelector('input[name="dropoff_lng"]');
        }

        const dayRentalForm = document.getElementById('day_rental-form');
        if (dayRentalForm) {
            const pickupLat = dayRentalForm.querySelector('input[name="pickup_lat"]');
            const pickupLng = dayRentalForm.querySelector('input[name="pickup_lng"]');
            const dropoffLat = dayRentalForm.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = dayRentalForm.querySelector('input[name="dropoff_lng"]');
        }
    }

    /**
     * Force coordinate update from airport selects on page load
     */
    function forceAirportCoordinateUpdate() {
        const airportForm = document.getElementById("airport_transfers-form");
        if (!airportForm) return;

        const fromAirportSelect = airportForm.querySelector('#from-airport-select');
        const fromAirportWrapper = airportForm.querySelector('#from-airport-select_wrapper');
        const toAirportSelect = airportForm.querySelector('#to-airport-select');
        const toAirportWrapper = airportForm.querySelector('#to-airport-select_wrapper');

        // Trigger coordinate updates for any pre-selected airports
        if (fromAirportSelect && fromAirportSelect.value && fromAirportWrapper && !fromAirportWrapper.classList.contains('hidden')) {
            fromAirportSelect.dispatchEvent(new Event('change'));
        }

        if (toAirportSelect && toAirportSelect.value && toAirportWrapper && !toAirportWrapper.classList.contains('hidden')) {
            toAirportSelect.dispatchEvent(new Event('change'));
        }
    }

    /**
     * Ensure airport transfer coordinates are set from selected airports
     */
    function ensureAirportCoordinatesAreSet() {
        const form = document.getElementById('airport_transfers-form');
        if (!form) return;

        const fromAirportSelect = form.querySelector('#from-airport-select');
        const fromAirportWrapper = form.querySelector('#from-airport-select_wrapper');
        const toAirportSelect = form.querySelector('#to-airport-select');
        const toAirportWrapper = form.querySelector('#to-airport-select_wrapper');
        const fromLat = form.querySelector('input[name="pickup_lat"]');
        const fromLng = form.querySelector('input[name="pickup_lng"]');
        const toLat = form.querySelector('input[name="dropoff_lat"]');
        const toLng = form.querySelector('input[name="dropoff_lng"]');

        // Force coordinate update from selected airport options
        if (fromAirportSelect && fromAirportWrapper && !fromAirportWrapper.classList.contains('hidden') && fromAirportSelect.value) {
            const selectedOption = fromAirportSelect.options[fromAirportSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                if (fromLat && fromLng) {
                    fromLat.value = selectedOption.dataset.lat;
                    fromLng.value = selectedOption.dataset.lng;
                }
            }
        }

        if (toAirportSelect && toAirportWrapper && !toAirportWrapper.classList.contains('hidden') && toAirportSelect.value) {
            const selectedOption = toAirportSelect.options[toAirportSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                if (toLat && toLng) {
                    toLat.value = selectedOption.dataset.lat;
                    toLng.value = selectedOption.dataset.lng
                }
            }
        }
    }

    /**
     * Ensure all coordinate fields have valid values
     */
    function ensureCoordinateValues() {
        // Check Airport Transfers form
        const airportForm = document.getElementById('airport_transfers-form');
        if (airportForm) {
            const fromLat = airportForm.querySelector('input[name="pickup_lat"]');
            const fromLng = airportForm.querySelector('input[name="pickup_lng"]');
            const toLat = airportForm.querySelector('input[name="dropoff_lat"]');
            const toLng = airportForm.querySelector('input[name="dropoff_lng"]');

            // Set default airport coordinates if missing
            if (fromLat && (!fromLat.value || fromLat.value === '')) {
                fromLat.value = '7.1808'; // BIA Airport
            }
            if (fromLng && (!fromLng.value || fromLng.value === '')) {
                fromLng.value = '79.8841'; // BIA Airport
            }
            if (toLat && (!toLat.value || toLat.value === '')) {
                toLat.value = '6.9271'; // Colombo
            }
            if (toLng && (!toLng.value || toLng.value === '')) {
                toLng.value = '79.8612'; // Colombo  
            }
        }

        // Check Ride Now form
        const rideForm = document.getElementById('ride_now-form');
        if (rideForm) {
            const pickupLat = rideForm.querySelector('input[name="pickup_lat"]');
            const pickupLng = rideForm.querySelector('input[name="pickup_lng"]');
            const dropoffLat = rideForm.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = rideForm.querySelector('input[name="dropoff_lng"]');

            // Set default coordinates if missing
            if (pickupLat && (!pickupLat.value || pickupLat.value === '')) {
                pickupLat.value = '6.9271'; // Colombo
            }
            if (pickupLng && (!pickupLng.value || pickupLng.value === '')) {
                pickupLng.value = '79.8612'; // Colomb
            }
            if (dropoffLat && (!dropoffLat.value || dropoffLat.value === '')) {
                dropoffLat.value = '6.0535'; // Galle
            }
            if (dropoffLng && (!dropoffLng.value || dropoffLng.value === '')) {
                dropoffLng.value = '80.221'; // Galle
            }
        }
    }

    /**
     * Initialize Return Trip functionality for Ride Now form
     */
    function initReturnTrip() {
        const returnToggle = document.getElementById('ride_now-return-toggle');
        const returnDetails = document.getElementById('ride_now-return-details');
        const returnDateInput = document.getElementById('ride_now-return-date');
        const pricingInfo = document.getElementById('ride_now-return-pricing-info');

        if (!returnToggle || !returnDetails) {
            return;
        }

        // If return toggle is already checked (from search params), initialize everything
        if (returnToggle.checked) {
            initReturnDatePicker();
            updateReturnRouteLocations();
            calculateReturnPricing();
        }

        // Toggle return trip details visibility
        returnToggle.addEventListener('change', function () {
            if (this.checked) {
                returnDetails.style.display = 'grid';
                // Initialize return date picker if needed
                initReturnDatePicker();
                // Set default return date to pickup date
                syncReturnDateWithPickup();
                // Update return route locations
                updateReturnRouteLocations();
                // Calculate initial pricing
                calculateReturnPricing();
            } else {
                returnDetails.style.display = 'none';
                if (pricingInfo) {
                    pricingInfo.style.display = 'none';
                }
            }
        });

        // Listen for return date changes
        if (returnDateInput) {
            returnDateInput.addEventListener('change', calculateReturnPricing);
        }

        // Listen for pickup date changes to update return pricing
        const pickupDateInput = document.querySelector('#ride_now-form input[name="pickup_date"]');
        if (pickupDateInput) {
            pickupDateInput.addEventListener('change', function () {
                if (returnToggle.checked) {
                    syncReturnDateWithPickup();
                    calculateReturnPricing();
                }
            });
        }

        // Listen for pickup/dropoff location changes to update return route display
        const rideNowForm = document.getElementById('ride_now-form');
        if (rideNowForm) {
            const pickupInput = rideNowForm.querySelector('input[name="pickup"]');
            const dropoffInput = rideNowForm.querySelector('input[name="dropoff"]');

            if (pickupInput) {
                pickupInput.addEventListener('change', updateReturnRouteLocations);
                pickupInput.addEventListener('blur', updateReturnRouteLocations);
            }
            if (dropoffInput) {
                dropoffInput.addEventListener('change', updateReturnRouteLocations);
                dropoffInput.addEventListener('blur', updateReturnRouteLocations);
            }
        }

    }

    /**
     * Update return route location display (shows dropoff → pickup for return)
     */
    function updateReturnRouteLocations() {
        const rideNowForm = document.getElementById('ride_now-form');
        if (!rideNowForm) return;

        const pickupInput = rideNowForm.querySelector('input[name="pickup"]');
        const dropoffInput = rideNowForm.querySelector('input[name="dropoff"]');
        const returnPickupEl = document.getElementById('return-pickup-location');
        const returnDropoffEl = document.getElementById('return-dropoff-location');

        if (returnPickupEl && pickupInput) {
            returnPickupEl.textContent = pickupInput.value || 'Pickup';
        }
        if (returnDropoffEl && dropoffInput) {
            returnDropoffEl.textContent = dropoffInput.value || 'Drop-off';
        }
    }

    /**
     * Initialize date picker for return date (Litepicker, Flatpickr or Bootstrap fallback)
     */
    function initReturnDatePicker() {
        const returnDateInput = document.getElementById('ride_now-return-date');
        if (!returnDateInput) return;

        // If already attached, skip
        if (returnDateInput._litepicker || returnDateInput._flatpickr) return;

        // Get minimum date from pickup date
        const pickupDateInput = document.querySelector('#ride_now-form input[name="pickup_date"]');
        let startDate = new Date();
        if (pickupDateInput && pickupDateInput.value) {
            const parsed = parseDDMMYYYY(pickupDateInput.value);
            if (parsed) startDate = parsed;
        }

        // Use Litepicker if available
        if (typeof Litepicker !== 'undefined') {
            const litepicker = new Litepicker({
                element: returnDateInput,
                format: 'DD/MM/YYYY',
                minDate: startDate,
                maxDate: null,
                autoApply: true,
                singleMode: true,
                numberOfMonths: 1,
                numberOfColumns: 1,
                showTooltip: false,
                dropdowns: {
                    minYear: new Date().getFullYear(),
                    maxYear: new Date().getFullYear() + 2,
                    months: true,
                    years: true
                },
                setup: (picker) => {
                    picker.on('selected', (date) => {
                        calculateReturnPricing();
                    });
                }
            });

            returnDateInput._litepicker = litepicker;
        } else if (typeof flatpickr !== 'undefined') {
            // Fallback to Flatpickr
            flatpickr(returnDateInput, {
                dateFormat: 'd/m/Y',
                minDate: startDate,
                allowInput: false,
                clickOpens: true,
                disableMobile: true,
                showMonths: 1,
                locale: {
                    firstDayOfWeek: 1
                },
                onChange: function (selectedDates, dateStr, instance) {
                    calculateReturnPricing();
                }
            });
        } else if (typeof $ !== 'undefined' && $.fn.datepicker) {
            // Fallback to Bootstrap Datepicker
            if ($(returnDateInput).data('datepicker')) {
                $(returnDateInput).datepicker('destroy');
            }

            $(returnDateInput).datepicker({
                format: 'dd/mm/yyyy',
                autoclose: true,
                todayHighlight: true,
                startDate: startDate,
                orientation: 'bottom auto',
                container: 'body',
                zIndexOffset: 9999
            }).on('changeDate', function () {
                calculateReturnPricing();
            });
        }
    }

    /**
     * Sync return date with pickup date (set return date = pickup date by default)
     */
    function syncReturnDateWithPickup() {
        const pickupDateInput = document.querySelector('#ride_now-form input[name="pickup_date"]');
        const returnDateInput = document.getElementById('ride_now-return-date');

        if (pickupDateInput && returnDateInput) {
            returnDateInput.value = pickupDateInput.value;
            // Update datepicker (Flatpickr or Bootstrap)
            if (returnDateInput._flatpickr) {
                returnDateInput._flatpickr.setDate(pickupDateInput.value, false, 'd/m/Y');
            } else if (typeof $ !== 'undefined' && $.fn.datepicker) {
                $(returnDateInput).datepicker('update', pickupDateInput.value);
            }
        }
    }

    /**
     * Calculate return trip pricing via API
     */
    function calculateReturnPricing() {
        const returnToggle = document.getElementById('ride_now-return-toggle');
        if (!returnToggle || !returnToggle.checked) {
            return;
        }

        const rideNowForm = document.getElementById('ride_now-form');
        if (!rideNowForm) return;

        // Get selected package (supports radio/select/hidden and legacy/new names)
        let packageId = '';
        const packageInput = rideNowForm.querySelector('input[name="package_id"]:checked, input[name="service_package_id"]:checked');
        if (packageInput) {
            packageId = packageInput.value;
        }
        if (!packageId) {
            const packageField = rideNowForm.querySelector(
                'select[name="package_id"], select[name="service_package_id"], input[type="hidden"][name="package_id"], input[type="hidden"][name="service_package_id"]'
            );
            if (packageField && packageField.value) {
                packageId = packageField.value;
            }
        }

        const pickupDateInput = rideNowForm.querySelector('input[name="pickup_date"]');
        const returnDateInput = document.getElementById('ride_now-return-date');

        if (!pickupDateInput || !returnDateInput) {
            return;
        }

        const pickupDate = parseDDMMYYYY(pickupDateInput.value);
        const returnDate = parseDDMMYYYY(returnDateInput.value);

        if (!packageId || !pickupDate || !returnDate) {
            return;
        }

        // Calculate day offset locally for immediate UI feedback
        const dayOffset = Math.floor((returnDate - pickupDate) / (1000 * 60 * 60 * 24));
        updateReturnPricingUI(dayOffset);

        // Optionally call API for exact pricing (if one-way fare is known)
        // This would typically be called after vehicle selection
    }

    /**
     * Update return pricing UI based on day offset
     */
    function updateReturnPricingUI(dayOffset) {
        const pricingInfo = document.getElementById('ride_now-return-pricing-info');
        const discountLabel = document.getElementById('ride_now-return-discount-label');
        const discountValue = document.getElementById('ride_now-return-discount-value');

        if (!pricingInfo || !discountLabel || !discountValue) {
            return;
        }

        let label = '';
        let discount = 0;

        if (dayOffset === 0) {
            label = 'Same Day Return';
            discount = 50;
        } else if (dayOffset === 1) {
            label = 'Next Day Return';
            discount = 10;
        } else {
            label = `${dayOffset}+ Days Return`;
            discount = 0;
        }

        discountLabel.textContent = label;

        if (discount > 0) {
            discountValue.textContent = `${discount}% off`;
            pricingInfo.style.display = 'flex';
        } else {
            discountValue.textContent = 'Standard fare';
            pricingInfo.style.display = 'flex';
        }
    }

    /**
     * Parse DD/MM/YYYY date string to Date object
     */
    function parseDDMMYYYY(dateString) {
        if (!dateString) return null;
        const parts = dateString.split('/');
        if (parts.length !== 3) return null;
        const day = parseInt(parts[0], 10);
        const month = parseInt(parts[1], 10) - 1; // JS months are 0-indexed
        const year = parseInt(parts[2], 10);
        return new Date(year, month, day);
    }

    /**
     * Fetch return trip rules for a package (for advanced pricing display)
     */
    async function fetchReturnRules(packageId) {
        try {
            const response = await fetch(`/api/service-packages/${packageId}/return-rules`);
            if (!response.ok) {
                throw new Error('Failed to fetch return rules');
            }
            const data = await response.json();
            return data.data;
        } catch (error) {
            console.error('Error fetching return rules:', error);
            return null;
        }
    }

    /**
     * Calculate return price with actual fare via API
     */
    async function calculateReturnPriceWithFare(packageId, outboundDate, returnDate, oneWayFare, vehicleGroupId = null) {
        try {
            const response = await fetch('/api/public/return-trip/calculate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    service_package_id: packageId,
                    vehicle_group_id: vehicleGroupId,
                    outbound_date: outboundDate,
                    return_date: returnDate,
                    one_way_fare: oneWayFare,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to calculate return price');
            }

            const data = await response.json();
            return data.data;
        } catch (error) {
            console.error('Error calculating return price:', error);
            return null;
        }
    }

    // Expose return trip functions globally
    window.calculateReturnPriceWithFare = calculateReturnPriceWithFare;
    window.fetchReturnRules = fetchReturnRules;

    // Initialize when DOM is ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }

    // Initialize return trip after main init
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initReturnTrip);
    } else {
        initReturnTrip();
    }

    setTimeout(() => {
        logCoordinateValues();

        ensureCoordinateValues();
        forceAirportCoordinateUpdate();

        setTimeout(() => {
            logCoordinateValues();
        }, 1000);
    }, 1500);

    // Expose functions globally for use in Blade templates
    window.updateAirportTransferLocations = updateAirportTransferLocations;
    window.logCoordinateValues = logCoordinateValues;
})();
