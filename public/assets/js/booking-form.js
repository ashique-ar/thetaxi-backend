/**
 * TheTaxi Enhanced Booking Form - Complete Implementation
 * Handles all service types with Google Maps integration and fallbacks
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
        console.log("TheTaxi Enhanced Booking Form initializing...");

        setupServiceSwitcher();
        setupAirportTransfer();
        setupDropPickup();
        setupCustomTour();
        setupFormValidation();
        setupDateTimePickers();
        setupFormAnimations();
        loadMapsAPI();
        initializeDatePickers();
        setDefaultDatesAndLocations();

        console.log("TheTaxi Enhanced Booking Form initialized");
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
            const dropoffDateInput = rideNowForm.querySelector(
                'input[name="dropoff_date"]'
            );
            const pickupTimeInput = rideNowForm.querySelector(
                'input[name="pickup_time"]'
            );
            const dropoffTimeInput = rideNowForm.querySelector(
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
        console.log(
            "Google Maps initialized successfully (for location search only)"
        );
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

        autocomplete.addListener("place_changed", function () {
            const place = autocomplete.getPlace();
            updateLocationData(input, place);
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
                    },
                });
            }

            // Show dropdown on focus for airport fields
            if (isAirportField) {
                $(input).on("focus", function () {
                    $(this).autocomplete("search", "");
                });
            }
        }
    }

    /**
     * Update location data in hidden fields
     */
    function updateLocationData(input, place) {
        // For airport transfer form
        if (input.name === "from") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="pickup_lat"]');
            const lngInput = form.querySelector('input[name="pickup_lng"]');

            if (place.geometry && latInput && lngInput) {
                latInput.value = place.geometry.location.lat();
                lngInput.value = place.geometry.location.lng();
            }
        } else if (input.name === "to") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="dropoff_lat"]');
            const lngInput = form.querySelector('input[name="dropoff_lng"]');

            if (place.geometry && latInput && lngInput) {
                latInput.value = place.geometry.location.lat();
                lngInput.value = place.geometry.location.lng();
            }
        } else if (input.name === "pickup") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="pickup_lat"]');
            const lngInput = form.querySelector('input[name="pickup_lng"]');

            if (place.geometry && latInput && lngInput) {
                latInput.value = place.geometry.location.lat();
                lngInput.value = place.geometry.location.lng();
                console.log('Updated pickup coordinates via Google Places:', latInput.value, lngInput.value);
            } else {
                console.error('Could not find pickup coordinate fields or place geometry');
            }
        } else if (input.name === "dropoff") {
            const form = input.closest("form");
            const latInput = form.querySelector('input[name="dropoff_lat"]');
            const lngInput = form.querySelector('input[name="dropoff_lng"]');

            if (place.geometry && latInput && lngInput) {
                latInput.value = place.geometry.location.lat();
                lngInput.value = place.geometry.location.lng();
                console.log('Updated dropoff coordinates via Google Places:', latInput.value, lngInput.value);
            } else {
                console.error('Could not find dropoff coordinate fields or place geometry');
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
                switchService(serviceType, serviceItems, forms);
            });
        });
    }

    /**
     * Switch between service types
     */
    function switchService(serviceType, items, forms) {
        state.currentService = serviceType;

        // Update active state
        items.forEach((item) => item.classList.remove("active"));
        document
            .querySelector(`[data-service="${serviceType}"]`)
            .classList.add("active");

        // Show corresponding form
        forms.forEach((form) => {
            if (form.getAttribute("data-service") === serviceType) {
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
            console.log('Initializing airport transfer with type:', defaultRadio.value);
            updateAirportTransferLocations(defaultRadio.value);
        } else {
            // Default to from-airport if no radio is checked
            console.log('No transfer type selected, defaulting to from-airport');
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
        const fromLocationInput = form.querySelector('#from-location-input');
        const toAirportSelect = form.querySelector('#to-airport-select');
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

        // Debug: Log coordinate values
        console.log('Airport coordinate setup:', {
            type,
            colomboCoords,
            defaultAirport,
            defaultAirportCoords,
            configKeys: Object.keys(CONFIG.cityCoordinates)
        });

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
            fromAirportSelect.classList.remove('hidden');
            fromAirportSelect.classList.add('visible');
            fromLocationInput.classList.remove('visible');
            fromLocationInput.classList.add('hidden');
            fromAirportSelect.required = true;
            fromLocationInput.required = false;
            fromAirportSelect.disabled = false;
            fromLocationInput.disabled = true;

            // Show TO location input, hide TO airport select  
            toLocationInput.classList.remove('hidden');
            toLocationInput.classList.add('visible');
            toAirportSelect.classList.remove('visible');
            toAirportSelect.classList.add('hidden');
            toLocationInput.required = true;
            toAirportSelect.required = false;
            toLocationInput.disabled = false;
            toAirportSelect.disabled = true;

            // Set default values
            fromAirportSelect.value = defaultAirport;
            toLocationInput.value = "Colombo, Sri Lanka";

            // Set coordinates with validation (only if not already set)
            if (defaultAirportCoords && defaultAirportCoords.lat && defaultAirportCoords.lng) {
                if (!fromLat.value || fromLat.value === '' || fromLat.value === '0') {
                    fromLat.value = defaultAirportCoords.lat;
                }
                if (!fromLng.value || fromLng.value === '' || fromLng.value === '0') {
                    fromLng.value = defaultAirportCoords.lng;
                }
                console.log('FROM coordinates set (from-airport):', {
                    lat: fromLat.value,
                    lng: fromLng.value,
                    source: 'defaultAirportCoords'
                });
            } else {
                console.error('Default airport coordinates not found for:', defaultAirport);
            }

            if (colomboCoords && colomboCoords.lat && colomboCoords.lng) {
                if (!toLat.value || toLat.value === '' || toLat.value === '0') {
                    toLat.value = colomboCoords.lat;
                }
                if (!toLng.value || toLng.value === '' || toLng.value === '0') {
                    toLng.value = colomboCoords.lng;
                }
                console.log('TO coordinates set (from-airport):', {
                    lat: toLat.value,
                    lng: toLng.value,
                    source: 'colomboCoords'
                });
            } else {
                console.error('Colombo coordinates not found');
            }

            // Initialize autocomplete for TO location input
            setTimeout(() => {
                initializeLocationInputAutocomplete(toLocationInput);
            }, 100);

        } else {
            // Show FROM location input, hide FROM airport select
            fromLocationInput.classList.remove('hidden');
            fromLocationInput.classList.add('visible');
            fromAirportSelect.classList.remove('visible');
            fromAirportSelect.classList.add('hidden');
            fromLocationInput.required = true;
            fromAirportSelect.required = false;
            fromLocationInput.disabled = false;
            fromAirportSelect.disabled = true;

            // Show TO airport select, hide TO location input
            toAirportSelect.classList.remove('hidden');
            toAirportSelect.classList.add('visible');
            toLocationInput.classList.remove('visible');
            toLocationInput.classList.add('hidden');
            toAirportSelect.required = true;
            toLocationInput.required = false;
            toAirportSelect.disabled = false;
            toLocationInput.disabled = true;

            // Set default values
            fromLocationInput.value = "Colombo, Sri Lanka";
            toAirportSelect.value = defaultAirport;

            // Set coordinates with validation (only if not already set)
            if (colomboCoords && colomboCoords.lat && colomboCoords.lng) {
                if (!fromLat.value || fromLat.value === '' || fromLat.value === '0') {
                    fromLat.value = colomboCoords.lat;
                }
                if (!fromLng.value || fromLng.value === '' || fromLng.value === '0') {
                    fromLng.value = colomboCoords.lng;
                }
                console.log('FROM coordinates set (to-airport):', {
                    lat: fromLat.value,
                    lng: fromLng.value,
                    source: 'colomboCoords'
                });
            } else {
                console.error('Colombo coordinates not found');
            }

            if (defaultAirportCoords && defaultAirportCoords.lat && defaultAirportCoords.lng) {
                if (!toLat.value || toLat.value === '' || toLat.value === '0') {
                    toLat.value = defaultAirportCoords.lat;
                }
                if (!toLng.value || toLng.value === '' || toLng.value === '0') {
                    toLng.value = defaultAirportCoords.lng;
                }
                console.log('TO coordinates set (to-airport):', {
                    lat: toLat.value,
                    lng: toLng.value,
                    source: 'defaultAirportCoords'
                });
            } else {
                console.error('Default airport coordinates not found for:', defaultAirport);
            }

            // Initialize autocomplete for FROM location input
            setTimeout(() => {
                initializeLocationInputAutocomplete(fromLocationInput);
            }, 100);
        }

        // Setup airport select change handlers
        setupAirportSelectHandlers();

        // Force coordinate update for any pre-selected airports
        setTimeout(() => {
            if (fromAirportSelect && fromAirportSelect.value && !fromAirportSelect.classList.contains('hidden')) {
                fromAirportSelect.dispatchEvent(new Event('change'));
                console.log('Forced FROM airport coordinate update');
            }
            if (toAirportSelect && toAirportSelect.value && !toAirportSelect.classList.contains('hidden')) {
                toAirportSelect.dispatchEvent(new Event('change'));
                console.log('Forced TO airport coordinate update');
            }
        }, 200);
    }

    /**
     * Setup airport select change handlers
     */
    function setupAirportSelectHandlers() {
        const form = document.getElementById("airport_transfers-form");
        if (!form) {
            console.warn('Airport transfers form not found');
            return;
        }

        const fromAirportSelect = form.querySelector('#from-airport-select');
        const toAirportSelect = form.querySelector('#to-airport-select');
        const fromLat = form.querySelector('input[name="pickup_lat"]');
        const fromLng = form.querySelector('input[name="pickup_lng"]');
        const toLat = form.querySelector('input[name="dropoff_lat"]');
        const toLng = form.querySelector('input[name="dropoff_lng"]');

        console.log('Setting up airport select handlers:', {
            fromAirportSelect: !!fromAirportSelect,
            toAirportSelect: !!toAirportSelect,
            fromLat: !!fromLat,
            fromLng: !!fromLng,
            toLat: !!toLat,
            toLng: !!toLng
        });

        // FROM airport select handler
        if (fromAirportSelect && !fromAirportSelect.hasAirportHandler) {
            fromAirportSelect.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];
                console.log('FROM airport changed:', selectedOption.value);

                if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                    if (fromLat && fromLng) {
                        fromLat.value = selectedOption.dataset.lat;
                        fromLng.value = selectedOption.dataset.lng;
                        console.log('FROM airport coordinates set:', {
                            lat: fromLat.value,
                            lng: fromLng.value
                        });
                    } else {
                        console.error('FROM coordinate inputs not found');
                    }
                } else {
                    console.warn('FROM airport option missing coordinate data');
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
                console.log('TO airport changed:', selectedOption.value);

                if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                    if (toLat && toLng) {
                        toLat.value = selectedOption.dataset.lat;
                        toLng.value = selectedOption.dataset.lng;
                        console.log('TO airport coordinates set:', {
                            lat: toLat.value,
                            lng: toLng.value
                        });
                    } else {
                        console.error('TO coordinate inputs not found');
                    }
                } else {
                    console.warn('TO airport option missing coordinate data');
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
                updateLocationDataForInput(input, place);
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

        console.log(
            `[Autocomplete] Found ${modalLocationInputs.length} modal location inputs`
        );

        modalLocationInputs.forEach((input, idx) => {
            const dataIndex = input.getAttribute("data-index");

            // Skip if already initialized
            if (input.dataset.googleAutocompleteInitialized === "true") {
                console.log(
                    `[Autocomplete] Skipping already initialized input at index ${dataIndex}`
                );
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

                    console.log(
                        `[Autocomplete] Place selected for index ${index}:`,
                        place
                    );

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

                        console.log(
                            `[Autocomplete] State updated for index ${index}:`,
                            state.customTourDestinations[index]
                        );

                        // Sync to form and refresh map
                        updateFormFromState();
                        setTimeout(() => initializeRouteMap(), 200);
                    }
                });

                // Mark as initialized
                input.dataset.googleAutocompleteInitialized = "true";

                console.log(
                    `[Autocomplete] ✓ Google autocomplete initialized for modal input at index ${dataIndex}`
                );
            } else {
                console.warn(
                    "Google Maps API not loaded yet. Autocomplete not available."
                );

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
        // Destroy existing datepickers first to prevent duplicates
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
     * Setup form validation
     */
    function setupFormValidation() {
        const forms = document.querySelectorAll(".filter-input");

        forms.forEach((form) => {
            form.addEventListener("submit", function (e) {
                // Debug: Log form data including coordinates before submission
                const formData = new FormData(form);
                const formDataObj = {};
                formData.forEach((value, key) => {
                    formDataObj[key] = value;
                });

                console.log("Form submission - All form data:", formDataObj);
                console.log("Form submission - Coordinates check:", {
                    pickup_lat: formData.get("pickup_lat"),
                    pickup_lng: formData.get("pickup_lng"),
                    dropoff_lat: formData.get("dropoff_lat"),
                    dropoff_lng: formData.get("dropoff_lng"),
                    pickup_lat: formData.get("pickup_lat"),
                    pickup_lng: formData.get("pickup_lng"),
                    dropoff_lat: formData.get("dropoff_lat"),
                    dropoff_lng: formData.get("dropoff_lng"),
                });

                if (!validateForm(form)) {
                    e.preventDefault();
                }
            });
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

        requiredInputs.forEach((input) => {
            if (!input.value.trim()) {
                isValid = false;
                input.classList.add("error");
                input.style.borderColor = "#dc3545";
            } else {
                input.classList.remove("error");
                input.style.borderColor = "";
            }
        });

        // Special validation for airport transfer coordinates
        if (form.id === 'airport_transfers-form') {
            const pickupLat = form.querySelector('input[name="pickup_lat"]');
            const pickupLng = form.querySelector('input[name="pickup_lng"]');
            const dropoffLat = form.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = form.querySelector('input[name="dropoff_lng"]');

            if (!pickupLat?.value || pickupLat.value === '' || pickupLat.value === '0') {
                console.error('Airport transfer missing pickup latitude');
                isValid = false;
            }
            if (!pickupLng?.value || pickupLng.value === '' || pickupLng.value === '0') {
                console.error('Airport transfer missing pickup longitude');
                isValid = false;
            }
            if (!dropoffLat?.value || dropoffLat.value === '' || dropoffLat.value === '0') {
                console.error('Airport transfer missing dropoff latitude');
                isValid = false;
            }
            if (!dropoffLng?.value || dropoffLng.value === '' || dropoffLng.value === '0') {
                console.error('Airport transfer missing dropoff longitude');
                isValid = false;
            }

            if (isValid) {
                console.log('Airport transfer coordinates validated successfully:', {
                    pickup: { lat: pickupLat.value, lng: pickupLng.value },
                    dropoff: { lat: dropoffLat.value, lng: dropoffLng.value }
                });
            } else {
                alert("Airport transfer coordinates are missing. Please check your location selections.");
                return false;
            }
        }

        if (!isValid) {
            alert("Please fill in all required fields");
        }

        return isValid;
    }

    /**
     * Setup enhanced date and time pickers
     */
    function setupDateTimePickers() {
        // Enhance date pickers - fix direct click issue
        const datePickers = document.querySelectorAll(".custom-datepicker");
        datePickers.forEach((picker) => {
            // Remove readonly to allow direct clicking
            picker.removeAttribute("readonly");

            picker.addEventListener("click", function (e) {
                e.stopPropagation();
                if (typeof $ !== "undefined" && $.fn.datepicker) {
                    $(this).datepicker("show");
                } else {
                    this.type = "date";
                    this.showPicker();
                }
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
     * Enhanced form validation with visual feedback
     */
    function setupFormValidation() {
        const forms = document.querySelectorAll(".filter-input");

        forms.forEach((form) => {
            const inputs = form.querySelectorAll(
                "input[required], select[required]"
            );

            inputs.forEach((input) => {
                input.addEventListener("blur", validateField);
                input.addEventListener("input", validateField);
            });

            form.addEventListener("submit", function (e) {
                let isValid = true;

                inputs.forEach((input) => {
                    if (!validateField.call(input)) {
                        isValid = false;
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    showValidationMessage(
                        "Please fill in all required fields correctly."
                    );
                }
            });
        });
    }

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
        console.log('=== COORDINATE VALUES DEBUG ===');

        // Airport Transfers Form (uses pickup_lat/pickup_lng and dropoff_lat/dropoff_lng)
        const airportForm = document.getElementById('airport_transfers-form');
        if (airportForm) {
            const fromLat = airportForm.querySelector('input[name="pickup_lat"]');
            const fromLng = airportForm.querySelector('input[name="pickup_lng"]');
            const toLat = airportForm.querySelector('input[name="dropoff_lat"]');
            const toLng = airportForm.querySelector('input[name="dropoff_lng"]');

            console.log('Airport Transfers coordinates:', {
                fromLat: fromLat?.value || 'not found',
                fromLng: fromLng?.value || 'not found',
                toLat: toLat?.value || 'not found',
                toLng: toLng?.value || 'not found',
                formVisible: !airportForm.classList.contains('hidden')
            });
        }

        // Ride Now Form (uses pickup_lat/pickup_lng and dropoff_lat/dropoff_lng)
        const rideNowForm = document.getElementById('ride_now-form');
        if (rideNowForm) {
            const pickupLat = rideNowForm.querySelector('input[name="pickup_lat"]');
            const pickupLng = rideNowForm.querySelector('input[name="pickup_lng"]');
            const dropoffLat = rideNowForm.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = rideNowForm.querySelector('input[name="dropoff_lng"]');

            console.log('Ride Now coordinates:', {
                pickupLat: pickupLat?.value || 'not found',
                pickupLng: pickupLng?.value || 'not found',
                dropoffLat: dropoffLat?.value || 'not found',
                dropoffLng: dropoffLng?.value || 'not found',
                formVisible: !rideForm.classList.contains('hidden')
            });
        }

        const dayRentalForm = document.getElementById('day_rental-form');
        if (dayRentalForm) {
            const pickupLat = dayRentalForm.querySelector('input[name="pickup_lat"]');
            const pickupLng = dayRentalForm.querySelector('input[name="pickup_lng"]');
            const dropoffLat = dayRentalForm.querySelector('input[name="dropoff_lat"]');
            const dropoffLng = dayRentalForm.querySelector('input[name="dropoff_lng"]');

            console.log('Ride Now coordinates:', {
                pickupLat: pickupLat?.value || 'not found',
                pickupLng: pickupLng?.value || 'not found',
                dropoffLat: dropoffLat?.value || 'not found',
                dropoffLng: dropoffLng?.value || 'not found',
                formVisible: !rideForm.classList.contains('hidden')
            });
        }
    }

    /**
     * Force coordinate update from airport selects on page load
     */
    function forceAirportCoordinateUpdate() {
        const airportForm = document.getElementById("airport_transfers-form");
        if (!airportForm) return;

        const fromAirportSelect = airportForm.querySelector('#from-airport-select');
        const toAirportSelect = airportForm.querySelector('#to-airport-select');

        // Trigger coordinate updates for any pre-selected airports
        if (fromAirportSelect && fromAirportSelect.value && !fromAirportSelect.classList.contains('hidden')) {
            fromAirportSelect.dispatchEvent(new Event('change'));
        }

        if (toAirportSelect && toAirportSelect.value && !toAirportSelect.classList.contains('hidden')) {
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
        const toAirportSelect = form.querySelector('#to-airport-select');
        const fromLat = form.querySelector('input[name="pickup_lat"]');
        const fromLng = form.querySelector('input[name="pickup_lng"]');
        const toLat = form.querySelector('input[name="dropoff_lat"]');
        const toLng = form.querySelector('input[name="dropoff_lng"]');

        // Force coordinate update from selected airport options
        if (fromAirportSelect && !fromAirportSelect.classList.contains('hidden') && fromAirportSelect.value) {
            const selectedOption = fromAirportSelect.options[fromAirportSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                if (fromLat && fromLng) {
                    fromLat.value = selectedOption.dataset.lat;
                    fromLng.value = selectedOption.dataset.lng;
                    console.log('Forced FROM airport coordinates:', {
                        airport: fromAirportSelect.value,
                        lat: fromLat.value,
                        lng: fromLng.value
                    });
                }
            }
        }

        if (toAirportSelect && !toAirportSelect.classList.contains('hidden') && toAirportSelect.value) {
            const selectedOption = toAirportSelect.options[toAirportSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng) {
                if (toLat && toLng) {
                    toLat.value = selectedOption.dataset.lat;
                    toLng.value = selectedOption.dataset.lng;
                    console.log('Forced TO airport coordinates:', {
                        airport: toAirportSelect.value,
                        lat: toLat.value,
                        lng: toLng.value
                    });
                }
            }
        }
    }

    /**
     * Ensure all coordinate fields have valid values
     */
    function ensureCoordinateValues() {
        console.log('Ensuring coordinate values are set...');

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
                console.log('Set default airport FROM lat:', fromLat.value);
            }
            if (fromLng && (!fromLng.value || fromLng.value === '')) {
                fromLng.value = '79.8841'; // BIA Airport
                console.log('Set default airport FROM lng:', fromLng.value);
            }
            if (toLat && (!toLat.value || toLat.value === '')) {
                toLat.value = '6.9271'; // Colombo
                console.log('Set default airport TO lat:', toLat.value);
            }
            if (toLng && (!toLng.value || toLng.value === '')) {
                toLng.value = '79.8612'; // Colombo  
                console.log('Set default airport TO lng:', toLng.value);
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
                console.log('Set default ride pickup lat:', pickupLat.value);
            }
            if (pickupLng && (!pickupLng.value || pickupLng.value === '')) {
                pickupLng.value = '79.8612'; // Colombo
                console.log('Set default ride pickup lng:', pickupLng.value);
            }
            if (dropoffLat && (!dropoffLat.value || dropoffLat.value === '')) {
                dropoffLat.value = '6.0535'; // Galle
                console.log('Set default ride dropoff lat:', dropoffLat.value);
            }
            if (dropoffLng && (!dropoffLng.value || dropoffLng.value === '')) {
                dropoffLng.value = '80.221'; // Galle
                console.log('Set default ride dropoff lng:', dropoffLng.value);
            }
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }

    // Add debugging capabilities and force coordinate updates
    setTimeout(() => {
        console.log('=== INITIAL COORDINATE CHECK ===');
        logCoordinateValues();

        // Ensure coordinates are set with fallback values
        ensureCoordinateValues();
        forceAirportCoordinateUpdate();

        // Log again after forced updates
        setTimeout(() => {
            console.log('=== AFTER COORDINATE INITIALIZATION ===');
            logCoordinateValues();
        }, 1000);
    }, 1500);

    // Expose functions globally for use in Blade templates
    window.updateAirportTransferLocations = updateAirportTransferLocations;
    window.logCoordinateValues = logCoordinateValues;
})();
