<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans;
            font-size: 10px;
            color: #20242a
        }

        h1 {
            font-size: 18px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            padding: 6px;
            border: 1px solid #ddd;
            text-align: left
        }

        th {
            background: #f2f4f7
        }
    </style>
</head>

<body>
    <h1>Corporate Management Report</h1>
    <p>Period: {{ $payload['period']['from'] ?: 'All time' }} to {{ $payload['period']['to'] ?: 'Present' }}</p>
    <p>Bookings: {{ $payload['operational']['booking_count'] }} | Trips: {{ $payload['operational']['trip_count'] }} |
        Estimated booking value: {{ number_format($payload['operational']['estimated_booking_value'], 2) }} | Finalized
        charges: {{ number_format($payload['operational']['finalized_charges'], 2) }}</p>
    <p>Contractual distance: {{ number_format($payload['distance']['contractual_km'], 3) }} km (pricing) | Operational
        distance: {{ number_format($payload['distance']['operational_km'], 3) }} km (telemetry evidence)</p>
    <table>
        <thead>
            <tr>
                <th>Dimension</th>
                <th>Label</th>
                <th>Bookings</th>
                <th>Trips</th>
                <th>Estimated</th>
                <th>Finalized</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
