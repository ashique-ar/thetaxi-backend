<?php

namespace App\Services;

use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateBillingTerm;
use App\Models\Finance\FinancialAccountSettlement;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\FinancialAuditEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class CorporateMonthlyBillingService
{
    public function __construct(private readonly FinancialAccountSettlementService $settlements)
    {
    }

    public function terms(string $corporateId)
    {
        return CorporateBillingTerm::withInactive()->where('corporate_id', $corporateId)
            ->orderByDesc('effective_from')->get();
    }

    public function storeTerms(Corporate $corporate, array $data, ?string $userId): CorporateBillingTerm
    {
        $overlap = CorporateBillingTerm::withInactive()
            ->where('corporate_id', $corporate->id)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
            ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['effective_from' => ['Billing-term effective periods cannot overlap. End the existing version first.']]);
        }

        return CorporateBillingTerm::create([
            ...$data,
            'corporate_id' => $corporate->id,
            'billing_name' => $data['billing_name'] ?? $corporate->name,
            'billing_address' => $data['billing_address'] ?? $corporate->billing_address,
            'created_user_id' => $userId,
            'updated_user_id' => $userId,
        ]);
    }

    public function endTerms(Corporate $corporate, CorporateBillingTerm $term, string $effectiveTo, ?string $userId): CorporateBillingTerm
    {
        abort_unless((string) $term->corporate_id === (string) $corporate->id, 404);
        if (Carbon::parse($effectiveTo)->lt($term->effective_from)) {
            throw ValidationException::withMessages(['effective_to' => ['The end date cannot precede the effective start date.']]);
        }
        $term->update(['effective_to' => $effectiveTo, 'updated_user_id' => $userId]);
        return $term->fresh();
    }

    public function preview(string $corporateId, string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();
        $terms = $this->effectiveTerms($corporateId, $end);
        $alreadyBilledIds = FinancialSettlementItem::query()
            ->whereHas('settlement', fn($query) => $query->where('owner_type', 'corporate')->where('owner_id', $corporateId)->where('status', '!=', 'void'))
            ->get(['booking_item_ids'])->flatMap(fn($item) => $item->booking_item_ids ?? [])->map(fn($id) => (string) $id)->unique();

        $periodItems = BookingItem::query()
            ->whereHas('booking', fn($query) => $query->where('is_corporate_booking', true)->where('corporate_account_id', $corporateId))
            ->whereDate('from_date', '>=', $start->toDateString())
            ->whereDate('to_date', '<=', $end->toDateString())
            ->with(['booking:id,booking_number,corporate_account_id,status,currency', 'serviceType:id,name'])
            ->orderBy('from_date')->get();

        $eligible = $periodItems->filter(fn($item) => $item->status === 'completed' && $item->final_priced_at && !$alreadyBilledIds->contains((string) $item->id));
        $excluded = $periodItems->reject(fn($item) => $eligible->contains('id', $item->id))->map(fn($item) => [
            'booking_id' => $item->booking_id,
            'booking_item_id' => $item->id,
            'booking_number' => $item->booking?->booking_number,
            'reason' => $alreadyBilledIds->contains((string) $item->id) ? 'already_billed' : ($item->status !== 'completed' ? 'not_completed' : 'final_pricing_pending'),
        ])->values();

        $groups = $eligible->groupBy('booking_id')->map(function ($items) {
            $booking = $items->first()->booking;
            return [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'currency' => $booking->currency,
                'charge_amount' => round((float) $items->sum('total_price'), 2),
                'items' => $items->map(fn($item) => [
                    'booking_item_id' => $item->id,
                    'service_type' => $item->serviceType?->name,
                    'from_date' => optional($item->from_date)->toDateString(),
                    'to_date' => optional($item->to_date)->toDateString(),
                    'status' => $item->status,
                    'final_priced_at' => $item->final_priced_at?->toIso8601String(),
                    'total_price' => (float) $item->total_price,
                    'currency' => $item->currency,
                    'pricing_snapshot_hash' => hash('sha256', json_encode($item->pricing_breakdown, JSON_UNESCAPED_SLASHES)),
                ])->values()->all(),
            ];
        })->values();

        $openingBalance = (float) FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')->where('owner_id', $corporateId)->where('status', '!=', 'void')
            ->whereDate('period_end', '<', $start->toDateString())->sum('outstanding_total');

        return [
            'corporate_id' => $corporateId,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'generation_key' => $this->generationKey($corporateId, $start, $end),
            'terms' => $this->termsSnapshot($terms),
            'opening_balance' => round($openingBalance, 2),
            'booking_count' => $groups->count(),
            'trip_count' => $eligible->count(),
            'charges_total' => round((float) $groups->sum('charge_amount'), 2),
            'included' => $groups,
            'excluded' => $excluded,
        ];
    }

    public function generate(string $corporateId, string $periodStart, string $periodEnd, ?string $userId): FinancialAccountSettlement
    {
        $generationKey = $this->generationKey($corporateId, Carbon::parse($periodStart)->startOfDay(), Carbon::parse($periodEnd)->endOfDay());
        $existing = FinancialAccountSettlement::where('generation_key', $generationKey)->first();
        if ($existing) {
            return $existing->load(['items.booking', 'document']);
        }
        $preview = $this->preview($corporateId, $periodStart, $periodEnd);
        if ($preview['included']->isEmpty()) {
            throw ValidationException::withMessages(['period_start' => ['No completed, final-priced, unbilled trips are available for this period.']]);
        }

        return DB::transaction(function () use ($preview, $corporateId, $userId) {
            Corporate::query()->whereKey($corporateId)->lockForUpdate()->firstOrFail();
            $concurrent = FinancialAccountSettlement::where('generation_key', $preview['generation_key'])->first();
            if ($concurrent) {
                return $concurrent->load(['items.booking', 'document']);
            }
            $terms = $preview['terms'];
            $dueDate = Carbon::parse($preview['period_end'])->addDays((int) $terms['due_days']);
            $settlement = FinancialAccountSettlement::create([
                'settlement_number' => 'CB-' . Carbon::parse($preview['period_end'])->format('Ym') . '-' . strtoupper(substr(hash('sha256', $preview['generation_key']), 0, 8)),
                'owner_type' => 'corporate', 'owner_id' => $corporateId,
                'billing_terms_id' => $terms['id'], 'billing_cycle' => $terms['billing_cycle'],
                'generation_key' => $preview['generation_key'], 'billing_terms_snapshot' => $terms,
                'period_start' => $preview['period_start'], 'period_end' => $preview['period_end'], 'due_date' => $dueDate,
                'status' => 'draft', 'created_user_id' => $userId, 'updated_user_id' => $userId,
            ]);

            foreach ($preview['included'] as $group) {
                $receiptNet = (float) BookingPaymentReceipt::query()->where('booking_id', $group['booking_id'])
                    ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
                    ->selectRaw('COALESCE(SUM(amount-refunded_amount),0) as total')->value('total');
                $previouslyApplied = (float) FinancialSettlementItem::query()->where('booking_id', $group['booking_id'])
                    ->whereHas('settlement', fn($query) => $query->where('status', '!=', 'void'))
                    ->selectRaw('COALESCE(SUM(paid_before_amount+allocated_amount),0) as total')->value('total');
                $paidBefore = min((float) $group['charge_amount'], max(0, $receiptNet - $previouslyApplied));
                $settlement->items()->create([
                    'booking_id' => $group['booking_id'],
                    'booking_item_ids' => collect($group['items'])->pluck('booking_item_id')->all(),
                    'source_snapshot' => $group,
                    'charge_amount' => $group['charge_amount'], 'paid_before_amount' => $paidBefore,
                    'outstanding_amount' => max(0, (float) $group['charge_amount'] - $paidBefore),
                    'status' => $paidBefore >= (float) $group['charge_amount'] ? 'paid' : ($paidBefore > 0 ? 'partial' : 'open'),
                ]);
            }
            $settlement = $this->settlements->recalculate($settlement);
            $statement = $this->statementSnapshot($settlement, (float) $preview['opening_balance']);
            $path = 'corporate-statements/' . $settlement->id . '.pdf';
            Storage::disk('local')->put($path, Pdf::loadView('finance.corporate-statement', ['settlement' => $settlement, 'statement' => $statement])->output());
            $settlement->update(['statement_snapshot' => $statement, 'statement_pdf_path' => $path, 'statement_pdf_disk' => 'local']);
            FinancialAuditEvent::create([
                'subject_type' => 'account_settlement', 'subject_id' => $settlement->id,
                'event_type' => 'corporate_monthly_billing_generated', 'from_status' => null, 'to_status' => $settlement->status,
                'amount' => $settlement->charges_total, 'performed_by' => $userId, 'occurred_at' => now(),
                'metadata' => ['generation_key' => $preview['generation_key'], 'period_start' => $preview['period_start'], 'period_end' => $preview['period_end'], 'trip_count' => $preview['trip_count']],
            ]);

            return $settlement->fresh(['items.booking', 'document']);
        });
    }

    public function issue(FinancialAccountSettlement $settlement, bool $sendDocuments = true): FinancialAccountSettlement
    {
        abort_unless($settlement->owner_type === 'corporate' && $settlement->generation_key, 422, 'This is not a generated corporate billing settlement.');
        $issued = $this->settlements->issue($settlement, ['send_invoice' => $sendDocuments]);
        if ($sendDocuments && data_get($issued->billing_terms_snapshot, 'delivery_preferences.email_statement', false)) {
            $recipients = collect(data_get($issued->billing_terms_snapshot, 'recipients', []))->filter()->values();
            if ($recipients->isEmpty()) {
                $issued->update(['statement_last_error' => 'No statement recipients are configured.']);
            } else {
                try {
                    Mail::raw('Please find attached corporate account statement ' . $issued->settlement_number . '.', function ($message) use ($issued, $recipients) {
                        $message->to($recipients->all())->subject('Corporate account statement ' . $issued->settlement_number)
                            ->attach(Storage::disk($issued->statement_pdf_disk)->path($issued->statement_pdf_path));
                    });
                    $issued->update(['statement_sent_at' => now(), 'statement_sent_to' => $recipients->all(), 'statement_last_error' => null]);
                    FinancialAuditEvent::create(['subject_type'=>'account_settlement','subject_id'=>$issued->id,'event_type'=>'corporate_statement_sent','from_status'=>$issued->status,'to_status'=>$issued->status,'metadata'=>['sent_to'=>$recipients->all()],'performed_by'=>auth()->id(),'occurred_at'=>now()]);
                } catch (\Throwable $exception) {
                    $issued->update(['statement_last_error' => $exception->getMessage()]);
                    FinancialAuditEvent::create(['subject_type'=>'account_settlement','subject_id'=>$issued->id,'event_type'=>'corporate_statement_delivery_failed','from_status'=>$issued->status,'to_status'=>$issued->status,'metadata'=>['error_class'=>get_class($exception)],'performed_by'=>auth()->id(),'occurred_at'=>now()]);
                }
            }
        }
        return $issued->fresh(['items.booking', 'document']);
    }

    private function effectiveTerms(string $corporateId, Carbon $at): CorporateBillingTerm
    {
        return CorporateBillingTerm::query()->where('corporate_id', $corporateId)
            ->whereDate('effective_from', '<=', $at->toDateString())
            ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $at->toDateString()))
            ->orderByDesc('effective_from')->firstOrFail();
    }

    private function generationKey(string $corporateId, Carbon $start, Carbon $end): string
    {
        return hash('sha256', implode('|', [$corporateId, $start->toDateString(), $end->toDateString(), 'monthly-v1']));
    }

    private function termsSnapshot(CorporateBillingTerm $terms): array
    {
        return collect($terms->only(['id','billing_cycle','cutoff_day','invoice_day','due_days','credit_limit','currency','billing_name','tax_identifier','billing_address','recipients','delivery_preferences','effective_from','effective_to']))
            ->map(fn($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)->all();
    }

    private function statementSnapshot(FinancialAccountSettlement $settlement, float $opening): array
    {
        return [
            'period_start' => $settlement->period_start->toDateString(), 'period_end' => $settlement->period_end->toDateString(),
            'opening_balance' => round($opening, 2), 'charges' => (float) $settlement->charges_total,
            'payments' => (float) $settlement->payments_total, 'refunds' => (float) $settlement->refunds_total,
            'adjustments' => (float) $settlement->adjustments_total,
            'period_closing_balance' => (float) $settlement->outstanding_total,
            'account_closing_balance' => round($opening + (float) $settlement->outstanding_total, 2),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
