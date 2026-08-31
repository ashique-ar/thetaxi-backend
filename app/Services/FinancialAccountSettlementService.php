<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Finance\FinancialAccountSettlement;
use App\Models\Finance\FinancialPaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Finance\DriverCashSettlement;
use App\Models\Finance\FinancialAdjustment;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Finance\FinancialSettlementDocument;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\DriverCashSettlementItem;
use App\Models\Corporate\Corporate;
use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;

class FinancialAccountSettlementService
{
    public function __construct(
        private readonly BookingPaymentLedgerService $ledger,
        private readonly DriverHireSettlementService $driverSettlements,
        private readonly BookingOperationsHealthMonitor $healthMonitor,
    ) {}

    public function monitorReconciliation(FinancialAccountSettlement $settlement): array
    {
        $items = $settlement->items()->get();
        $issues = [];
        $expected = [
            'charges_total' => round((float) $items->sum('charge_amount'), 2),
            'payments_total' => round((float) $items->sum(fn ($item) => (float) $item->paid_before_amount + (float) $item->allocated_amount), 2),
            'refunds_total' => round((float) $items->sum('refund_amount'), 2),
            'adjustments_total' => round((float) $items->sum('adjustment_amount'), 2),
            'outstanding_total' => round((float) $items->sum('outstanding_amount'), 2),
        ];

        foreach ($expected as $field => $value) {
            if (abs((float) $settlement->{$field} - $value) > 0.01) {
                $issues[] = 'aggregate_' . $field;
            }
        }

        if ($items->contains(function ($item): bool {
            $expectedOutstanding = max(0, round(
                (float) $item->charge_amount
                + (float) $item->adjustment_amount
                - (float) $item->refund_amount
                - (float) $item->paid_before_amount
                - (float) $item->allocated_amount,
                2
            ));
            return abs((float) $item->outstanding_amount - $expectedOutstanding) > 0.01;
        })) {
            $issues[] = 'item_outstanding_total';
        }

        $this->healthMonitor->recordSettlementMismatch((string) $settlement->id, $issues);
        return $issues;
    }

    public function markOverdueSettlements(?string $userId = null): int
    {
        $settlements = FinancialAccountSettlement::query()
            ->whereIn('status', ['open', 'partial'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->get();
        foreach ($settlements as $settlement) {
            $from = $settlement->status;
            $settlement->update(['status' => 'overdue']);
            $this->audit('account_settlement',$settlement->id,'marked_overdue',$from,'overdue',null,['due_date'=>$settlement->due_date?->toDateString()],$userId);
        }
        return $settlements->count();
    }

    public function create(array $data, ?string $userId): FinancialAccountSettlement
    {
        return DB::transaction(function () use ($data, $userId) {
            $query = Booking::query()
                ->whereBetween('booking_date', [$data['period_start'], $data['period_end']])
                ->whereNotIn('status', ['cancelled', 'canceled', 'rejected', 'expired']);
            $activeBookingIds=\App\Models\Finance\FinancialSettlementItem::whereHas('settlement',fn($q)=>$q->whereNotIn('status',['paid','void']))->pluck('booking_id');
            $query->whereNotIn('id',$activeBookingIds);
            if ($data['owner_type'] === 'corporate') {
                $query->where('is_corporate_booking', true)->where('corporate_account_id', $data['owner_id']);
            } else {
                $query->where('customer_id', $data['owner_id'])
                    ->where(fn ($q) => $q->where('is_corporate_booking', false)->orWhereNull('is_corporate_booking'));
            }
            $bookings = $query->with('bookingItems.serviceType')->orderBy('booking_date')->get()
                ->filter(fn (Booking $booking) => $this->ledger->summary($booking)['due_amount'] > 0);
            if ($bookings->isEmpty()) {
                throw ValidationException::withMessages(['owner_id' => ['No unsettled bookings were found for this account and period.']]);
            }

            $configuredDueDays=(int)($bookings->flatMap(fn($booking)=>$booking->bookingItems)->pluck('serviceType.settlement_due_days')->filter(fn($v)=>$v!==null)->max() ?? ($data['owner_type']==='corporate'?30:14));
            $data['due_date']=$data['due_date']??\Illuminate\Support\Carbon::parse($data['period_end'])->addDays($configuredDueDays)->toDateString();
            $settlement = FinancialAccountSettlement::create([
                ...$data,
                'settlement_number' => $this->nextNumber($data['owner_type']),
                'status' => 'draft',
                'created_user_id' => $userId,
                'updated_user_id' => $userId,
            ]);
            foreach ($bookings as $booking) {
                $summary = $this->ledger->summary($booking);
                $settlement->items()->create([
                    'booking_id' => $booking->id,
                    'charge_amount' => $summary['total_amount'],
                    'paid_before_amount' => $summary['paid_amount'],
                    'outstanding_amount' => $summary['due_amount'],
                ]);
                $booking->update([
                    $data['owner_type'] === 'corporate' ? 'corporate_settlement_status' : 'customer_settlement_status' => 'draft',
                    'settlement_due_date' => $data['due_date'] ?? null,
                    'invoice_status' => 'draft',
                    'payment_status' => $data['owner_type'] === 'corporate' ? 'corporate_account' : 'credit_terms',
                ]);
            }
            $settlement=$this->recalculate($settlement);
            $this->audit('account_settlement',$settlement->id,'created',null,'draft',null,['owner_type'=>$settlement->owner_type,'owner_id'=>$settlement->owner_id],$userId);
            return $settlement;
        });
    }

    public function issue(FinancialAccountSettlement $settlement, array $data): FinancialAccountSettlement
    {
        abort_if(!in_array($settlement->status, ['draft', 'open'], true), 422, 'Only draft or open settlements can be issued.');
        $settlement->update([
            'status' => 'open', 'issued_at' => now(),
            'invoice_number' => $data['invoice_number'] ?? $settlement->invoice_number ?? ('INV-' . $settlement->settlement_number),
            'due_date' => $data['due_date'] ?? $settlement->due_date,
        ]);
        foreach ($settlement->items()->with('booking')->get() as $item) {
            $item->booking?->update([
                'invoice_status' => 'issued',
                'payment_status' => $settlement->owner_type === 'corporate' ? 'invoiced_corporate' : 'invoiced_credit',
                $settlement->owner_type === 'corporate' ? 'corporate_settlement_status' : 'customer_settlement_status' => 'open',
                'settlement_due_date' => $settlement->due_date,
            ]);
        }
        $settlement=$this->recalculate($settlement);
        $document=$this->generateDocument($settlement);
        if (($data['send_invoice'] ?? true) === true) $this->sendDocument($settlement,$document);
        $this->audit('account_settlement',$settlement->id,'issued','draft','open',null,['invoice_number'=>$settlement->invoice_number],auth()->id());
        return $settlement->fresh(['items.booking','document']);
    }

    public function receivePayment(FinancialAccountSettlement $settlement, array $data, ?string $userId): FinancialAccountSettlement
    {
        abort_unless(in_array($settlement->status,['open','partial','overdue'],true),422,'Only issued, partial, or overdue settlements can receive payments.');
        return DB::transaction(function () use ($settlement, $data, $userId) {
            $settlement = FinancialAccountSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            $remaining = round((float) $data['amount'], 2);
            if ($remaining > (float) $settlement->outstanding_total) {
                throw ValidationException::withMessages(['amount' => ['Payment exceeds this settlement outstanding total.']]);
            }
            foreach ($settlement->items()->with('booking')->orderBy('created_at')->get() as $item) {
                if ($remaining <= 0 || (float) $item->outstanding_amount <= 0) continue;
                $amount = min($remaining, (float) $item->outstanding_amount);
                $this->ledger->receive($item->booking, [
                    'amount' => $amount, 'payment_method' => $data['payment_method'],
                    'payment_stage' => 'account_payment', 'reference' => $data['reference'] ?? null,
                    'received_at' => $data['received_at'], 'notes' => $data['notes'] ?? null,
                    'received_via' => 'company',
                    'skip_settlement_allocation' => true,
                ], $userId);
                $receipt = BookingPaymentReceipt::where('booking_id', $item->booking_id)->latest('created_at')->firstOrFail();
                $receipt->update(['allocated_amount' => $amount, 'allocation_status' => 'allocated']);
                $allocation = FinancialPaymentAllocation::create([
                    'settlement_id' => $settlement->id, 'settlement_item_id' => $item->id,
                    'booking_id' => $item->booking_id, 'payment_receipt_id' => $receipt->id,
                    'amount' => $amount, 'allocated_by' => $userId, 'allocated_at' => now(),
                ]);
                $this->audit('payment_allocation', $allocation->id, 'payment_allocated', null, 'allocated', $amount, [
                    'settlement_id' => $settlement->id, 'settlement_item_id' => $item->id, 'receipt_id' => $receipt->id,
                ], $userId, $item->booking_id);
                $item->update([
                    'allocated_amount' => (float) $item->allocated_amount + $amount,
                    'outstanding_amount' => (float) $item->outstanding_amount - $amount,
                    'status' => $amount >= (float) $item->outstanding_amount ? 'paid' : 'partial',
                ]);
                $remaining = round($remaining - $amount, 2);
            }
            $settlement->update(['payment_reference' => $data['reference'] ?? $settlement->payment_reference]);
            $settlement=$this->recalculate($settlement);
            $this->audit('account_settlement',$settlement->id,'payment_received',null,$settlement->status,(float)$data['amount'],['reference'=>$data['reference']??null],$userId);
            return $settlement;
        });
    }

    public function recalculate(FinancialAccountSettlement $settlement): FinancialAccountSettlement
    {
        $items = $settlement->items()->get();
        $outstanding = round((float) $items->sum('outstanding_amount'), 2);
        $payments = round((float) $items->sum(fn ($item) => (float) $item->paid_before_amount + (float) $item->allocated_amount), 2);
        $status = $outstanding <= 0 ? 'paid' : ($payments > 0 ? 'partial' : ($settlement->status === 'draft' ? 'draft' : 'open'));
        if ($outstanding > 0 && $settlement->due_date?->isPast()) $status = 'overdue';
        $settlement->update([
            'charges_total' => $items->sum('charge_amount'), 'payments_total' => $payments,
            'refunds_total' => $items->sum('refund_amount'), 'adjustments_total' => $items->sum('adjustment_amount'),
            'outstanding_total' => $outstanding, 'status' => $status,
            'settled_at' => $outstanding <= 0 ? ($settlement->settled_at ?: now()) : null,
        ]);
        if ($outstanding <= 0) {
            $settlement->document()?->update(['status'=>'paid']);
            foreach ($settlement->items()->with('booking')->get() as $item) {
                $item->booking?->update([
                    'payment_status' => 'paid', 'invoice_status' => 'paid', 'settled_at' => $settlement->settled_at,
                    $settlement->owner_type === 'corporate' ? 'corporate_settlement_status' : 'customer_settlement_status' => 'settled',
                ]);
            }
        }
        return $settlement->fresh(['items.booking']);
    }

    public function settleDriverCash(array $data, ?string $userId): DriverCashSettlement
    {
        return DB::transaction(function () use ($data, $userId) {
            $receipts = BookingPaymentReceipt::query()->lockForUpdate()
                ->where('driver_id', $data['driver_id'])->where('received_via', 'driver')
                ->whereRaw('amount > driver_company_settled_amount')->orderBy('received_at')->get();
            $held = round((float) $receipts->sum(fn($r)=>(float)$r->amount-(float)$r->driver_company_settled_amount),2);
            if ((float)$data['amount'] > $held) throw ValidationException::withMessages(['amount'=>['Handover amount exceeds cash currently held by this driver.']]);
            $settlement=DriverCashSettlement::create([
                'settlement_number'=>'DCS-'.now()->format('Ymd-His').'-'.strtoupper(substr((string)\Illuminate\Support\Str::uuid(),0,6)),
                'driver_id'=>$data['driver_id'],'amount'=>$data['amount'],'status'=>'settled','due_date'=>$data['due_date']??null,
                'handed_over_at'=>$data['handed_over_at'],'reference'=>$data['reference']??null,'proof_files'=>$data['proof_files']??null,
                'notes'=>$data['notes']??null,'received_by'=>$userId,'created_user_id'=>$userId,
            ]);
            $remaining=round((float)$data['amount'],2);
            foreach($receipts as $receipt){
                if($remaining<=0)break; $open=(float)$receipt->amount-(float)$receipt->driver_company_settled_amount; $amount=min($open,$remaining);
                $cashItem = $settlement->items()->create(['payment_receipt_id'=>$receipt->id,'booking_id'=>$receipt->booking_id,'amount'=>$amount]);
                $this->audit('driver_cash_allocation',$cashItem->id,'driver_cash_allocated',null,'settled',$amount,['driver_cash_settlement_id'=>$settlement->id,'receipt_id'=>$receipt->id,'driver_id'=>$data['driver_id']],$userId,$receipt->booking_id);
                $newSettled=(float)$receipt->driver_company_settled_amount+$amount;
                $receipt->update(['driver_company_settled_amount'=>$newSettled,'driver_company_settlement_status'=>$newSettled >= (float)$receipt->amount?'settled':'partially_settled','driver_company_settled_at'=>$newSettled >= (float)$receipt->amount?$data['handed_over_at']:null]);
                $remaining=round($remaining-$amount,2);
            }
            $this->driverSettlements->reconcileCashHandoff(
                $data['driver_id'],
                $settlement->items()->pluck('booking_id'),
            );
            $this->audit('driver_cash_settlement',$settlement->id,'cash_handed_over','unsettled','settled',(float)$data['amount'],['driver_id'=>$data['driver_id'],'reference'=>$data['reference']??null],$userId);
            return $settlement->load('items');
        });
    }

    public function adjust(FinancialAccountSettlement $settlement, array $data, ?string $userId): FinancialAccountSettlement
    {
        abort_if(in_array($settlement->status,['paid','void','disputed'],true),422,'Paid, void, or disputed settlements cannot be adjusted.');
        return DB::transaction(function() use($settlement,$data,$userId){
            $item=$settlement->items()->where('booking_id',$data['booking_id'])->firstOrFail();
            $amount=round((float)$data['amount'],2);
            FinancialAdjustment::create(['settlement_id'=>$settlement->id,'settlement_item_id'=>$item->id,'booking_id'=>$item->booking_id,'type'=>$data['type'],'amount'=>$amount,'reason'=>$data['reason'],'reference'=>$data['reference']??null,'created_user_id'=>$userId]);
            if(in_array($data['type'],['refund','credit_note'],true))$item->refund_amount=(float)$item->refund_amount+$amount;
            else $item->adjustment_amount=(float)$item->adjustment_amount+($data['type']==='additional_charge'?$amount:-$amount);
            $item->outstanding_amount=max(0,round((float)$item->charge_amount+(float)$item->adjustment_amount-(float)$item->refund_amount-(float)$item->paid_before_amount-(float)$item->allocated_amount,2));
            $item->status=(float)$item->outstanding_amount<=0?'paid':'open';$item->save();
            if($data['type']==='waiver')$item->booking?->update(['payment_status'=>'waived','refund_status'=>'waived']);
            elseif(in_array($data['type'],['refund','credit_note'],true))$item->booking?->update(['refund_status'=>$data['type']==='refund'?'refunded':'credit_noted']);
            $settlement=$this->recalculate($settlement);
            $this->audit('account_settlement',$settlement->id,$data['type'],null,$settlement->status,$amount,['booking_id'=>$data['booking_id'],'reason'=>$data['reason'],'reference'=>$data['reference']??null],$userId,$data['booking_id']);
            return $settlement;
        });
    }

    public function dispute(FinancialAccountSettlement $settlement, string $reason, ?string $userId): FinancialAccountSettlement
    {
        $from=$settlement->status;$settlement->update(['status'=>'disputed','dispute_reason'=>$reason,'disputed_at'=>now(),'disputed_by'=>$userId]);
        $this->audit('account_settlement',$settlement->id,'disputed',$from,'disputed',null,['reason'=>$reason],$userId);
        return $settlement->fresh(['items.booking','document']);
    }

    public function resolveDispute(FinancialAccountSettlement $settlement, string $notes, ?string $userId): FinancialAccountSettlement
    {
        abort_unless($settlement->status==='disputed',422,'Only disputed settlements can be resolved.');
        $settlement->update(['status'=>'open','resolved_at'=>now(),'resolved_by'=>$userId,'resolution_notes'=>$notes]);
        $settlement=$this->recalculate($settlement);$this->audit('account_settlement',$settlement->id,'dispute_resolved','disputed',$settlement->status,null,['notes'=>$notes],$userId);
        return $settlement;
    }

    public function generateDocument(FinancialAccountSettlement $settlement): FinancialSettlementDocument
    {
        $settlement->load(['items.booking']);
        $owner=$settlement->owner_type==='corporate'?Corporate::find($settlement->owner_id):Customer::with('user')->find($settlement->owner_id);
        $ownerName=$settlement->owner_type==='corporate'?($owner?->name??'Corporate account'):(trim(($owner?->user?->first_name??'').' '.($owner?->user?->last_name??''))?:($owner?->code??'Customer account'));
        $document=FinancialSettlementDocument::updateOrCreate(['settlement_id'=>$settlement->id],['invoice_number'=>$settlement->invoice_number,'status'=>$settlement->status==='paid'?'paid':'issued','pdf_disk'=>'local','last_error'=>null]);
        try{$pdf=Pdf::loadView('invoices.financial-settlement',['settlement'=>$settlement,'ownerName'=>$ownerName]);$path='invoices/settlements/'.$settlement->invoice_number.'.pdf';Storage::disk('local')->put($path,$pdf->output());$document->update(['pdf_path'=>$path,'generated_at'=>now()]);$this->audit('account_settlement',$settlement->id,'invoice_generated',null,$document->status,null,['invoice_number'=>$document->invoice_number,'document_id'=>$document->id],auth()->id());}
        catch(\Throwable $e){$document->update(['last_error'=>$e->getMessage()]);$this->audit('account_settlement',$settlement->id,'invoice_generation_failed',null,$settlement->status,null,['invoice_number'=>$document->invoice_number,'document_id'=>$document->id,'error_class'=>get_class($e)],auth()->id());throw $e;}
        return $document->fresh();
    }

    public function sendDocument(FinancialAccountSettlement $settlement, FinancialSettlementDocument $document): void
    {
        $recipients = $settlement->owner_type === 'corporate'
            ? collect(data_get($settlement->billing_terms_snapshot, 'recipients', []))->filter()->values()
            : collect([Customer::with('user')->find($settlement->owner_id)?->user?->email])->filter()->values();
        if ($settlement->owner_type === 'corporate' && $recipients->isEmpty()) {
            $recipients = collect([Corporate::whereKey($settlement->owner_id)->value('contact_email')])->filter()->values();
        }
        if($recipients->isEmpty()){$document->update(['last_error'=>'No billing email is recorded for this account.']);$this->audit('account_settlement',$settlement->id,'invoice_delivery_failed',null,$settlement->status,null,['invoice_number'=>$document->invoice_number,'document_id'=>$document->id,'reason'=>'billing_email_missing'],auth()->id());return;}
        $sentTo = $recipients->implode(',');
        try{Mail::raw('Please find attached account invoice '.$document->invoice_number.' for settlement '.$settlement->settlement_number.'.',function($message)use($recipients,$document){$message->to($recipients->all())->subject('Account invoice '.$document->invoice_number)->attach(Storage::disk($document->pdf_disk)->path($document->pdf_path));});$document->update(['sent_at'=>now(),'sent_to'=>$sentTo,'last_error'=>null]);$this->audit('account_settlement',$settlement->id,'invoice_sent',null,$document->status,null,['invoice_number'=>$document->invoice_number,'document_id'=>$document->id,'sent_to'=>$recipients->all()],auth()->id());}
        catch(\Throwable $e){$document->update(['last_error'=>$e->getMessage()]);$this->audit('account_settlement',$settlement->id,'invoice_delivery_failed',null,$settlement->status,null,['invoice_number'=>$document->invoice_number,'document_id'=>$document->id,'error_class'=>get_class($e)],auth()->id());}
    }

    public function disputeDriverCashReceipt(BookingPaymentReceipt $receipt, string $reason, ?string $userId): BookingPaymentReceipt
    {
        abort_unless($receipt->received_via === 'driver', 422, 'This is not a driver cash collection.');
        $from = $receipt->driver_company_settlement_status;
        $receipt->update(['driver_company_settlement_status'=>'disputed','metadata'=>array_merge($receipt->metadata??[],['driver_cash_dispute_reason'=>$reason,'driver_cash_disputed_at'=>now()->toIso8601String(),'driver_cash_disputed_by'=>$userId])]);
        $this->audit('driver_cash_receipt',$receipt->id,'driver_cash_disputed',$from,'disputed',null,['reason'=>$reason],$userId,$receipt->booking_id);
        return $receipt->fresh();
    }

    public function resolveDriverCashReceiptDispute(BookingPaymentReceipt $receipt, string $notes, ?string $userId): BookingPaymentReceipt
    {
        abort_unless($receipt->driver_company_settlement_status === 'disputed', 422, 'This cash collection is not disputed.');
        $to=(float)$receipt->driver_company_settled_amount >= (float)$receipt->amount?'settled':'unsettled';
        $receipt->update(['driver_company_settlement_status'=>$to,'metadata'=>array_merge($receipt->metadata??[],['driver_cash_resolution_notes'=>$notes,'driver_cash_resolved_at'=>now()->toIso8601String(),'driver_cash_resolved_by'=>$userId])]);
        $this->audit('driver_cash_receipt',$receipt->id,'driver_cash_dispute_resolved','disputed',$to,null,['notes'=>$notes],$userId,$receipt->booking_id);
        return $receipt->fresh();
    }

    private function audit(string $subjectType,string $subjectId,string $event,?string $from,?string $to,?float $amount,array $metadata,?string $userId,?string $bookingId=null):void
    {
        $bookingIds = $bookingId ? collect([$bookingId]) : match ($subjectType) {
            'account_settlement' => FinancialSettlementItem::where('settlement_id', $subjectId)->pluck('booking_id'),
            'driver_cash_settlement' => DriverCashSettlementItem::where('driver_cash_settlement_id', $subjectId)->pluck('booking_id'),
            default => collect([null]),
        };
        if ($bookingIds->isEmpty()) $bookingIds = collect([null]);
        foreach ($bookingIds->unique() as $linkedBookingId) {
            FinancialAuditEvent::create(['subject_type'=>$subjectType,'subject_id'=>$subjectId,'booking_id'=>$linkedBookingId,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'amount'=>$amount,'metadata'=>$metadata,'performed_by'=>$userId,'occurred_at'=>now()]);
        }
    }

    private function nextNumber(string $ownerType): string
    {
        return strtoupper(substr($ownerType, 0, 1)) . 'ST-' . now()->format('Ymd-His') . '-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 6));
    }
}
