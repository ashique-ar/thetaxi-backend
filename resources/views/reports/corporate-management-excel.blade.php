<html><head><meta charset="utf-8"></head><body>
@if(isset($payload['financial_period']['summary']))
<table border="1"><caption>Billing period totals</caption><tbody>
@foreach($payload['financial_period']['summary'] as $key=>$value)<tr><th>{{ str_replace('_', ' ', $key) }}</th><td>{{ $value }}</td></tr>@endforeach
</tbody></table><br>
@endif
<table border="1"><caption>Monthly travel activity</caption><thead><tr><th>Month</th><th>Currency</th><th>Bookings</th><th>Trips</th>@if($payload['financial_metrics_visible'])<th>Estimated value</th><th>Finalized charges</th>@endif</tr></thead><tbody>
@foreach($payload['monthly_trends'] as $month)<tr><td>{{ $month['month'] }}</td><td>{{ $month['currency'] ?: 'Currency unavailable' }}</td><td>{{ $month['bookings'] }}</td><td>{{ $month['trips'] }}</td>@if($payload['financial_metrics_visible'])<td>{{ $month['estimated_value'] }}</td><td>{{ $month['finalized_charges'] }}</td>@endif</tr>@endforeach
</tbody></table><br>
<table border="1"><thead><tr><th>Dimension</th><th>Label</th><th>Bookings</th><th>Trips</th>@if($payload['financial_metrics_visible'])<th>Currency</th><th>Estimated value</th><th>Finalized charges</th>@endif</tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table></body></html>
