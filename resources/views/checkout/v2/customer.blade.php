@if ($customerLookupEnabled)
    <section id="checkout-customer-choice" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm" aria-labelledby="checkout-customer-choice-title">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 id="checkout-customer-choice-title" class="flex items-center gap-2 text-base font-bold text-slate-900"><span class="flex h-6 w-6 items-center justify-center rounded-md bg-slate-900 text-xs font-bold text-white">1</span>Who is booking?</h2>
            <span class="text-xs text-slate-500">Choose an option</span>
        </div>
        <div id="checkout-customer-options" class="grid grid-cols-1 gap-3 sm:grid-cols-2" role="radiogroup" aria-labelledby="checkout-customer-choice-title">
            @if ($canBookAsNewCustomer)
                <label data-v2-choice class="relative flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
                    <input type="radio" name="checkout_customer_kind" value="new" @checked(old('first_name') || (!$verifiedCheckoutCustomer && $showCheckoutDetails)) class="mt-0.5 h-4 w-4 border-slate-300 text-red-600 focus:ring-red-500">
                    <span><strong class="block text-sm font-semibold text-slate-900">I’m a new customer</strong><small class="mt-0.5 block text-xs text-slate-500">Enter your contact and booking details below.</small></span>
                </label>
            @endif
            <label data-v2-choice class="relative flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
                <input type="radio" name="checkout_customer_kind" value="returning" @checked($verifiedCheckoutCustomer || !$canBookAsNewCustomer) class="mt-0.5 h-4 w-4 border-slate-300 text-red-600 focus:ring-red-500">
                <span><strong class="block text-sm font-semibold text-slate-900">Returning customer</strong><small class="mt-0.5 block text-xs text-slate-500">Verify your email or mobile to load saved details.</small></span>
            </label>
        </div>
    </section>
    <section id="returning-customer-panel" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm" aria-labelledby="returning-customer-title" @if ($verifiedCheckoutCustomer || ($canBookAsNewCustomer && !$showCheckoutDetails)) hidden @endif>
        <h2 id="returning-customer-title" class="text-base font-bold text-slate-900">Find your saved details</h2>
        <p class="mt-1 text-xs text-slate-500">Enter the email or mobile number used for an earlier booking. We’ll send a code before showing your details. @if (!$canBookAsNewCustomer) New customers need to sign in to book. @endif</p>
        <fieldset class="mt-4 border-0 p-0">
            <legend class="mb-2 text-xs font-semibold text-slate-700">Choose how we should send your code</legend>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Choose email or mobile verification">
                <label data-v2-choice class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-3"><input type="radio" name="checkout_lookup_channel" value="email" checked class="h-4 w-4 text-red-600"><span class="text-sm font-semibold text-slate-900">Email</span></label>
                <label data-v2-choice class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-3"><input type="radio" name="checkout_lookup_channel" value="mobile" class="h-4 w-4 text-red-600"><span class="text-sm font-semibold text-slate-900">Mobile</span></label>
            </div>
        </fieldset>
        <div id="customer-lookup-email-field" class="mt-4"><label for="customer-lookup-email" class="mb-1.5 block text-xs font-semibold text-slate-700">Email address</label><input type="email" id="customer-lookup-email" autocomplete="email" placeholder="you@example.com" class="w-full rounded-xl border-slate-200 px-3.5 py-2.5 text-sm"></div>
        <div id="customer-lookup-mobile-field" class="mt-4" hidden><label for="customer-lookup-mobile" class="mb-1.5 block text-xs font-semibold text-slate-700">Mobile number</label><input type="tel" id="customer-lookup-mobile" autocomplete="tel" placeholder="Enter mobile number" class="w-full rounded-xl border-slate-200 py-2.5 text-sm"><div id="customer-lookup-mobile-error" class="mt-1 text-xs text-red-600" role="status" aria-live="polite"></div></div>
        <button type="button" id="send-customer-code" class="mt-4 rounded-xl bg-red-600 px-4 py-2.5 text-xs font-bold text-white">Send code</button>
        <div id="customer-email-code-panel" class="mt-4" hidden><label for="customer-email-code" class="mb-1.5 block text-xs font-semibold text-slate-700">Enter the 6-digit code</label><div class="flex flex-wrap gap-2"><input id="customer-email-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" aria-describedby="customer-email-code-status" class="min-w-0 flex-1 rounded-xl border-slate-200 px-3.5 py-2.5 text-sm"><button type="button" id="verify-customer-email-code" class="rounded-xl bg-red-600 px-4 py-2.5 text-xs font-bold text-white">Verify code</button></div></div>
        <div id="customer-email-code-status" class="mt-2 text-xs text-slate-500" role="status" aria-live="polite"></div>
    </section>
@endif
