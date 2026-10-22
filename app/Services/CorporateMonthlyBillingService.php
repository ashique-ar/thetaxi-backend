<?php

namespace App\Services;

use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateBillingTerm;
use App\Models\Finance\FinancialAccountSettlement;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\FinancialPaymentAllocation;
use App\Models\Finance\FinancialAuditEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Collection;
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
        return DB::transaction(function () use ($corporate, $data, $userId): CorporateBillingTerm {
            Corporate::query()->whereKey($corporate->id)->lockForUpdate()->firstOrFail();
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
        });
    }

    public function endTerms(Corporate $corporate, CorporateBillingTerm $term, string $effectiveTo, ?string $userId): CorporateBillingTerm
    {
        return DB::transaction(function () use ($corporate, $term, $effectiveTo, $userId): CorporateBillingTerm {
            Corporate::query()->whereKey($corporate->id)->lockForUpdate()->firstOrFail();
            abort_unless((string) $term->corporate_id === (string) $corporate->id, 404);
            if (Carbon::parse($effectiveTo)->lt($term->effective_from)) {
                throw ValidationException::withMessages(['effective_to' => ['The end date cannot precede the effective start date.']]);
            }
            $overlap = CorporateBillingTerm::withInactive()
                ->where('corporate_id', $corporate->id)
                ->where('is_active', true)
                ->whereKeyNot($term->id)
                ->whereDate('effective_from', '<=', $effectiveTo)
                ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $term->effective_from))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_to' => ['This date would overlap another active billing-terms version.']]);
            }
            $term->update(['effective_to' => $effectiveTo, 'updated_user_id' => $userId]);
            return $term->fresh();
        });
    }

    public function preview(string $corporateId, string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();
        $terms = $this->effectiveTerms($corporateId, $end);
        if ($start->toDateString() < $terms->effective_from->toDateString()
            || ($terms->effective_to && $end->toDateString() > $terms->effective_to->toDateString())) {
            throw ValidationException::withMessages([
                'period_start' => ['A billing period must fall within one effective billing-terms version. Split periods at terms changes.'],
            ]);
        }
        $alreadyBilledIds = FinancialSettlementItem::query()
            ->whereHas('settlement', fn($query) => $query->where('owner_type', 'corporate')->where('owner_id', $corporateId)->where('status', '!=', 'void'))
            ->get(['booking_item_ids'])->flatMap(fn($item) => $item->booking_item_ids ?? [])->map(fn($id) => (string) $id)->unique();

        $periodItems = BookingItem::query()
            ->whereHas('booking', fn($query) => $query->where('is_corporate_booking', true)->where('corporate_account_id', $corporateId))
            ->whereBetween('from_date', [$start, $end])
            ->with(['booking:id,booking_number,corporate_account_id,status,currency,payment_collection_method', 'serviceType:id,name'])
            ->orderBy('from_date')->get();

        $eligible = $periodItems->filter(
            fn($item) =>
                $item->booking?->payment_collection_method === 'monthly_invoice'
                && $item->status === 'completed'
                && $item->final_priced_at
                && !$alreadyBilledIds->contains((string) $item->id)
        );
        $excluded = $periodItems->reject(fn($item) => $eligible->contains('id', $item->id))->map(fn($item) => [
            'booking_id' => $item->booking_id,
            'booking_item_id' => $item->id,
            'booking_number' => $item->booking?->booking_number,
            'currency' => strtoupper((string) ($item->currency ?: $item->booking?->currency ?: '')),
            'reason' => $item->booking?->payment_collection_method !== 'monthly_invoice'
                ? 'not_monthly_corporate_credit'
                : ($alreadyBilledIds->contains((string) $item->id)
                    ? 'already_billed'
                    : ($item->status !== 'completed' ? 'not_completed'
                        : (!$item->final_priced_at ? 'final_pricing_pending' : ''))),
        ])->values();

        $currency = strtoupper((string) $terms->currency);
        $currencyService = app(CurrencyService::class);
        $receiptsByBooking = BookingPaymentReceipt::query()
            ->whereIn('booking_id', $eligible->pluck('booking_id')->unique())
            ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
            ->get(['id', 'booking_id', 'amount', 'source_amount', 'source_currency', 'refunded_amount', 'allocated_amount'])
            ->groupBy('booking_id');
        $groups = $eligible->groupBy('booking_id')->map(function ($items) use ($currency, $receiptsByBooking, $currencyService) {
            $booking = $items->first()->booking;
            $bookingCurrency = strtoupper((string) $booking->currency);
            $receiptRows = $receiptsByBooking->get((string) $booking->id, collect());
            $lineItems = $items->map(function ($item) use ($currency, $currencyService) {
                $sourceCurrency = strtoupper((string) ($item->currency ?: $item->booking?->currency ?: $currency));
                $sourceAmount = $item->calculateTotalValue();
                $rate = $sourceAmount == 0.0 ? 1.0 : $this->conversionRate($sourceCurrency, $currency);

                return [
                    'booking_item_id' => $item->id,
                    'service_type' => $item->serviceType?->name,
                    'from_date' => optional($item->from_date)->toDateString(),
                    'to_date' => optional($item->to_date)->toDateString(),
                    'status' => $item->status,
                    'final_priced_at' => $item->final_priced_at?->toIso8601String(),
                    'total_price' => $sourceAmount,
                    'currency' => $sourceCurrency,
                    'invoice_amount' => $currencyService->roundAmount($sourceAmount * $rate, $currency),
                    'fx_rate_to_invoice' => $rate,
                    'fx_rate_at' => now()->toIso8601String(),
                    'fx_source' => $sourceCurrency === $currency ? 'identity' : 'portal_currency_table',
                    'pricing_snapshot_hash' => hash('sha256', json_encode($item->pricing_breakdown, JSON_UNESCAPED_SLASHES)),
                ];
            })->values();
            $paymentConversions = $receiptRows->map(function ($receipt) use ($bookingCurrency, $currency, $currencyService) {
                $sourceCurrency = strtoupper((string) ($receipt->source_currency ?: $bookingCurrency));
                $sourceAmount = (float) ($receipt->source_amount ?? $receipt->amount);
                $refundedAmount = (float) $receipt->refunded_amount;
                $allocatedAmount = (float) $receipt->allocated_amount;
                $availableSourceAmount = max(0, $sourceAmount - $refundedAmount - $allocatedAmount);
                $rate = $availableSourceAmount == 0.0 ? 1.0 : $this->conversionRate($sourceCurrency, $currency);

                return [
                    'receipt_id' => (string) $receipt->id,
                    'source_currency' => $sourceCurrency,
                    'source_amount' => $sourceAmount,
                    'refunded_amount' => $refundedAmount,
                    'allocated_source_amount' => $allocatedAmount,
                    'available_source_amount' => $availableSourceAmount,
                    'available_invoice_amount' => $currencyService->roundAmount($availableSourceAmount * $rate, $currency),
                    'fx_rate_to_invoice' => $rate,
                    'fx_rate_at' => now()->toIso8601String(),
                    'fx_source' => $sourceCurrency === $currency ? 'identity' : 'portal_currency_table',
                ];
            })->values()->all();
            $chargeAmount = $currencyService->roundAmount((float) $lineItems->sum('invoice_amount'), $currency);
            $available = (float) collect($paymentConversions)->sum('available_invoice_amount');

            return [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'currency' => $currency,
                'booking_currency' => $bookingCurrency,
                'charge_amount' => $chargeAmount,
                'paid_before_amount' => $currencyService->roundAmount(min($chargeAmount, $available), $currency),
                'payment_snapshot_hash' => $this->paymentSnapshotHash($receiptRows, $currency, $bookingCurrency),
                'payment_conversions' => $paymentConversions,
                'items' => $lineItems->all(),
            ];
        })->values();
        $paidBeforeTotal = app(CurrencyService::class)->roundAmount((float) $groups->sum('paid_before_amount'), $currency);
        $creditUsed = $terms->credit_limit !== null
            ? app(CorporateFinancialProjectionService::class)->creditUsedInCurrency($corporateId, $currency)
            : 0.0;

        $currencyService = app(CurrencyService::class);
        $invoiceCurrency = strtoupper((string) $terms->currency);
        $openingBalanceBreakdown = FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')->where('owner_id', $corporateId)->whereNotIn('status', ['void', 'draft'])
            ->where('outstanding_total', '>', 0)
            ->whereDate('period_end', '<', $start->toDateString())
            ->with('items.booking:id,currency')->get()
            ->map(function ($settlement) use ($currencyService, $invoiceCurrency) {
                $sourceCurrency = strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
                    ?: $settlement->items->first()?->booking?->currency ?: $invoiceCurrency));
                $amount = (float) $settlement->outstanding_total;
                try {
                    $rate = $sourceCurrency === $invoiceCurrency ? 1.0 : $currencyService->getExchangeRate($sourceCurrency, $invoiceCurrency);
                } catch (\RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        'currency' => ["A portal exchange rate from {$sourceCurrency} to {$invoiceCurrency} is required to carry forward an unpaid invoice."],
                    ]);
                }

                return [
                    'settlement_id' => (string) $settlement->id,
                    'invoice_number' => $settlement->invoice_number,
                    'source_currency' => $sourceCurrency,
                    'source_amount' => $amount,
                    'invoice_currency' => $invoiceCurrency,
                    'fx_rate_to_invoice' => $rate,
                    'invoice_amount' => $currencyService->roundAmount($amount * $rate, $invoiceCurrency),
                    'fx_rate_at' => now()->toIso8601String(),
                    'fx_source' => $sourceCurrency === $invoiceCurrency ? 'identity' : 'portal_currency_table',
                ];
            })->values()->all();
        $openingBalance = $currencyService->roundAmount((float) collect($openingBalanceBreakdown)->sum('invoice_amount'), $invoiceCurrency);

        return [
            'corporate_id' => $corporateId,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'generation_key' => $this->generationKey(
                $corporateId,
                $start,
                $end,
                $groups->flatMap(fn(array $group) => collect($group['items'])->map(fn(array $item) => [
                    'id' => (string) $item['booking_item_id'],
                    'currency' => $item['currency'],
                    'amount' => $item['total_price'],
                    'invoice_amount' => $item['invoice_amount'],
                    'fx_rate_to_invoice' => $item['fx_rate_to_invoice'],
                    'final_priced_at' => $item['final_priced_at'],
                    'pricing_snapshot_hash' => $item['pricing_snapshot_hash'],
                ]))->sortBy('id')->values()->all(),
                $groups->map(fn(array $group) => [
                    'booking_id' => $group['booking_id'],
                    'payment_snapshot_hash' => $group['payment_snapshot_hash'],
                    'paid_before_amount' => $group['paid_before_amount'],
                ])->all(),
                $this->termsSnapshot($terms),
                app(CurrencyService::class)->roundAmount($openingBalance, $currency),
                collect($openingBalanceBreakdown)->map(fn (array $row) => collect($row)->except('fx_rate_at')->all())->all(),
            ),
            'terms' => $this->termsSnapshot($terms),
            'opening_balance' => app(CurrencyService::class)->roundAmount($openingBalance, $currency),
            'opening_balance_breakdown' => $openingBalanceBreakdown,
            'booking_count' => $groups->count(),
            'trip_count' => $eligible->count(),
            'charges_total' => app(CurrencyService::class)->roundAmount((float) $groups->sum('charge_amount'), $currency),
            'paid_before_total' => $paidBeforeTotal,
            'projected_balance' => app(CurrencyService::class)->roundAmount($openingBalance + (float) $groups->sum('charge_amount') - $paidBeforeTotal, $currency),
            'credit_limit_exceeded' => $terms->credit_limit !== null && $creditUsed >= (float) $terms->credit_limit,
            'included' => $groups,
            'excluded' => $excluded,
        ];
    }

    public function generate(string $corporateId, string $periodStart, string $periodEnd, ?string $userId, ?string $expectedGenerationKey = null): FinancialAccountSettlement
    {
        if ($expectedGenerationKey) {
            $existing = FinancialAccountSettlement::query()
                ->where('owner_type', 'corporate')
                ->where('owner_id', $corporateId)
                ->where('generation_key', $expectedGenerationKey)
                ->whereDate('period_start', $periodStart)
                ->whereDate('period_end', $periodEnd)
                ->first();
            if ($existing) {
                return $existing->load(['items.booking', 'document']);
            }
        }

        $preview = $this->preview($corporateId, $periodStart, $periodEnd);
        if ($expectedGenerationKey && ! hash_equals($preview['generation_key'], $expectedGenerationKey)) {
            throw ValidationException::withMessages([
                'generation_key' => ['Billing data changed after preview. Run the preview again before generating.'],
            ]);
        }
        $existing = FinancialAccountSettlement::where('generation_key', $preview['generation_key'])->first();
        if ($existing) {
            return $existing->load(['items.booking', 'document']);
        }
        if ($preview['included']->isEmpty()) {
            $previous = FinancialAccountSettlement::query()->where('owner_type', 'corporate')->where('owner_id', $corporateId)
                ->whereDate('period_start', $preview['period_start'])->whereDate('period_end', $preview['period_end'])->latest()->first();
            if ($previous)
                return $previous->load(['items.booking', 'document']);
            throw ValidationException::withMessages(['period_start' => ['No completed, final-priced, unbilled trips are available for this period.']]);
        }
        return DB::transaction(function () use ($preview, $corporateId, $userId, $expectedGenerationKey) {
            Corporate::query()->whereKey($corporateId)->lockForUpdate()->firstOrFail();
            $concurrent = FinancialAccountSettlement::query()
                ->where('owner_type', 'corporate')
                ->where('owner_id', $corporateId)
                ->where('generation_key', $preview['generation_key'])
                ->first();
            if ($concurrent) {
                return $concurrent->load(['items.booking', 'document']);
            }
            $preview = $this->preview($corporateId, $preview['period_start'], $preview['period_end']);
            if ($expectedGenerationKey && ! hash_equals($preview['generation_key'], $expectedGenerationKey)) {
                throw ValidationException::withMessages([
                    'generation_key' => ['Billing data changed while waiting to generate. Run the preview again before generating.'],
                ]);
            }
            $concurrent = FinancialAccountSettlement::query()
                ->where('owner_type', 'corporate')
                ->where('owner_id', $corporateId)
                ->where('generation_key', $preview['generation_key'])
                ->first();
            if ($concurrent) {
                return $concurrent->load(['items.booking', 'document']);
            }
            $terms = $preview['terms'];
            $invoiceCurrency = strtoupper((string) $terms['currency']);
            try {
                $invoiceFxRateToLkr = $invoiceCurrency === 'LKR' ? 1.0 : app(CurrencyService::class)->getExchangeRate($invoiceCurrency, 'LKR');
            } catch (\Throwable) {
                throw ValidationException::withMessages(['currency' => ['The invoice currency has no configured portal exchange rate to LKR.']]);
            }
            $dueDate = Carbon::parse($preview['period_end'])->addDays((int) $terms['due_days']);
            $settlement = FinancialAccountSettlement::create([
                'settlement_number' => 'CB-' . Carbon::parse($preview['period_end'])->format('Ym') . '-' . strtoupper(substr(hash('sha256', $preview['generation_key']), 0, 8)),
                'owner_type' => 'corporate',
                'owner_id' => $corporateId,
                'billing_terms_id' => $terms['id'],
                'billing_cycle' => $terms['billing_cycle'],
                'generation_key' => $preview['generation_key'],
                'billing_terms_snapshot' => [
                    ...$terms,
                    'fx_policy' => 'portal_currency_table_at_generation',
                    'fx_rate_to_lkr' => $invoiceFxRateToLkr,
                    'fx_rate_at' => now()->toIso8601String(),
                    'fx_source' => $invoiceCurrency === 'LKR' ? 'identity' : 'portal_currency_table_at_invoice_generation',
                ],
                'period_start' => $preview['period_start'],
                'period_end' => $preview['period_end'],
                'due_date' => $dueDate,
                'status' => 'draft',
                'created_user_id' => $userId,
                'updated_user_id' => $userId,
            ]);

            foreach ($preview['included'] as $group) {
                $receipts = $this->paymentReceiptsForGroup($group, true);
                if (! hash_equals($group['payment_snapshot_hash'], $this->paymentSnapshotHash($receipts, $group['currency'], $group['booking_currency']))) {
                    throw ValidationException::withMessages([
                        'generation_key' => ['Payment records changed after preview. Run the preview again before generating.'],
                    ]);
                }
                $paidBefore = (float) $group['paid_before_amount'];
                $remaining = $paidBefore;
                $paymentConversions = collect($group['payment_conversions'])->map(function (array $conversion) use (&$remaining, $group) {
                    $amount = min($remaining, (float) $conversion['available_invoice_amount']);
                    $sourceAllocation = $amount <= 0 ? 0.0 : ($amount >= (float) $conversion['available_invoice_amount']
                        ? (float) $conversion['available_source_amount']
                        : min((float) $conversion['available_source_amount'], app(CurrencyService::class)->roundAmount(
                            $amount / (float) $conversion['fx_rate_to_invoice'],
                            $conversion['source_currency']
                        )));
                    $remaining = app(CurrencyService::class)->roundAmount(max(0, $remaining - $amount), $group['currency']);

                    return [
                        ...$conversion,
                        'allocated_invoice_amount' => $amount,
                        'allocated_source_amount' => $sourceAllocation,
                    ];
                })->all();
                $item = $settlement->items()->create([
                    'booking_id' => $group['booking_id'],
                    'booking_item_ids' => collect($group['items'])->pluck('booking_item_id')->all(),
                    'source_snapshot' => [...$group, 'payment_conversions' => $paymentConversions],
                    'charge_amount' => $group['charge_amount'],
                    'paid_before_amount' => $paidBefore,
                    'outstanding_amount' => max(0, (float) $group['charge_amount'] - $paidBefore),
                    'status' => $paidBefore >= (float) $group['charge_amount'] ? 'paid' : ($paidBefore > 0 ? 'partial' : 'open'),
                ]);
                $receiptsById = $receipts->keyBy(fn($receipt) => (string) $receipt->id);
                foreach ($paymentConversions as $conversion) {
                    $receipt = $receiptsById->get($conversion['receipt_id']);
                    if (!$receipt) {
                        throw ValidationException::withMessages([
                            'generation_key' => ['A payment record changed after preview. Run the preview again before generating.'],
                        ]);
                    }
                    $amount = (float) $conversion['allocated_invoice_amount'];
                    $sourceAllocation = (float) $conversion['allocated_source_amount'];
                    if ($amount <= 0)
                        continue;
                    $receipt->update([
                        'allocated_amount' => (float) $receipt->allocated_amount + $sourceAllocation,
                        'allocation_status' => $sourceAllocation >= (float) $conversion['available_source_amount'] ? 'allocated' : 'partially_allocated',
                    ]);
                    FinancialPaymentAllocation::create([
                        'settlement_id' => $settlement->id,
                        'settlement_item_id' => $item->id,
                        'booking_id' => $item->booking_id,
                        'payment_receipt_id' => $receipt->id,
                        'amount' => $amount,
                        'allocated_by' => $userId,
                        'allocated_at' => now(),
                    ]);
                }
            }
            $settlement = $this->settlements->recalculate($settlement);
            $statement = $this->statementSnapshot($settlement, (float) $preview['opening_balance'], $preview['opening_balance_breakdown'] ?? []);
            $path = 'corporate-statements/' . $settlement->id . '.pdf';
            Storage::disk('local')->put($path, Pdf::loadView('finance.corporate-statement', ['settlement' => $settlement, 'statement' => $statement])->output());
            $settlement->update(['statement_snapshot' => $statement, 'statement_pdf_path' => $path, 'statement_pdf_disk' => 'local']);
            FinancialAuditEvent::create([
                'subject_type' => 'account_settlement',
                'subject_id' => $settlement->id,
                'event_type' => 'corporate_monthly_billing_generated',
                'from_status' => null,
                'to_status' => $settlement->status,
                'amount' => $settlement->charges_total,
                'performed_by' => $userId,
                'occurred_at' => now(),
                'metadata' => ['generation_key' => $preview['generation_key'], 'period_start' => $preview['period_start'], 'period_end' => $preview['period_end'], 'trip_count' => $preview['trip_count']],
            ]);

            return $settlement->fresh(['items.booking', 'document']);
        });
    }

    public function issue(FinancialAccountSettlement $settlement, bool $sendDocuments = true): FinancialAccountSettlement
    {
        abort_unless($settlement->owner_type === 'corporate' && $settlement->generation_key, 422, 'This is not a generated corporate billing settlement.');
        $issued = $this->settlements->issue($settlement, [
            'send_invoice' => $sendDocuments && data_get($settlement->billing_terms_snapshot, 'delivery_preferences.email_invoice', false),
        ]);
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
                    FinancialAuditEvent::create(['subject_type' => 'account_settlement', 'subject_id' => $issued->id, 'event_type' => 'corporate_statement_sent', 'from_status' => $issued->status, 'to_status' => $issued->status, 'metadata' => ['sent_to' => $recipients->all()], 'performed_by' => auth()->id(), 'occurred_at' => now()]);
                } catch (\Throwable $exception) {
                    $issued->update(['statement_last_error' => $exception->getMessage()]);
                    FinancialAuditEvent::create(['subject_type' => 'account_settlement', 'subject_id' => $issued->id, 'event_type' => 'corporate_statement_delivery_failed', 'from_status' => $issued->status, 'to_status' => $issued->status, 'metadata' => ['error_class' => get_class($exception)], 'performed_by' => auth()->id(), 'occurred_at' => now()]);
                }
            }
        }
        return $issued->fresh(['items.booking', 'document']);
    }

    private function effectiveTerms(string $corporateId, Carbon $at): CorporateBillingTerm
    {
        return CorporateBillingTerm::withInactive()->where('corporate_id', $corporateId)
            ->whereDate('effective_from', '<=', $at->toDateString())
            ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $at->toDateString()))
            ->orderByDesc('effective_from')->firstOrFail();
    }

    private function generationKey(string $corporateId, Carbon $start, Carbon $end, array $itemSnapshots, array $paymentSnapshots, array $termsSnapshot, float $openingBalance, array $openingBalanceBreakdown): string
    {
        return hash('sha256', json_encode([
            'corporate_id' => $corporateId,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'items' => $itemSnapshots,
            'payments' => $paymentSnapshots,
            'terms' => $termsSnapshot,
            'opening_balance' => $openingBalance,
            'opening_balance_breakdown' => $openingBalanceBreakdown,
            'version' => 'monthly-v5',
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function paymentReceiptsForGroup(array $group, bool $lock = false): Collection
    {
        $query = BookingPaymentReceipt::query()
            ->where('booking_id', $group['booking_id'])
            ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
            ->orderBy('received_at')
            ->orderBy('created_at');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function paymentSnapshotHash(Collection $receipts, string $currency, ?string $fallbackSourceCurrency = null): string
    {
        $currencyService = app(CurrencyService::class);
        $snapshot = $receipts->map(function ($receipt) use ($currency, $currencyService, $fallbackSourceCurrency) {
            $sourceCurrency = strtoupper((string) ($receipt->source_currency ?: $fallbackSourceCurrency ?: $currency));
            $availableSourceAmount = max(0,
                (float) ($receipt->source_amount ?? $receipt->amount)
                    - (float) $receipt->refunded_amount
                    - (float) $receipt->allocated_amount
            );
            $rate = $availableSourceAmount == 0.0 ? 1.0 : $this->conversionRate($sourceCurrency, $currency);

            return [
            'id' => (string) $receipt->id,
            'amount' => (string) $receipt->amount,
            'source_amount' => $receipt->source_amount === null ? null : (string) $receipt->source_amount,
            'source_currency' => $sourceCurrency,
            'refunded_amount' => (string) $receipt->refunded_amount,
            'allocated_amount' => (string) $receipt->allocated_amount,
            'available_invoice_amount' => $currencyService->roundAmount($availableSourceAmount * $rate, $currency),
            'fx_rate_to_invoice' => $rate,
        ];
        })->sortBy('id')->values()->all();

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function conversionRate(string $fromCurrency, string $toCurrency): float
    {
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            return 1.0;
        }

        try {
            return app(CurrencyService::class)->getExchangeRate($fromCurrency, $toCurrency);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'currency' => ["A portal exchange rate from {$fromCurrency} to {$toCurrency} is required before this billing period can be generated."],
            ]);
        }
    }

    private function termsSnapshot(CorporateBillingTerm $terms): array
    {
        return collect($terms->only(['id', 'billing_cycle', 'cutoff_day', 'invoice_day', 'due_days', 'credit_limit', 'currency', 'billing_name', 'tax_identifier', 'billing_address', 'recipients', 'delivery_preferences', 'effective_from', 'effective_to']))
            ->map(fn($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)->all();
    }

    private function statementSnapshot(FinancialAccountSettlement $settlement, float $opening, array $openingBreakdown = []): array
    {
        return [
            'period_start' => $settlement->period_start->toDateString(),
            'period_end' => $settlement->period_end->toDateString(),
            'currency' => strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
                ?: $settlement->items->first()?->booking?->currency ?: 'LKR')),
            'opening_balance' => app(CurrencyService::class)->roundAmount($opening, strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency') ?: 'LKR'))),
            'opening_balance_breakdown' => $openingBreakdown,
            'charges' => (float) $settlement->charges_total,
            'payments' => (float) $settlement->payments_total,
            'refunds' => (float) $settlement->refunds_total,
            'adjustments' => (float) $settlement->adjustments_total,
            'period_closing_balance' => (float) $settlement->outstanding_total,
            'account_closing_balance' => app(CurrencyService::class)->roundAmount($opening + (float) $settlement->outstanding_total, strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency') ?: 'LKR'))),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
