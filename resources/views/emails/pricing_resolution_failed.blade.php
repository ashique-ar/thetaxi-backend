<p>Hi,</p>
<p>A driver/booking completion finished successfully, but final pricing could not be resolved automatically.
    Active calculation definitions exist for the service type, but none of them matched this booking, so no invoice
    was generated. The booking has been flagged for manual review and will be retried automatically once the
    underlying configuration is corrected.</p>
<ul>
    <li><strong>Booking:</strong> {{ $details['booking_number'] ?? $details['booking_id'] ?? 'n/a' }}</li>
    <li><strong>Booking item:</strong> {{ $details['booking_item_id'] ?? 'n/a' }}</li>
    <li><strong>Service type:</strong> {{ $details['service_type_id'] ?? 'n/a' }}</li>
    <li><strong>Vehicle group:</strong> {{ $details['vehicle_group_id'] ?? 'n/a' }}</li>
    <li><strong>Reason:</strong> {{ $details['reason'] ?? 'n/a' }}</li>
    <li><strong>Detected at:</strong> {{ $details['detected_at'] ?? now() }}</li>
</ul>

@if (!empty($details['candidate_failures']))
    <p><strong>Candidate definition failures:</strong></p>
    <ul>
        @foreach ($details['candidate_failures'] as $failure)
            <li>
                Definition {{ $failure['definition_id'] ?? 'n/a' }}: {{ $failure['reason'] ?? 'unknown' }}
                @if (!empty($failure['message']))
                    — {{ $failure['message'] }}
                @endif
                @if (!empty($failure['missing_variables']))
                    (missing: {{ implode(', ', $failure['missing_variables']) }})
                @endif
            </li>
        @endforeach
    </ul>
@endif

<p>Check Pricing Management for this service type to find the missing rate row, contradictory condition, or formula
    issue. The <code>bookings:retry-final-pricing</code> scheduled job will pick this booking up automatically once
    it's fixed.</p>

<p>Regards,<br>{{ $settings['brand_name'] ?? $settings['site_name'] ?? 'Company' }} system</p>
