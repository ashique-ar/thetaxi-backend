@extends('layouts.app')

@section('title', 'Tour request received')

@section('content')
<main class="container py-5" style="max-width: 52rem">
    <section class="p-4 p-md-5 border rounded-4 bg-white shadow-sm">
        <div class="text-success mb-3" aria-hidden="true"><i class="bi bi-check-circle-fill fs-1"></i></div>
        @if ($booking)
            <span class="badge text-bg-info mb-3">{{ ucfirst(str_replace('_', ' ', $booking->status)) }}</span>
            <h1 class="h2">Your tour request has been received</h1>
            <p class="lead">Reference <strong>{{ $booking->public_reference }}</strong></p>
            <p>Our team will check vehicle availability and contact you about the next steps and payment. This page confirms we received your request; it does not confirm vehicle availability or payment.</p>
        @else
            <span class="badge text-bg-info mb-3">Staff review requested</span>
            <h1 class="h2">Your Tour review request has been received</h1>
            <p class="lead">Reference <strong>{{ $inquiry->inquiry_number }}</strong></p>
            <p>Our team will review your route or vehicle availability and contact you. This request does not book or reserve a vehicle, and no payment has been taken.</p>
        @endif
        <div class="alert alert-light mb-4">
            <strong>Activity visits are route stops only.</strong> Tickets, entry fees and activity reservations are not included. Please arrange these separately.
        </div>
        <a href="{{ route('home') }}" class="btn btn-primary">Back to the website</a>
    </section>
</main>
@endsection
