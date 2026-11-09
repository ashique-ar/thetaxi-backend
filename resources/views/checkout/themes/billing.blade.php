                            <div class="reservation-card reservation-billing">
                                <div class="reservation-card__heading">
                                    <h2>Billing &amp; Lead Passenger</h2><p class="reservation-card__intro">The primary driver or voucher recipient</p>
                                    @if (!$customerLookupEnabled && $guestBookingEnabled)
                                        <p class="small text-muted mb-0">You can book as a guest. If your email or mobile is already on file, we’ll use that customer record without changing its saved details.</p>
                                    @endif
                                    @if ($verifiedCheckoutCustomer)
                                        <button type="button" id="reset-customer-details" class="btn btn-link btn-sm px-0">Use different details</button>
                                    @else
                                        <button type="button" id="reset-customer-details" class="btn btn-link btn-sm px-0" hidden>Use different details</button>
                                    @endif
                                    <p id="verified-customer-status" class="small text-success mb-0" hidden>Saved details loaded. Check them and update anything that has changed.</p>
                                </div>
                                <div class="reservation-billing__fields">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>First Name*</label>
                                                <input type="text" name="first_name" placeholder="Enter your first name"
                                                    required value="{{ old('first_name', $tourCheckoutCustomer?->user?->first_name) }}">
                                                @error('first_name')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>Last Name*</label>
                                                <input type="text" name="last_name" placeholder="Enter your last name"
                                                    value="{{ old('last_name', $tourCheckoutCustomer?->user?->last_name) }}">
                                                @error('last_name')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="checkout-phone-field">
                                            <div class="reservation-field">
                                                <label>Mobile Number<span class="checkout-contact-required">*</span></label>
                                                <input type="tel" id="phone-input" name="phone"
                                                    placeholder="Mobile number" autocomplete="tel"
                                                    required
                                                    value="{{ old('phone', Auth::user()?->phone ?? $tourCheckoutCustomer?->user?->phone) }}" style="padding-left: 48px;">
                                                <div id="phone-error" class="text-danger mt-2" style="display: none;">
                                                </div>
                                                <div id="phone-valid" class="text-success small mt-1"
                                                    style="display: none;"></div>
                                                <!-- Hidden fields for additional phone data -->
                                                <input type="hidden" id="phone-country-code" name="phone_country_code"
                                                    value="{{ old('phone_country_code') }}">
                                                <input type="hidden" id="phone-international" name="phone_international"
                                                    value="{{ old('phone_international') }}">
                                                @error('phone')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="checkout-email-field">
                                            <div class="reservation-field">
                                                <label>Email Address<span class="checkout-contact-required">*</span></label>
                                                <input type="email" name="email" placeholder="Enter email address"
                                                    required
                                                    value="{{ old('email', Auth::user()?->email ?? $tourCheckoutCustomer?->user?->email) }}">
                                                @error('email')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>Street Address*</label>
                                                <input type="text" name="address" placeholder="Enter street address"
                                                    required value="{{ old('address') }}">
                                                @error('address')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>City*</label>
                                                <input type="text" name="city" placeholder="Enter city" required
                                                    value="{{ old('city') }}">
                                                @error('city')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>Country*</label>
                                                <select id="country-select" name="country" required class="no-nice">
                                                    <option value="">Select Country</option>
                                                    @foreach ($countries as $country)
                                                        <option value="{{ $country->name }}"
                                                            {{ old('country', $checkoutDefaultCountryName) === $country->name ? 'selected' : '' }}>
                                                            {{ $country->name }}
                                                            @if ($country->callcode)
                                                                (+{{ $country->callcode }})
                                                            @endif
                                                        </option>
                                                    @endforeach
                                                </select>
                                                @error('country')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>National ID / Passport</label>
                                                <input type="text" name="identification"
                                                    placeholder="ID/Passport number (optional)"
                                                    value="{{ old('identification') }}">
                                                @error('identification')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        @if ($hasAirportTransfer)
                                        <div class="col-md-12">
                                            <div class="reservation-section-heading">
                                                <h6>Flight Details (Optional)</h6>
                                                <p class="text-muted">If arriving by flight, provide details for airport
                                                    pickup</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>Airline</label>
                                                <input type="text" name="flight_airline"
                                                    placeholder="e.g., Sri Lankan Airlines"
                                                    value="{{ old('flight_airline') }}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>Flight Number</label>
                                                <input type="text" name="flight_number" placeholder="e.g., UL123"
                                                    value="{{ old('flight_number') }}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>Arrival Date</label>
                                                <input type="date" name="flight_arrival_date"
                                                    value="{{ old('flight_arrival_date') }}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="reservation-field">
                                                <label>Arrival Time</label>
                                                <input type="time" name="flight_arrival_time"
                                                    value="{{ old('flight_arrival_time') }}">
                                            </div>
                                        </div>
                                        @endif
                                        <div class="col-md-12">
                                            <div class="reservation-field">
                                                <label for="checkoutAdditionalNotes">Anything else we should know? <span class="text-muted">(optional)</span></label>
                                                <textarea id="checkoutAdditionalNotes" name="additional_notes" maxlength="1000" placeholder="Anything you'd like us to know about your booking...">{{ old('additional_notes', old('special_notes')) }}</textarea>
                                            </div>
                                        </div>

                                        @php
                                            // Build a deduplicated map of applicable terms with metadata
                                            $uniqueTerms = [];

                                            // Terms grouped by service
                                            foreach ($termsByService as $service => $terms) {
                                                foreach ($terms as $tc) {
                                                    if (!isset($uniqueTerms[$tc->id])) {
                                                        $uniqueTerms[$tc->id] = [
                                                            'term' => $tc,
                                                            'services' => [],
                                                            'payment_types' => [],
                                                        ];
                                                    }
                                                    $uniqueTerms[$tc->id]['services'][] = ucfirst(
                                                        str_replace('_', ' ', $service),
                                                    );
                                                }
                                            }

                                            // Merge payment-type terms, record which payment types each term applies to
                                            foreach ($termsByPaymentType as $ptype => $terms) {
                                                foreach ($terms as $tc) {
                                                    if (!isset($uniqueTerms[$tc->id])) {
                                                        $uniqueTerms[$tc->id] = [
                                                            'term' => $tc,
                                                            'services' => [],
                                                            'payment_types' => [],
                                                        ];
                                                    }
                                                    $uniqueTerms[$tc->id]['payment_types'][] = $ptype;
                                                }
                                            }

                                            // Convert service and payment type arrays to unique lists
                                            foreach ($uniqueTerms as $id => $meta) {
                                                $uniqueTerms[$id]['services'] = array_values(
                                                    array_unique($meta['services']),
                                                );
                                                $uniqueTerms[$id]['payment_types'] = array_values(
                                                    array_unique($meta['payment_types']),
                                                );
                                            }
                                        @endphp

                                        @if (!empty($uniqueTerms))
                                            <div class="col-md-12">
                                                <div class="terms-conditions-section">
                                                    <h6>Applicable Terms & Conditions</h6>
                                                    <div class="terms-content">
                                                        @foreach ($uniqueTerms as $meta)
                                                            @php
                                                                $tc = $meta['term'];
                                                                $services = $meta['services'];
                                                                $pTypes = $meta['payment_types'];
                                                            @endphp
                                                            <div class="term-item mb-3"
                                                                data-payment-types="{{ implode(',', $pTypes) }}"
                                                                data-term-id="{{ $tc->id }}"
                                                                data-term-version="{{ $tc->version }}">
                                                                <div class="term-header">
                                                                    <div class="term-meta">
                                                                        <strong>{{ $tc->title }}</strong>
                                                                        @if (!empty($services))
                                                                            <small class="text-muted"> — Applies to:
                                                                                {{ implode(', ', $services) }}</small>
                                                                        @endif
                                                                        @if (!empty($pTypes))
                                                                            <small class="text-muted">
                                                                                <em>(Payment-specific:
                                                                                    {{ implode(', ', $pTypes) }})</em></small>
                                                                        @endif
                                                                    </div>
                                                                    <button type="button" class="term-toggle"
                                                                        aria-expanded="true">Hide</button>
                                                                </div>
                                                                <div class="term-body mt-2">
                                                                    {!! $tc->content !!}
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>

                                                    <div class="terms-accept-all-container mt-3" id="termsAcceptancePanel">
                                                        <div class="form-check terms-accept-check">
                                                            <input class="form-check-input" type="checkbox"
                                                                id="accept_all_terms" aria-describedby="termsAcceptHelp">
                                                            <label class="form-check-label" for="accept_all_terms">
                                                                <span class="terms-accept-title">I agree to the above
                                                                    <strong>Terms & Conditions</strong></span>
                                                                <span class="terms-accept-copy" id="termsAcceptHelp">
                                                                    Required before placing your booking.
                                                                </span>
                                                            </label>
                                                        </div>
                                                        <div class="terms-accept-error" id="termsAcceptError" role="alert">
                                                            Please tick this box to continue with your booking.
                                                        </div>
                                                        <div id="termsAcceptedHiddenInputs"></div>
                                                    </div>

                                                </div>
                                            </div>

                                        @endif

                                        <div class="col-md-12 mt-3 pt-3 border-top">
                                            <div class="reservation-preference">
                                                <p class="text-muted small mb-2">Optional preferences</p>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="save_info"
                                                        value="1" id="saveInfo"
                                                        {{ old('save_info') ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="saveInfo">
                                                        Save my information for future bookings
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Marketing Consent Checkbox -->
                                        <div class="col-md-12">
                                            <div class="reservation-preference">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="marketing_consent"
                                                        value="1" id="marketingConsent"
                                                        {{ old('marketing_consent') ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="marketingConsent">
                                                        I would like to receive marketing communications, special offers, and promotional emails. 
                                                        You can unsubscribe at any time.
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
