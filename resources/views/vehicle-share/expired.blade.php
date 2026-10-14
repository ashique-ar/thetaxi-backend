@extends('layouts.app')
@section('seo_exact_title', 'true')
@section('title', 'Vehicle Preview Link Expired')

@push('meta')
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
@endpush

@push('styles')
<style>
    .vehicle-preview-page {
        --share-primary: var(--t4-accent, var(--t3-accent, var(--primary-color1, #bf2629)));
    }
    .vehicle-preview-page .btn-theme-link,
    .vehicle-preview-page .btn-link:hover {
        color: var(--share-primary) !important;
    }
</style>
@endpush

@section('content')
<div class="vehicle-preview-page py-5 bg-light min-vh-50 d-flex align-items-center">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6 text-center">
                <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center bg-warning-subtle text-warning rounded-circle p-3" style="width: 72px; height: 72px;">
                            <i class="bi bi-clock-history fs-2"></i>
                        </div>
                    </div>
                    <h2 class="fw-bold text-dark mb-2">Preview Link Expired</h2>
                    <p class="text-muted mb-4">
                        This vehicle preview link has expired. Please contact our team to receive an updated preview link with current photos and details.
                    </p>
                    <div class="d-grid gap-2">
                        @if(!empty($whatsappNumber))
                        <a href="https://wa.me/{{ $whatsappNumber }}?text={{ urlencode('Hello! I would like to request an updated preview link for a vehicle.') }}" target="_blank" rel="noopener" class="btn btn-success btn-lg rounded-3 d-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-whatsapp"></i> Request Updated Link via WhatsApp
                        </a>
                        @endif
                        @if(!empty($companyPhone))
                        <a href="tel:{{ $companyPhone }}" class="btn btn-outline-dark rounded-3">
                            <i class="bi bi-telephone-fill me-1"></i> Call {{ $companyPhone }}
                        </a>
                        @endif
                        <a href="{{ url('/') }}" class="btn btn-link text-decoration-none text-muted">
                            <i class="bi bi-house me-1"></i> Go to Homepage
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
