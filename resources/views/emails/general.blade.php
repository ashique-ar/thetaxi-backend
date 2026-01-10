@extends('emails.layouts.master')

@section('title', config('app.name'))

@section('header_title', env('COMPANY_NAME', 'TheTaxi Company'))

@section('header_subtitle', 'Premium Car Rental Services')

@section('content')
    <div class="highlight-box">
        <p style="margin: 0; font-size: 15px; line-height: 1.7; color: #333;">
            {!! nl2br(e($message)) !!}
        </p>
    </div>
@endsection
