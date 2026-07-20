@php
    $data = $section['data'] ?? [];
    $kicker = $data['kicker'] ?? 'Get In Touch';
    $heading = $data['heading'] ?? 'Contact Information';
    $description = $data['description'] ?? '';
    $contacts = $data['contacts'] ?? [];
    $officeHours = $data['office_hours'] ?? null;
    $emergencyHotline = $data['emergency_hotline'] ?? null;
    $showLocation = $data['show_location'] ?? false;
    $location = $data['location'] ?? null;
@endphp

<div class="contact-info-section mb-100">
    <div class="container">
        <div class="row mb-60">
            <div class="col-lg-12">
                <div class="section-title1 text-center">
                    @if ($kicker)
                        <span>{{ $kicker }}</span>
                    @endif
                    <h2>{{ $heading }}</h2>
                    @if ($description)
                        <p class="mt-3">{{ $description }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="row g-4">
            {{-- Contact Persons --}}
            @if (!empty($contacts))
                @foreach ($contacts as $contact)
                    <div class="col-lg-4 col-md-6">
                        <div class="contact-card"
                            style="background: white; border-radius: 10px; padding: 2rem; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1); height: 100%; transition: transform 0.3s ease, box-shadow 0.3s ease;">
                            @if (!empty($contact['name']))
                                <div class="d-flex align-items-center mb-3">
                                    <div class="icon-box"
                                        style="width: 50px; height: 50px; background: var(--primary-color1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                                        <i class="bi bi-person" style="color: white; font-size: 24px;"></i>
                                    </div>
                                    <div>
                                        <h4 style="margin: 0; font-size: 18px; font-weight: 600; color: #2c2c2c;">
                                            {{ $contact['name'] }}</h4>
                                        @if (!empty($contact['title']))
                                            <p style="margin: 0; font-size: 14px; color: #666;">{{ $contact['title'] }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div class="contact-details" style="margin-top: 20px;">
                                @if (!empty($contact['email']))
                                    <div class="d-flex align-items-start mb-3">
                                        <i class="bi bi-envelope"
                                            style="color: var(--primary-color1); font-size: 18px; margin-right: 12px; margin-top: 2px;"></i>
                                        <div>
                                            <p
                                                style="margin: 0; font-size: 13px; color: #999; text-transform: uppercase; letter-spacing: 0.5px;">
                                                Email</p>
                                            <a href="mailto:{{ $contact['email'] }}"
                                                style="font-size: 15px; color: #2c2c2c; text-decoration: none; word-break: break-all;">{{ $contact['email'] }}</a>
                                        </div>
                                    </div>
                                @endif

                                @if (!empty($contact['phone']))
                                    <div class="d-flex align-items-start mb-3">
                                        <i class="bi bi-telephone"
                                            style="color: var(--primary-color1); font-size: 18px; margin-right: 12px; margin-top: 2px;"></i>
                                        <div>
                                            <p
                                                style="margin: 0; font-size: 13px; color: #999; text-transform: uppercase; letter-spacing: 0.5px;">
                                                Phone</p>
                                            <a href="tel:{{ $contact['phone'] }}"
                                                style="font-size: 15px; color: #2c2c2c; text-decoration: none;">{{ $contact['phone'] }}</a>
                                        </div>
                                    </div>
                                @endif

                                @if (!empty($contact['availability']))
                                    <div class="d-flex align-items-start">
                                        <i class="bi bi-clock"
                                            style="color: var(--primary-color1); font-size: 18px; margin-right: 12px; margin-top: 2px;"></i>
                                        <div>
                                            <p
                                                style="margin: 0; font-size: 13px; color: #999; text-transform: uppercase; letter-spacing: 0.5px;">
                                                Availability</p>
                                            <p style="margin: 0; font-size: 15px; color: #2c2c2c;">
                                                {{ $contact['availability'] }}</p>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif

            {{-- Office Hours --}}
            @if ($officeHours)
                <div class="col-lg-4 col-md-6">
                    <div class="contact-card"
                        style="background: white; border-radius: 10px; padding: 2rem; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1); height: 100%; transition: transform 0.3s ease, box-shadow 0.3s ease;">
                        <div class="d-flex align-items-center mb-3">
                            <div class="icon-box"
                                style="width: 50px; height: 50px; background: var(--primary-color1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                                <i class="bi bi-clock-history" style="color: white; font-size: 24px;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; font-size: 18px; font-weight: 600; color: #2c2c2c;">Office Hours
                                </h4>
                            </div>
                        </div>

                        <div class="office-hours-list" style="margin-top: 20px;">
                            @if (!empty($officeHours['weekdays']))
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-3"
                                    style="border-bottom: 1px solid #eee;">
                                    <span style="font-size: 15px; color: #666; font-weight: 500;">Monday - Friday</span>
                                    <span
                                        style="font-size: 15px; color: #2c2c2c; font-weight: 600;">{{ $officeHours['weekdays'] }}</span>
                                </div>
                            @endif

                            @if (!empty($officeHours['saturday']))
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-3"
                                    style="border-bottom: 1px solid #eee;">
                                    <span style="font-size: 15px; color: #666; font-weight: 500;">Saturday</span>
                                    <span
                                        style="font-size: 15px; color: #2c2c2c; font-weight: 600;">{{ $officeHours['saturday'] }}</span>
                                </div>
                            @endif

                            @if (!empty($officeHours['sunday']))
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span style="font-size: 15px; color: #666; font-weight: 500;">Sunday</span>
                                    <span
                                        style="font-size: 15px; color: #2c2c2c; font-weight: 600;">{{ $officeHours['sunday'] }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Emergency Hotline --}}
            @if ($emergencyHotline)
                <div class="col-lg-4 col-md-6">
                    <div class="contact-card"
                        style="background: linear-gradient(135deg, var(--primary-color1) 0%, #d32f2f 100%); border-radius: 10px; padding: 2rem; box-shadow: 0 5px 20px rgba(201, 28, 35, 0.3); height: 100%; transition: transform 0.3s ease, box-shadow 0.3s ease;">
                        <div class="d-flex align-items-center mb-3">
                            <div class="icon-box"
                                style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                                <i class="bi bi-telephone-forward" style="color: white; font-size: 24px;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; font-size: 18px; font-weight: 600; color: white;">24/7 Emergency
                                </h4>
                                <p style="margin: 0; font-size: 13px; color: rgba(255, 255, 255, 0.9);">Always Available
                                </p>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <a href="tel:{{ $emergencyHotline }}"
                                style="display: block; text-align: center; font-size: 28px; font-weight: 700; color: white; text-decoration: none; padding: 20px; background: rgba(255, 255, 255, 0.1); border-radius: 8px; transition: background 0.3s ease;">
                                {{ $emergencyHotline }}
                            </a>
                            <p
                                style="margin-top: 15px; font-size: 14px; color: rgba(255, 255, 255, 0.9); text-align: center;">
                                For urgent assistance and emergencies</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Location Map --}}
        @if ($showLocation && $location)
            <div class="row mt-5">
                <div class="col-lg-12">
                    <div class="location-card"
                        style="background: white; border-radius: 10px; padding: 2rem; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-box"
                                style="width: 50px; height: 50px; background: var(--primary-color1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                                <i class="bi bi-geo-alt" style="color: white; font-size: 24px;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; font-size: 20px; font-weight: 600; color: #2c2c2c;">Our Location
                                </h4>
                            </div>
                        </div>

                        @if (!empty($location['address']))
                            <p style="font-size: 16px; color: #666; margin-bottom: 20px;">
                                <i class="bi bi-building" style="color: var(--primary-color1); margin-right: 8px;"></i>
                                {{ $location['address'] }}
                            </p>
                        @endif

                        @if (!empty($location['map_embed']))
                            <div class="map-container inquiry-location-map" style="border-radius: 8px; overflow: hidden; height: 80vh; height: 80dvh; min-height: 24rem;">
                                {!! $location['map_embed'] !!}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

<style>
    .inquiry-location-map iframe {
        width: 100% !important;
        height: 100% !important;
        min-height: 24rem;
    }

    .contact-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    }

    .contact-card a:hover {
        color: var(--primary-color1) !important;
        transition: color 0.3s ease;
    }

    .contact-card:has(.d-flex) a[href^="tel:"]:hover {
        background: rgba(255, 255, 255, 0.2);
        transition: background 0.3s ease;
    }

    .map-container iframe {
        width: 100%;
        height: 100%;
        border: 0;
    }
</style>
