                @if ($customerLookupEnabled)
                    <div id="checkout-customer-choice" class="reservation-card reservation-customer mb-3" aria-labelledby="checkout-customer-choice-title">
                        <div class="reservation-card__heading">
                            <div>
                                <h2 id="checkout-customer-choice-title">Who is booking?</h2>
                                <p class="text-muted mb-0">Choose the option that fits you. The right fields will appear below.</p>
                            </div>
                        </div>
                        <div id="checkout-customer-options" class="reservation-choices" role="radiogroup" aria-labelledby="checkout-customer-choice-title">
                            @if ($canBookAsNewCustomer)
                                <label class="reservation-choice">
                                    <input type="radio" name="checkout_customer_kind" value="new" @checked(old('first_name') || (!$verifiedCheckoutCustomer && $showCheckoutDetails))>
                                    <span class="reservation-choice__copy"><strong>I’m a new customer</strong><small>Enter your contact and booking details below.</small></span>
                                </label>
                            @endif
                            <label class="reservation-choice">
                                <input type="radio" name="checkout_customer_kind" value="returning" @checked($verifiedCheckoutCustomer || !$canBookAsNewCustomer)>
                                <span class="reservation-choice__copy"><strong>I’ve booked with you before</strong><small>Verify your email or mobile to load saved details.</small></span>
                            </label>
                        </div>
                    </div>
                    <div id="returning-customer-panel" class="reservation-card reservation-returning mb-3" aria-labelledby="returning-customer-title" @if ($verifiedCheckoutCustomer || ($canBookAsNewCustomer && !$showCheckoutDetails)) hidden @endif>
                        <div class="reservation-card__heading">
                            <h2 id="returning-customer-title">Find your saved details</h2>
                            <p class="small text-muted mb-0">Enter the email or mobile number used for an earlier booking. We’ll send a code before showing your details. @if (!$canBookAsNewCustomer) New customers need to sign in to book. @endif</p>
                        </div>
                        <div class="reservation-returning__fields">
                            <fieldset class="mb-3 border-0 p-0">
                                <legend class="fs-6 fw-semibold mb-2">Choose how we should send your code</legend>
                                <div class="reservation-choices" role="radiogroup" aria-label="Choose email or mobile verification">
                                    <label class="reservation-choice">
                                        <input type="radio" name="checkout_lookup_channel" value="email" checked>
                                        <span class="reservation-choice__copy"><strong>Email</strong><small>Get a code in your inbox.</small></span>
                                    </label>
                                    <label class="reservation-choice">
                                        <input type="radio" name="checkout_lookup_channel" value="mobile">
                                        <span class="reservation-choice__copy"><strong>Mobile</strong><small>Get a text message on your phone.</small></span>
                                    </label>
                                </div>
                            </fieldset>
                            <div class="row checkout-lookup-fields">
                                <div id="customer-lookup-email-field" class="col-md-6 mb-3">
                                    <label for="customer-lookup-email">Email address</label>
                                    <input type="email" id="customer-lookup-email" class="form-control" autocomplete="email" placeholder="you@example.com">
                                </div>
                                <div id="customer-lookup-mobile-field" class="col-md-6 mb-3" hidden>
                                    <label for="customer-lookup-mobile">Mobile number</label>
                                    <input type="tel" id="customer-lookup-mobile" class="form-control" autocomplete="tel" placeholder="Enter mobile number">
                                    <div id="customer-lookup-mobile-error" class="small text-danger mt-1" role="status" aria-live="polite"></div>
                                </div>
                            </div>
                            <button type="button" id="send-customer-code" class="reservation-action">Send code</button>
                            <div id="customer-email-code-panel" class="mt-3" hidden>
                                <label for="customer-email-code">Enter the 6-digit code</label>
                                <div class="d-flex gap-2">
                                    <input id="customer-email-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="form-control" aria-describedby="customer-email-code-status">
                                    <button type="button" id="verify-customer-email-code" class="reservation-action">Verify code</button>
                                </div>
                            </div>
                            <div id="customer-email-code-status" class="small mt-2" role="status" aria-live="polite"></div>
                        </div>
                    </div>
                @endif
