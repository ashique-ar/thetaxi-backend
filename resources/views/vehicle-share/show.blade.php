@extends('layouts.app')
@section('seo_exact_title', 'true')
@section('title', ($vehicle->title ?? 'Vehicle') . ' - Vehicle Preview')

@push('meta')
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
@endpush

@push('styles')
<style>
    .vehicle-preview-page {
        --share-primary: var(--t4-accent, var(--t3-accent, var(--primary-color1, #bf2629)));
    }
    .vehicle-preview-page .text-primary {
        color: var(--share-primary) !important;
    }
    .vehicle-preview-page .bg-primary {
        background-color: var(--share-primary) !important;
        color: #ffffff !important;
    }
    .vehicle-preview-page .border-primary,
    .vehicle-preview-page .thumbnail-btn.active-thumbnail {
        border-color: var(--share-primary) !important;
    }
    .vehicle-preview-page .bg-primary-subtle {
        background-color: color-mix(in srgb, var(--share-primary) 12%, transparent) !important;
    }
    .vehicle-preview-page .border-primary-subtle {
        border-color: color-mix(in srgb, var(--share-primary) 28%, transparent) !important;
    }
    .vehicle-preview-page .btn-primary {
        background-color: var(--share-primary) !important;
        border-color: var(--share-primary) !important;
        color: #ffffff !important;
    }
    .vehicle-preview-page .btn-primary:hover {
        filter: brightness(0.92);
        color: #ffffff !important;
    }
</style>
@endpush

@section('content')
@php
    $actualImages = [];
    if (!empty($vehicle->actual_vehicle_images) && is_array($vehicle->actual_vehicle_images)) {
        foreach ($vehicle->actual_vehicle_images as $img) {
            $path = is_array($img) ? ($img['path'] ?? ($img['url'] ?? null)) : (is_string($img) ? $img : null);
            if ($path) {
                $actualImages[] = [
                    'url' => s3_asset($path),
                    'name' => is_array($img) ? ($img['name'] ?? 'Actual Vehicle Photo') : 'Actual Vehicle Photo',
                    'is_primary' => is_array($img) ? !empty($img['is_primary']) : false,
                ];
            }
        }
    }

    $thumbnailUrl = null;
    if ($vehicle->thumbnail) {
        $thumbnailPath = is_array($vehicle->thumbnail) ? ($vehicle->thumbnail['path'] ?? null) : $vehicle->thumbnail;
        if ($thumbnailPath) {
            $thumbnailUrl = s3_asset($thumbnailPath);
        }
    }

    // Combine thumbnail and actual images, ensuring no duplicates
    $gallery = [];
    if ($thumbnailUrl) {
        $gallery[] = [
            'url' => $thumbnailUrl,
            'name' => 'Primary Photo',
            'is_primary' => true,
        ];
    }
    foreach ($actualImages as $act) {
        if ($act['url'] !== $thumbnailUrl) {
            $gallery[] = $act;
        }
    }

    $primaryImage = count($gallery) > 0 ? $gallery[0]['url'] : asset('assets/img/default-vehicle.jpg');
    $categoryName = $vehicle->group?->category?->name ?? 'Vehicle';
    $makeName = $vehicle->make?->name ?? ($vehicle->group?->make?->name ?? '');
    $modelName = $vehicle->model?->name ?? ($vehicle->group?->model?->name ?? '');
    $transmissionName = $vehicle->group?->transmission?->name ?? 'Automatic';
    $fuelTypeName = $vehicle->group?->fuelType?->name ?? 'Petrol';
    $seats = $vehicle->seats ?? ($vehicle->group?->seats ?? 4);
    $bags = $vehicle->bags ?? ($vehicle->group?->bags ?? 2);
    $doors = $vehicle->no_od_doors ?? ($vehicle->group?->no_of_doors ?? 4);
    $color = $vehicle->color ?? '';
    $year = $vehicle->year ?? ($vehicle->model_year ?? '');

    $currentUrl = request()->fullUrl();
    $waText = urlencode("Hello! I am inquiring about the vehicle: " . ($vehicle->title ?? 'Vehicle') . " (" . $currentUrl . ")");
@endphp

<div class="vehicle-preview-page py-5 bg-light">
    <div class="container">
        
        <!-- Trust & Preview Header Banner -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden">
            <div class="card-body p-4 p-md-5">
                <div class="row align-items-center g-3">
                    <div class="col-lg-8">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-semibold">
                                <i class="bi bi-patch-check-fill me-1"></i> Verified Vehicle
                            </span>
                            <span class="badge bg-secondary-subtle text-secondary px-3 py-2 rounded-pill">
                                {{ $categoryName }}
                            </span>
                            @if($year)
                            <span class="badge bg-dark-subtle text-dark px-3 py-2 rounded-pill">
                                Year: {{ $year }}
                            </span>
                            @endif
                        </div>
                        <h1 class="display-6 fw-bold text-dark mb-2">{{ $vehicle->title }}</h1>
                        @if($vehicle->tagline)
                        <p class="lead text-muted mb-0 fs-6">{{ $vehicle->tagline }}</p>
                        @elseif($makeName || $modelName)
                        <p class="text-muted mb-0">{{ trim($makeName . ' ' . $modelName) }} &bull; Maintained to Premier Standards</p>
                        @endif
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <div class="d-inline-flex flex-column align-items-lg-end">
                            <span class="text-muted small mb-1"><i class="bi bi-clock-history me-1"></i> Vehicle Preview</span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill">
                                Valid until {{ $shareLink->expires_at->format('M d, Y') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- Left Column: Actual Vehicle Gallery -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden p-3 p-md-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="bi bi-camera me-2 text-primary"></i>Actual Vehicle Photos
                        </h5>
                        <span class="text-muted small">
                            <i class="bi bi-images me-1"></i> {{ count($gallery) }} {{ count($gallery) === 1 ? 'Photo' : 'Photos' }}
                        </span>
                    </div>

                    <!-- Main Hero Display Image -->
                    <div class="position-relative rounded-3 overflow-hidden bg-dark d-flex align-items-center justify-content-center mb-3" style="min-height: 380px; max-height: 520px;">
                        <img id="mainVehicleDisplay" src="{{ $primaryImage }}" alt="{{ $vehicle->title }}" class="img-fluid w-100 object-fit-contain rounded-3" style="max-height: 500px;">
                        <div class="position-absolute bottom-0 start-0 m-3">
                            <span class="badge bg-dark bg-opacity-75 text-white px-3 py-2 rounded-pill shadow-sm">
                                <i class="bi bi-shield-check text-success me-1"></i> Verified Vehicle
                            </span>
                        </div>
                    </div>

                    <!-- Thumbnails Strip -->
                    @if(count($gallery) > 1)
                    <div class="d-flex gap-2 overflow-auto py-2" id="galleryThumbnails">
                        @foreach($gallery as $index => $item)
                        <button type="button" 
                                onclick="switchVehicleImage('{{ $item['url'] }}', this)" 
                                class="btn p-0 rounded-3 border-2 overflow-hidden flex-shrink-0 thumbnail-btn transition {{ $index === 0 ? 'border-primary shadow-sm active-thumbnail' : 'border-transparent opacity-75' }}" 
                                style="width: 90px; height: 65px; border-style: solid;">
                            <img src="{{ $item['url'] }}" alt="{{ $item['name'] }}" class="w-100 h-100 object-fit-cover">
                        </button>
                        @endforeach
                    </div>
                    @endif
                </div>

                <!-- Vehicle Overview & Features -->
                @if($vehicle->description)
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
                    <h5 class="fw-bold text-dark mb-3">
                        <i class="bi bi-info-circle me-2 text-primary"></i>Vehicle Overview
                    </h5>
                    <div class="text-secondary lh-lg">
                        {!! nl2br(e($vehicle->description)) !!}
                    </div>
                </div>
                @endif

                <!-- Quality & Assurance Standards -->
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
                    <h5 class="fw-bold text-dark mb-3">
                        <i class="bi bi-shield-check me-2 text-primary"></i>Inspection & Quality Standards
                    </h5>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="d-flex align-items-start gap-3 p-3 rounded-3 bg-light">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                    <i class="bi bi-check-lg"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">Sanitized & Cleaned</h6>
                                    <p class="text-muted small mb-0">Fully cleaned, vacuumed, and sanitized before every hire.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex align-items-start gap-3 p-3 rounded-3 bg-light">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                    <i class="bi bi-wrench"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">Safety Inspected</h6>
                                    <p class="text-muted small mb-0">Regularly serviced and maintained by professional technicians.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex align-items-start gap-3 p-3 rounded-3 bg-light">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                    <i class="bi bi-snow"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">Climate Controlled</h6>
                                    <p class="text-muted small mb-0">Air conditioning verified for maximum passenger comfort.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex align-items-start gap-3 p-3 rounded-3 bg-light">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                    <i class="bi bi-shield-fill-check"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">24/7 Roadside Assistance</h6>
                                    <p class="text-muted small mb-0">Dedicated support team available around the clock.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column: Specs & Contact Actions -->
            <div class="col-lg-4">
                
                <!-- Vehicle Specifications -->
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
                    <h5 class="fw-bold text-dark mb-3">
                        <i class="bi bi-sliders me-2 text-primary"></i>Vehicle Specifications
                    </h5>
                    
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                            <span class="text-muted"><i class="bi bi-gear-wide-connected me-2 text-primary"></i>Transmission</span>
                            <span class="fw-semibold text-dark">{{ $transmissionName }}</span>
                        </li>
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                            <span class="text-muted"><i class="bi bi-fuel-pump me-2 text-primary"></i>Fuel Type</span>
                            <span class="fw-semibold text-dark">{{ $fuelTypeName }}</span>
                        </li>
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                            <span class="text-muted"><i class="bi bi-people me-2 text-primary"></i>Seating Capacity</span>
                            <span class="fw-semibold text-dark">{{ $seats }} Passengers</span>
                        </li>
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                            <span class="text-muted"><i class="bi bi-briefcase me-2 text-primary"></i>Luggage Capacity</span>
                            <span class="fw-semibold text-dark">{{ $bags }} Bags</span>
                        </li>
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                            <span class="text-muted"><i class="bi bi-snow me-2 text-primary"></i>Air Conditioning</span>
                            <span class="fw-semibold text-dark">{{ $vehicle->ac ? 'Air Conditioned' : 'Standard' }}</span>
                        </li>
                        @if($doors)
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                            <span class="text-muted"><i class="bi bi-door-closed me-2 text-primary"></i>Doors</span>
                            <span class="fw-semibold text-dark">{{ $doors }} Doors</span>
                        </li>
                        @endif
                        @if($color)
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                            <span class="text-muted"><i class="bi bi-palette me-2 text-primary"></i>Exterior Color</span>
                            <span class="fw-semibold text-dark">{{ ucfirst($color) }}</span>
                        </li>
                        @endif
                        @if($year)
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="bi bi-calendar me-2 text-primary"></i>Model Year</span>
                            <span class="fw-semibold text-dark">{{ $year }}</span>
                        </li>
                        @endif
                    </ul>
                </div>

                <!-- Inquire / Contact Box -->
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
                    <h5 class="fw-bold text-dark mb-2">Interested in this vehicle?</h5>
                    <p class="text-muted small mb-4">Contact our team to reserve this specific vehicle or inquire about customized packages.</p>

                    @if($whatsappNumber)
                    <a href="https://wa.me/{{ $whatsappNumber }}?text={{ $waText }}" target="_blank" rel="noopener" class="btn btn-success btn-lg w-100 rounded-3 mb-2 d-flex align-items-center justify-content-center gap-2 shadow-sm">
                        <i class="bi bi-whatsapp fs-5"></i> Chat on WhatsApp
                    </a>
                    @endif

                    @if($companyPhone)
                    <a href="tel:{{ $companyPhone }}" class="btn btn-outline-dark btn-lg w-100 rounded-3 mb-2 d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-telephone-fill"></i> Call {{ $companyPhone }}
                    </a>
                    @endif

                    @if($companyEmail)
                    <a href="mailto:{{ $companyEmail }}?subject=Inquiry for {{ urlencode($vehicle->title) }}" class="btn btn-outline-secondary w-100 rounded-3 d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-envelope"></i> Email Us
                    </a>
                    @endif

                    <div class="border-top pt-3 mt-4 text-center">
                        <p class="text-muted small mb-0">
                            <i class="bi bi-shield-lock me-1"></i> Private and secure customer preview link.
                        </p>
                    </div>
                </div>

            </div>

        </div>

    </div>
</div>

<script>
function switchVehicleImage(url, element) {
    const mainImg = document.getElementById('mainVehicleDisplay');
    if (mainImg) {
        mainImg.style.opacity = '0.5';
        setTimeout(() => {
            mainImg.src = url;
            mainImg.style.opacity = '1';
        }, 120);
    }
    
    // Update active thumbnail border
    const container = document.getElementById('galleryThumbnails');
    if (container) {
        const buttons = container.querySelectorAll('button');
        buttons.forEach(btn => {
            btn.classList.remove('border-primary', 'shadow-sm', 'active-thumbnail');
            btn.classList.add('border-transparent', 'opacity-75');
        });
        if (element) {
            element.classList.remove('border-transparent', 'opacity-75');
            element.classList.add('border-primary', 'shadow-sm', 'active-thumbnail');
        }
    }
}
</script>
@endsection
