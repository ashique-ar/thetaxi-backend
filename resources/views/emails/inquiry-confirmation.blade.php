@extends('emails.layouts.master')

@section('title', $typeLabel . ' - ' . config('app.name'))

@section('header_title', $typeLabel . ' Received')

@section('header_subtitle', 'We have received your inquiry')

@section('content')
    @php
        $payload = $inquiry->payload ?? [];
        $form = is_array($payload['form'] ?? null) ? $payload['form'] : [];
    @endphp

    <!-- Greeting -->
    <p class="greeting">
        Dear <strong>{{ $contactName }}</strong>,
    </p>

    <p class="intro-text">
        {{ $intro }}
    </p>

    <!-- Reference Box -->
    <div class="reference-box">
        <div class="reference-label">Reference Number</div>
        <div class="reference-number">{{ $inquiry->id }}</div>
    </div>

    <!-- Inquiry Details Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📋</span> Inquiry Details
        </h2>
        <table class="info-table">
            <tr>
                <td>Type</td>
                <td>{{ $typeLabel }}</td>
            </tr>
            @if (!empty($form['company_name']))
                <tr>
                    <td>Company</td>
                    <td>{{ $form['company_name'] }}</td>
                </tr>
            @endif
            @if (!empty($form['contact_person']))
                <tr>
                    <td>Contact Person</td>
                    <td>{{ $form['contact_person'] }}</td>
                </tr>
            @endif
            @if (!empty($form['name']))
                <tr>
                    <td>Name</td>
                    <td>{{ $form['name'] }}</td>
                </tr>
            @endif
            <tr>
                <td>Email</td>
                <td>{{ $inquiry->email }}</td>
            </tr>
            <tr>
                <td>Phone</td>
                <td>{{ $inquiry->phone ?? 'N/A' }}</td>
            </tr>
            @if (!empty($form['country']))
                <tr>
                    <td>Country</td>
                    <td>{{ $form['country'] }}</td>
                </tr>
            @endif
            @if (!empty($form['pickup_location']))
                <tr>
                    <td>Pickup Location</td>
                    <td>{{ $form['pickup_location'] }}</td>
                </tr>
            @endif
            @if (!empty($form['dropoff_location']))
                <tr>
                    <td>Dropoff Location</td>
                    <td>{{ $form['dropoff_location'] }}</td>
                </tr>
            @endif
            @if (!empty($form['travel_date']))
                <tr>
                    <td>Travel Date</td>
                    <td>{{ $form['travel_date'] }}</td>
                </tr>
            @endif
            @if (!empty($form['travel_time']))
                <tr>
                    <td>Travel Time</td>
                    <td>{{ $form['travel_time'] }}</td>
                </tr>
            @endif
            @if (!empty($form['passengers']))
                <tr>
                    <td>Passengers</td>
                    <td>{{ $form['passengers'] }}</td>
                </tr>
            @endif
            @if (!empty($form['requirements']))
                <tr>
                    <td>Requirements</td>
                    <td>{{ $form['requirements'] }}</td>
                </tr>
            @endif
            @if (!empty($form['message']))
                <tr>
                    <td>Message</td>
                    <td>{{ $form['message'] }}</td>
                </tr>
            @endif
            @if (!empty($inquiry->message) && empty($form['message']) && empty($form['requirements']))
                <tr>
                    <td>Message</td>
                    <td>{{ $inquiry->message }}</td>
                </tr>
            @endif
            <tr>
                <td>Submitted</td>
                <td>{{ $inquiry->created_at?->format('F j, Y \a\t g:i A') }}</td>
            </tr>
        </table>
    </div>

    <div class="divider"></div>

    <!-- What Happens Next Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📌</span> What Happens Next
        </h2>
        <div class="highlight-box info">
            <p style="margin: 0;">Our team will review your inquiry and contact you using the details provided. If you need
                to add more information, reply to this email or contact us directly.</p>
        </div>
    </div>

    <!-- Need Assistance Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📞</span> Need Assistance?
        </h2>
        <table class="info-table">
            <tr>
                <td>📧 Email</td>
                <td><a href="mailto:{{ $supportEmail }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportEmail }}</a></td>
            </tr>
            <tr>
                <td>📞 Phone</td>
                <td><a href="tel:{{ $supportPhone }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportPhone }}</a></td>
            </tr>
            <tr>
                <td>🌐 Website</td>
                <td><a href="{{ config('app.url') }}"
                        style="color: #BF2629; text-decoration: none;">{{ config('app.url') }}</a></td>
            </tr>
        </table>
    </div>

    <!-- CTA Button -->
    <div class="btn-container">
        <a href="{{ route('home') }}" class="btn">Visit {{ env('COMPANY_NAME', 'Casons Rent A Car') }}</a>
    </div>
@endsection
