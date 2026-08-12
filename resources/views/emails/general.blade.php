@extends('emails.layouts.master')

@section('title', config('app.name'))

@section('header_title', ($settings['company_name'] ?? $settings['brand_name'] ?? $settings['site_name'] ?? 'Company'))

@section('header_subtitle', 'Premium Car Rental Services')

@section('content')
    @php
        // Keep rolling deployments and already-running queue workers compatible
        // with the former view contract while new mailables use emailBody.
        $generalEmailBody = $emailBody
            ?? (isset($message) && is_string($message) ? $message : '');
    @endphp
    <div class="highlight-box">
        <p style="margin: 0; font-size: 15px; line-height: 1.7; color: #333;">
            {!! nl2br(e($generalEmailBody)) !!}
        </p>
    </div>
@endsection
