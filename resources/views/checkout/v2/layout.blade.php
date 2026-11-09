@php
    $canBookAsNewCustomer = $guestBookingEnabled || Auth::check();
    $showCheckoutDetails = (!$customerLookupEnabled && $canBookAsNewCustomer)
        || !empty($verifiedCheckoutCustomer) || !empty($tourCheckoutCustomer) || old('email') || old('first_name');
    $hideCheckoutDetails = !$showCheckoutDetails || ($customerLookupEnabled && !$verifiedCheckoutCustomer && !old('first_name') && !old('email'));
    $canShowCheckout = $canBookAsNewCustomer || Auth::check() || $customerLookupEnabled;
@endphp
<div id="checkout-v2" class="min-h-screen bg-slate-50 font-sans antialiased text-slate-800">
    <div data-popup-page="checkout"></div>
    @include('checkout.v2.header-' . $checkoutDesign)
    <main id="checkoutContentStart" class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @if (empty($cart))
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-center" role="status"><h2 class="text-lg font-bold text-slate-900">Your cart is empty!</h2><p class="mt-2 text-sm text-slate-600">Please add some vehicles to your cart before proceeding to checkout.</p><a href="{{ route('search') }}" class="mt-4 inline-block rounded-xl bg-checkout-accent px-5 py-3 text-sm font-bold text-white">Browse Vehicles</a></div>
        @else
            <div class="grid grid-cols-1 items-start gap-8 lg:grid-cols-12">
                <div class="space-y-6 lg:col-span-7">
                    @include('checkout.v2.customer')
                    @if ($canShowCheckout)
                        <div id="checkout-details-layout" @if ($hideCheckoutDetails) hidden @endif>
                            <form id="checkout-form" method="POST" action="{{ route('checkout.process') }}">
                                @csrf
                                @if ($errors->any() || session('error'))
                                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">@if (session('error')) <div>{{ session('error') }}</div> @endif @foreach ($errors->all() as $message) <div>{{ $message }}</div> @endforeach</div>
                                @endif
                                @include('checkout.v2.billing')
                            </form>
                        </div>
                    @elseif (!$customerLookupEnabled)
                        <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700" role="status">Guest bookings are currently unavailable. Please sign in to continue.</div>
                    @endif
                </div>
                <aside class="lg:sticky lg:top-24 lg:col-span-5" aria-label="Order summary and payment">@include('checkout.v2.order-summary')</aside>
            </div>
        @endif
    </main>
</div>
@include('checkout.v2.popups')
