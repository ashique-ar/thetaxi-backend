<html><head><meta charset="utf-8"></head><body>
@if(isset($payload['financial_period']['summary']))
<table border="1"><caption>Financial totals — selected travel period</caption><tbody>
@foreach($payload['financial_period']['summary'] as $key=>$value)<tr><th>{{ str_replace('_', ' ', $key) }}</th><td>{{ $value }}</td></tr>@endforeach
</tbody></table><br>
@endif
<table border="1"><thead><tr><th>Dimension</th><th>Label</th><th>Bookings</th><th>Trips</th><th>Estimated value</th><th>Finalized charges</th></tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table></body></html>
