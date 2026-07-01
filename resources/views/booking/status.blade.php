@extends('layouts.app')

@section('title', 'Booking Status')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h2 mb-2">Check your booking status</h1>
                    <p class="text-muted mb-4">Enter your booking reference and the email address or phone number used for the booking.</p>

                    <form method="POST" action="{{ route('booking.status.lookup') }}" class="row g-3">
                        @csrf
                        <div class="col-md-6">
                            <label for="booking_reference" class="form-label">Booking reference</label>
                            <input id="booking_reference" name="booking_reference" class="form-control @error('booking_reference') is-invalid @enderror" value="{{ old('booking_reference') }}" required autocomplete="off">
                            @error('booking_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="contact" class="form-label">Email or phone number</label>
                            <input id="contact" name="contact" class="form-control @error('contact') is-invalid @enderror" value="{{ old('contact') }}" required autocomplete="off">
                            @error('contact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">Check status</button>
                        </div>
                    </form>

                    @isset($bookingStatus)
                        <hr class="my-4">
                        <div aria-live="polite">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                                <div>
                                    <div class="text-muted small">Booking reference</div>
                                    <strong>{{ $bookingStatus['reference'] }}</strong>
                                </div>
                                <span class="badge bg-primary fs-6 align-self-start">{{ $bookingStatus['label'] }}</span>
                            </div>
                            <p class="lead mb-3">{{ $bookingStatus['message'] }}</p>
                            <div class="progress mb-3" role="progressbar" aria-label="Booking progress" aria-valuemin="0" aria-valuemax="5" aria-valuenow="{{ $bookingStatus['stage'] }}">
                                <div class="progress-bar" style="width: {{ ($bookingStatus['stage'] / 5) * 100 }}%"></div>
                            </div>
                            <div class="row g-3 small">
                                <div class="col-sm-6"><span class="text-muted">Payment:</span> {{ $bookingStatus['payment_status'] }}</div>
                                <div class="col-sm-6"><span class="text-muted">Last updated:</span> {{ optional($bookingStatus['updated_at'])->format('d M Y, H:i') }}</div>
                            </div>
                        </div>
                    @endisset
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
