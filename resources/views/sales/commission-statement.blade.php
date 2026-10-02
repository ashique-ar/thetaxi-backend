<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#222}h1{font-size:18px;margin:0 0 8px}.meta{margin-bottom:14px}.meta div{margin:2px 0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:5px;text-align:left}.number{text-align:right}.excluded{color:#777}.totals{margin-top:14px;width:55%;margin-left:auto}.watermark{position:fixed;top:42%;left:16%;font-size:48px;color:#e5e5e5;transform:rotate(-25deg);z-index:-1}
</style></head><body>
<div class="watermark">{{ strtoupper($statement->status) }}</div>
<h1>Sales Commission Statement</h1>
<div class="meta">
<div><strong>Statement:</strong> {{ $statement->statement_number }} (version {{ $statement->version }})</div>
<div><strong>Period:</strong> {{ $statement->period_start->toDateString() }} to {{ $statement->period_end->toDateString() }}</div>
<div><strong>Cutoff:</strong> {{ $statement->cutoff_at->timezone($statement->timezone)->toIso8601String() }} ({{ $statement->timezone }})</div>
<div><strong>Staff ID:</strong> {{ $statement->staff_id }} | <strong>Currency:</strong> {{ $statement->payout_currency }}</div>
</div>
<table><thead><tr><th>Type</th><th>Description</th><th>Gross LKR</th><th>Deduction LKR</th><th>Net LKR</th><th>Status</th></tr></thead><tbody>
@foreach($statement->lines as $line)
<tr class="{{ $line->line_status === 'excluded' ? 'excluded' : '' }}"><td>{{ $line->line_type }}</td><td>{{ $line->description }} @if($line->hold_code)({{ $line->hold_code }})@endif</td><td class="number">{{ number_format((float)$line->gross_lkr,2) }}</td><td class="number">{{ number_format((float)$line->deduction_lkr,2) }}</td><td class="number">{{ number_format((float)$line->net_lkr,2) }}</td><td>{{ $line->line_status }}</td></tr>
@endforeach
</tbody></table>
<table class="totals"><tbody>
<tr><th>Opening carry-forward</th><td class="number">{{ number_format((float)$statement->opening_carry_forward_lkr,2) }}</td></tr>
<tr><th>Gross earnings</th><td class="number">{{ number_format((float)$statement->gross_earnings_lkr,2) }}</td></tr>
<tr><th>Adjustment credits</th><td class="number">{{ number_format((float)$statement->adjustment_credits_lkr,2) }}</td></tr>
<tr><th>Recoveries</th><td class="number">{{ number_format((float)$statement->recovery_deductions_lkr,2) }}</td></tr>
<tr><th>Other deductions</th><td class="number">{{ number_format((float)$statement->other_deductions_lkr,2) }}</td></tr>
<tr><th>Contested hold</th><td class="number">{{ number_format((float)$statement->contested_hold_lkr,2) }}</td></tr>
<tr><th>Net payable</th><td class="number">{{ number_format((float)$statement->net_payable_lkr,2) }}</td></tr>
<tr><th>Paid</th><td class="number">{{ number_format((float)$statement->paid_lkr,2) }}</td></tr>
<tr><th>Closing carry-forward</th><td class="number">{{ number_format((float)$statement->closing_carry_forward_lkr,2) }}</td></tr>
</tbody></table>
</body></html>
