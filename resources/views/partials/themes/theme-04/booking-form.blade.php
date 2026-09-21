@php($embedded = $embedded ?? false)

<section class="t4-booking-panel home-booking-form-section {{ $embedded ? 't4-booking-panel--embedded' : '' }}" @if (!$embedded) id="home-booking" @endif aria-label="Book or enquire about a journey" data-t4-journey-desk>
    <div class="t4-booking-panel__card">
        <header>
            <span class="t4-kicker">Book Your Ride</span>
            {{-- <strong>Book Your Ride</strong> --}}
        </header>
        @include('components.booking-form', ['search' => $search ?? null])
        @if (!$embedded && !empty($settings['hero_booking_note']))
            <p class="t4-booking-panel__note">{{ $settings['hero_booking_note'] }}</p>
        @endif
    </div>
</section>