@extends('layouts.app')

@section('title', 'Payment Callback')

@section('content')
    <div class="container pt-100 mb-100">
        <div class="alert alert-warning">
            <h4>Payment Callback Received</h4>
            <p>{{ $message ?? 'We could not find your booking based on the payment gateway response.' }}</p>
            <p>Please contact <a href="{{ route('contact') }}">support</a> and provide your transaction details. We have
                logged the callback for troubleshooting.</p>
            <a href="{{ route('home') }}" class="primary-btn1 mt-3">Back to Home</a>
        </div>
    </div>
@endsection
