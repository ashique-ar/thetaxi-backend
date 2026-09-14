<?php
namespace App\Models\Finance;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccountSettlement extends BaseModel
{
    protected $fillable = ['settlement_number', 'owner_type', 'owner_id', 'billing_terms_id', 'billing_cycle', 'generation_key', 'billing_terms_snapshot', 'statement_snapshot', 'statement_pdf_path', 'statement_pdf_disk', 'statement_sent_at', 'statement_sent_to', 'statement_last_error', 'period_start', 'period_end', 'due_date', 'status', 'invoice_number', 'charges_total', 'payments_total', 'refunds_total', 'adjustments_total', 'outstanding_total', 'issued_at', 'settled_at', 'payment_reference', 'collection_owner_id', 'collection_last_contact_at', 'promised_payment_date', 'next_follow_up_at', 'collection_notes', 'notes', 'dispute_reason', 'disputed_at', 'disputed_by', 'resolved_at', 'resolved_by', 'resolution_notes', 'created_user_id', 'updated_user_id'];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'settled_at' => 'datetime', 'statement_sent_at' => 'datetime', 'collection_last_contact_at' => 'datetime', 'promised_payment_date' => 'date', 'next_follow_up_at' => 'datetime', 'statement_sent_to' => 'array', 'charges_total' => 'decimal:2', 'payments_total' => 'decimal:2', 'refunds_total' => 'decimal:2', 'adjustments_total' => 'decimal:2', 'outstanding_total' => 'decimal:2', 'billing_terms_snapshot' => 'array', 'statement_snapshot' => 'array'];
    public function items(): HasMany
    {
        return $this->hasMany(FinancialSettlementItem::class, 'settlement_id');
    }
    public function allocations(): HasMany
    {
        return $this->hasMany(FinancialPaymentAllocation::class, 'settlement_id');
    }
    public function document()
    {
        return $this->hasOne(FinancialSettlementDocument::class, 'settlement_id');
    }
    public function auditEvents(): HasMany
    {
        return $this->hasMany(FinancialAuditEvent::class, 'subject_id')->where('subject_type', 'account_settlement');
    }
}
