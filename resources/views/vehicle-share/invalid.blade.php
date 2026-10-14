@extends('layouts.app')
@section('seo_exact_title', 'true')
@section('title', 'Vehicle Preview Link Invalid')

@push('meta')
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
@endpush

@section('content')
<div class="py-5 bg-light min-vh-50 d-flex align-items-center">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6 text-center">
                <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center bg-danger-subtle text-danger rounded-circle p-3" style="width: 72px; height: 72px;">
                            <i class="bi bi-exclamation-triangle fs-2"></i>
                        </div>
                    </div>
                    <h2 class="fw-bold text-dark mb-2">Invalid Preview Link</h2>
                    <p class="text-muted mb-4">
                        We could not find the vehicle associated with this preview link. Please check that you copied the complete link or contact our team.
                    </p>
                    <div class="d-grid gap-2">
                        <a href="{{ url('/') }}" class="btn btn-primary btn-lg rounded-3">
                            <i class="bi bi-house me-1"></i> Go to Homepage
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
