<div class="filter-wrapper">
    <ul class="filter-item-list">
        <li class="single-item active" data-service="airport-transfer">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
            </svg>
            <span>Airport Transfer</span>
        </li>
        <li class="single-item" data-service="drop-pickup">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
            </svg>
            <span>Drop & Pickup</span>
        </li>
        <li class="single-item" data-service="rental-packages">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M17 5h-2v2h2v2h2V7h2V5h-2V3h-2v2zm-2 4V7H9.01L3 13.01V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6h-6zM5 19v-4.99l4-4 4 4L9 18H5zm14 0h-6v-4l-2-2-4 4v2h12z"/>
            </svg>
            <span>Rental Packages</span>
        </li>
        <li class="single-item" data-service="custom-tour">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2zm0 2.5l-2.33 4.68-5.15.75 3.73 3.63-.88 5.13L12 16.77l4.63 2.42-.88-5.13 3.73-3.63-5.15-.75L12 4.5z"/>
            </svg>
            <span>Custom Tour</span>
        </li>
        <li class="single-item" data-service="corporate-transport">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>
            </svg>
            <span>Corporate Transport</span>
        </li>
    </ul>

    <div class="filter-input-wrap">
        <!-- Airport Transfer Form -->
        <form id="airport-transfer-form" class="filter-input show" data-service="airport-transfer" action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="airport-transfer">
            
            <!-- Transfer Type Selection - Compact Toggle Style -->
            <div class="transfer-type-selector">
                <div class="transfer-type-toggle">
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="from-airport" checked>
                        <span>From Airport</span>
                    </label>
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="to-airport">
                        <span>To Airport</span>
                    </label>
                </div>
            </div>

            <!-- From Location -->
            <div class="single-search-box from-location location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" class="from-display location-search" placeholder="Enter pickup location" readonly>
                    <input type="hidden" name="from" class="from-value">
                    <input type="hidden" name="from_lat" class="location-lat">
                    <input type="hidden" name="from_lng" class="location-lng">
                </div>
            </div>

            <!-- To Location -->
            <div class="single-search-box to-location location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" class="to-display location-search" placeholder="Enter destination" readonly>
                    <input type="hidden" name="to" class="to-value">
                    <input type="hidden" name="to_lat" class="location-lat">
                    <input type="hidden" name="to_lng" class="location-lng">
                </div>
            </div>

            <!-- Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                </svg>
                <input type="text" name="date" placeholder="DD/MM/YYYY" class="custom-datepicker" required autocomplete="off">
            </div>

            <!-- Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="time" value="00:00" required>
                </div>
            </div>

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Drop & Pickup Form -->
        <form id="drop-pickup-form" class="filter-input" data-service="drop-pickup" action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="drop-pickup">
            
            <!-- Pickup Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="pickup" placeholder="Pickup Location" class="location-search" required>
                    <input type="hidden" name="pickup_lat" class="location-lat">
                    <input type="hidden" name="pickup_lng" class="location-lng">
                </div>
            </div>

            <!-- Drop Off Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="dropoff" placeholder="Drop Off Location" class="location-search" required>
                    <input type="hidden" name="dropoff_lat" class="location-lat">
                    <input type="hidden" name="dropoff_lng" class="location-lng">
                </div>
            </div>

            <!-- Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                </svg>
                <input type="text" name="date" placeholder="DD/MM/YYYY" class="custom-datepicker" required autocomplete="off">
            </div>

            <!-- Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="time" value="00:00" required>
                </div>
            </div>

            <!-- Return Transfer Toggle Switch -->
            <div class="return-transfer-toggle-container">
                <label class="toggle-switch">
                    <input type="checkbox" id="need-return" name="need_return" value="1">
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Return Transfer</span>
                </label>
            </div>

            <!-- Return Transfer Fields (Hidden by default) -->
            <div id="return-transfer-fields" style="display: none;">
                <div class="return-transfer-header">
                    <h5>Return Transfer Details</h5>
                </div>
                
                <!-- Return Pickup Location -->
                <div class="single-search-box location-search-box">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                            <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                        </g>
                    </svg>
                    <div class="custom-select-dropdown">
                        <input type="text" name="return_pickup" placeholder="Return Pickup Location">
                    </div>
                </div>

                <!-- Return Drop Off Location -->
                <div class="single-search-box location-search-box">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                            <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                        </g>
                    </svg>
                    <div class="custom-select-dropdown">
                        <input type="text" name="return_dropoff" placeholder="Return Drop Off Location">
                    </div>
                </div>

                <!-- Return Date -->
                <div class="single-search-box date-field">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                    </svg>
                    <input type="text" name="return_date" placeholder="DD/MM/YYYY" class="custom-datepicker" autocomplete="off">
                </div>

                <!-- Return Time -->
                <div class="single-search-box">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                    </svg>
                    <div class="custom-select-dropdown">
                        <input type="time" name="return_time" value="00:00">
                    </div>
                </div>
            </div>

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Rental Packages Form -->
        <form id="rental-packages-form" class="filter-input" data-service="rental-packages" action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="rental-packages">
            
            <!-- Package Type Selection -->
            <div class="package-type-selector">
                <div style="display: flex; gap: 20px; justify-content: center;">
                    <label>
                        <input type="radio" name="package_type" value="taxi-100km" checked>
                        <span>TAXI 100 KM per day</span>
                    </label>
                    <label>
                        <input type="radio" name="package_type" value="tour-200km">
                        <span>TOUR 200 KM Per day</span>
                    </label>
                </div>
            </div>

            <!-- Pickup Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="pickup" placeholder="Pick up Location" class="location-search" required>
                    <input type="hidden" name="pickup_lat" class="location-lat">
                    <input type="hidden" name="pickup_lng" class="location-lng">
                </div>
            </div>

            <!-- Drop Off Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="dropoff" placeholder="Drop Off Location" class="location-search" required>
                    <input type="hidden" name="dropoff_lat" class="location-lat">
                    <input type="hidden" name="dropoff_lng" class="location-lng">
                </div>
            </div>

            <!-- Pickup Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                </svg>
                <input type="text" name="pickup_date" placeholder="DD/MM/YYYY" class="custom-datepicker" required autocomplete="off">
            </div>

            <!-- Pickup Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="pickup_time" value="00:00" required>
                </div>
            </div>

            <!-- Drop Off Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                </svg>
                <input type="text" name="dropoff_date" placeholder="DD/MM/YYYY" class="custom-datepicker" required autocomplete="off">
            </div>

            <!-- Drop Off Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="dropoff_time" value="00:00" required>
                </div>
            </div>

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Custom Tour Form -->
        <form id="custom-tour-form" class="filter-input" data-service="custom-tour" action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="custom-tour">
            
            <!-- Tour Title -->
            <div class="single-search-box" style="width: 100%; margin-bottom: 15px;">
                <input type="text" name="tour_title" placeholder="Tour Title (Optional)" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px;">
            </div>

            <!-- Starting Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="starting_location" placeholder="Starting Location" class="location-search" required>
                    <input type="hidden" name="starting_lat" class="location-lat">
                    <input type="hidden" name="starting_lng" class="location-lng">
                </div>
            </div>

            <!-- Tour Dates -->
            <div class="single-search-box date-range-field" style="width: 100%; margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 500;">Tour Period</label>
                <div style="display: flex; gap: 10px;">
                    <div style="flex: 1;">
                        <input type="text" name="start_date" placeholder="DD/MM/YYYY" class="custom-datepicker" required autocomplete="off">
                    </div>
                    <div style="flex: 1;">
                        <input type="text" name="end_date" placeholder="DD/MM/YYYY" class="custom-datepicker" required autocomplete="off">
                    </div>
                </div>
            </div>

            <!-- Destinations Container -->
            <div id="tour-destinations-section" style="width: 100%; margin-top: 20px;">
                <div class="destinations-header">
                    <h5>Tour Destinations</h5>
                    <button type="button" class="btn-add-destination" id="add-destination">
                        <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg" style="margin-right: 4px;">
                            <path d="M14 8H8V14H6V8H0V6H6V0H8V6H14V8Z" fill="currentColor"/>
                        </svg>
                        Add Destination
                    </button>
                </div>
                
                <div id="destinations-container">
                    <!-- Initial destination -->
                    <div class="destination-item" draggable="true" data-index="0">
                        <div class="destination-item-header">
                            <div class="d-flex align-items-center">
                                <div class="drag-handle me-2" style="cursor: grab;">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                        <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                </div>
                                <h6 class="destination-title mb-0">Destination 1</h6>
                            </div>
                            <button type="button" class="destination-remove" style="display: none;">
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
                                        <input type="text" name="destinations[0][location]" placeholder="Destination Location" class="location-search" required>
                                        <input type="hidden" name="destinations[0][lat]" class="location-lat">
                                        <input type="hidden" name="destinations[0][lng]" class="location-lng">
                                    </div>
                                </div>
                            </div>
                            <div class="destination-datetime">
                                <div class="single-search-box date-field">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z"/>
                                    </svg>
                                    <input type="text" name="destinations[0][visit_date]" placeholder="DD/MM/YYYY" class="custom-datepicker" required autocomplete="off">
                                </div>
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z"/>
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="time" name="destinations[0][visit_time]" value="09:00" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="destination-notes">
                            <textarea name="destinations[0][notes]" placeholder="Special notes for this destination (optional)" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Bottom Add Destination Button -->
                <div class="text-center mt-3">
                    <button type="button" class="btn-add-destination" id="add-destination-bottom">
                        <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg" style="margin-right: 4px;">
                            <path d="M14 8H8V14H6V8H0V6H6V0H8V6H14V8Z" fill="currentColor"/>
                        </svg>
                        Add Another Destination
                    </button>
                </div>
            </div>

            <div style="display: flex; gap: 15px; width: 100%; margin-top: 20px;">
                <button type="button" class="btn btn-info" id="check-route-btn" style="flex: 1;">
                    <i class="bi bi-map"></i> Check Route & Distance
                </button>
                <button type="submit" class="primary-btn1" style="flex: 1;">
                    <span>Search For Vehicles</span>
                </button>
            </div>
        </form>

        <!-- Corporate Transport Form -->
        <form id="corporate-transport-form" class="filter-input" data-service="corporate-transport" action="{{ route('booking.enquiry') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="corporate-transport">
            
            <!-- Company Name -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="company_name" placeholder="Company Name" required>
                </div>
            </div>

            <!-- Contact Person -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 9c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="contact_person" placeholder="Contact Person" required>
                </div>
            </div>

            <!-- Email -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M16 2H2C0.9 2 0.01 2.9 0.01 4L0 14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 4l-7 4.5L2 6V4l7 4.5L16 4v2z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="email" name="email" placeholder="Email Address" required>
                </div>
            </div>

            <!-- Phone -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3.62 7.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V17c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="tel" name="phone" placeholder="Phone Number" required>
                </div>
            </div>

            <!-- Service Requirements - Full Width -->
            <div class="corporate-requirements-field">
                <textarea name="requirements" placeholder="Describe your corporate transport requirements..." rows="4" required></textarea>
            </div>

            <button type="submit" class="primary-btn1 corporate-submit-btn">
                <span>Submit Enquiry</span>
            </button>
        </form>
    </div>
</div>

<!-- Route Check Modal -->
<div class="modal fade" id="routeModal" tabindex="-1" aria-labelledby="routeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="routeModalLabel">
                    <i class="bi bi-map"></i> Route Preview & Distance Calculation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div id="routeMap" style="height: 400px; border-radius: 8px;"></div>
                    </div>
                    <div class="col-md-4">
                        <div class="route-info">
                            <h6 class="mb-3">Route Information</h6>
                            <div id="routeDetails">
                                <div class="info-item mb-2">
                                    <strong>Total Distance:</strong>
                                    <span id="totalDistance">Calculating...</span>
                                </div>
                                <div class="info-item mb-2">
                                    <strong>Estimated Duration:</strong>
                                    <span id="totalDuration">Calculating...</span>
                                </div>
                                <div class="info-item mb-3">
                                    <strong>Number of Stops:</strong>
                                    <span id="totalStops">0</span>
                                </div>
                            </div>
                            
                            <h6 class="mb-2">Destination List</h6>
                            <div id="destinationList" class="destination-list">
                                <!-- Destinations will be populated here -->
                            </div>
                            
                            <!-- Add Destination Button in Modal -->
                            <button type="button" class="btn btn-primary btn-sm w-100 mt-2" id="addDestinationModal">
                                <i class="bi bi-plus-circle"></i> Add Another Destination
                            </button>
                            
                            <div class="route-actions mt-3">
                                <button type="button" class="btn btn-success" id="confirmRoute">
                                    <i class="bi bi-check-circle"></i> Confirm Route
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="bi bi-arrow-left"></i> Back to Edit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<!-- Leaflet CSS for route visualization fallback -->
<link rel="stylesheet" href="{{ asset('assets/css/leaflet.css') }}">

<!-- Leaflet JS -->
<script src="{{ asset('assets/js/leaflet.js') }}"></script>

<!-- jQuery UI for enhanced date pickers -->
<script>
// Enhanced date picker initialization if available
$(document).ready(function() {
    if ($.fn.datepicker) {
        $('.custom-datepicker').datepicker({
            format: 'dd/mm/yyyy',
            startDate: new Date(),
            autoclose: true,
            todayHighlight: true,
            orientation: 'bottom auto'
        });
    }
});
</script>
@endpush