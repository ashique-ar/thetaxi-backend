<section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm" data-purpose="billing-information-card">
    <div class="mb-5 flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
        <div>
            <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900"><span class="flex h-6 w-6 items-center justify-center rounded-md bg-slate-900 text-xs font-bold text-white">2</span>Billing &amp; Driver Information</h2>
            <p class="mt-0.5 text-xs text-slate-500">Required for your booking and contact details</p>
        </div>
        <span class="whitespace-nowrap text-xs font-medium text-red-500">* Required</span>
    </div>
    @if (!$customerLookupEnabled && $guestBookingEnabled)
        <p class="mb-4 text-xs text-slate-500">You can book as a guest. If your email or mobile is already on file, we’ll use that customer record without changing its saved details.</p>
    @endif
    <button type="button" id="reset-customer-details" class="mb-3 text-xs font-semibold text-red-700 underline" @unless ($verifiedCheckoutCustomer) hidden @endunless>Use different details</button>
    <p id="verified-customer-status" class="mb-3 text-xs text-emerald-700" hidden>Saved details loaded. Check them and update anything that has changed.</p>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="v2-first-name" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">First name <span class="text-red-500">*</span></label>
            <input id="v2-first-name" name="first_name" type="text" required autocomplete="given-name" value="{{ old('first_name', $tourCheckoutCustomer?->user?->first_name) }}" placeholder="Enter your first name" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
            @error('first_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="v2-last-name" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">Last name</label>
            <input id="v2-last-name" name="last_name" type="text" autocomplete="family-name" value="{{ old('last_name', $tourCheckoutCustomer?->user?->last_name) }}" placeholder="Enter your last name" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
            @error('last_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div id="checkout-phone-field">
            <label for="phone-input" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">Mobile number <span class="checkout-contact-required text-red-500">*</span></label>
            <input id="phone-input" name="phone" type="tel" required autocomplete="tel" value="{{ old('phone', Auth::user()?->phone ?? $tourCheckoutCustomer?->user?->phone) }}" placeholder="Mobile number" class="w-full rounded-xl border-slate-200 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
            <input id="phone-country-code" name="phone_country_code" type="hidden" value="{{ old('phone_country_code') }}">
            <input id="phone-international" name="phone_international" type="hidden" value="{{ old('phone_international') }}">
            <div id="phone-error" class="mt-1 text-xs text-red-600" style="display:none"></div><div id="phone-valid" class="mt-1 text-xs text-emerald-700" style="display:none"></div>
            @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div id="checkout-email-field">
            <label for="v2-email" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">Email address <span class="checkout-contact-required text-red-500">*</span></label>
            <input id="v2-email" name="email" type="email" required autocomplete="email" value="{{ old('email', Auth::user()?->email ?? $tourCheckoutCustomer?->user?->email) }}" placeholder="Enter email address" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        @foreach (['address' => ['Street address', 'street-address'], 'city' => ['City', 'address-level2']] as $field => [$label, $autocomplete])
            <div>
                <label for="v2-{{ $field }}" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">{{ $label }} <span class="text-red-500">*</span></label>
                <input id="v2-{{ $field }}" name="{{ $field }}" type="text" required autocomplete="{{ $autocomplete }}" value="{{ old($field) }}" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
                @error($field) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div>
            <label for="country-select" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">Country <span class="text-red-500">*</span></label>
            <select id="country-select" name="country" required class="no-nice w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
                <option value="">Select Country</option>
                @foreach ($countries as $country)
                    <option value="{{ $country->name }}" @selected(old('country', $checkoutDefaultCountryName) === $country->name)>{{ $country->name }} @if ($country->callcode) (+{{ $country->callcode }}) @endif</option>
                @endforeach
            </select>
            @error('country') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="v2-identification" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">National ID / Passport</label>
            <input id="v2-identification" name="identification" type="text" value="{{ old('identification') }}" placeholder="ID/Passport number (optional)" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">
            @error('identification') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        @if ($hasAirportTransfer)
            <div class="border-t border-slate-100 pt-4 sm:col-span-2"><h3 class="text-sm font-bold text-slate-900">Flight details (optional)</h3><p class="mt-1 text-xs text-slate-500">Provide details for airport pickup.</p></div>
            @foreach (['flight_airline' => ['Airline', 'text'], 'flight_number' => ['Flight number', 'text'], 'flight_arrival_date' => ['Arrival date', 'date'], 'flight_arrival_time' => ['Arrival time', 'time']] as $field => [$label, $type])
                <div><label for="v2-{{ $field }}" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">{{ $label }}</label><input id="v2-{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ old($field) }}" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500"></div>
            @endforeach
        @endif
        <div class="sm:col-span-2">
            <label for="checkoutAdditionalNotes" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-700">Anything else we should know? <span class="normal-case text-slate-400">(optional)</span></label>
            <textarea id="checkoutAdditionalNotes" name="additional_notes" maxlength="1000" rows="3" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm focus:border-red-500 focus:ring-red-500">{{ old('additional_notes', old('special_notes')) }}</textarea>
        </div>
    </div>
    @include('checkout.v2.terms')
    <div class="mt-4 space-y-3 border-t border-slate-100 pt-4 text-xs text-slate-600">
        <label class="flex items-start gap-3"><input type="checkbox" name="save_info" value="1" id="saveInfo" @checked(old('save_info')) class="rounded border-slate-300 text-red-600 focus:ring-red-500"><span>Save my information for future bookings</span></label>
        <label class="flex items-start gap-3"><input type="checkbox" name="marketing_consent" value="1" id="marketingConsent" @checked(old('marketing_consent')) class="rounded border-slate-300 text-red-600 focus:ring-red-500"><span>I would like to receive marketing communications, special offers, and promotional emails. You can unsubscribe at any time.</span></label>
    </div>
</section>
