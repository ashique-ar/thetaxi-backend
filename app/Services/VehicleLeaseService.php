<?php

namespace App\Services;

use App\Enums\VehicleAvailabilityStatus;
use App\Models\Document;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleFinanceProvider;
use App\Models\Vehicle\VehicleLease;
use App\Models\Vehicle\VehicleLeaseEvent;
use App\Models\Vehicle\VehicleLeasePayment;
use App\Models\Vehicle\VehicleLeasePaymentAllocation;
use App\Models\Vehicle\VehicleLeaseRelease;
use App\Models\Vehicle\VehicleLeaseSchedule;
use App\Models\Vehicle\VehicleOwnershipHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleLeaseService
{
    public function create(Vehicle $vehicle, array $data, ?string $userId): VehicleLease
    {
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if (VehicleLease::query()->where('vehicle_id', $vehicle->id)->whereIn('status', ['draft', 'active', 'expired', 'closure_pending'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['vehicle_id' => ['This vehicle already has an open finance or lease contract.']]);
            }
            $this->validateFinancialTerms($data);
            $this->validateVehicleContractCompatibility($vehicle, $data);
            $data['ownership_transfer_required'] = (bool) ($data['ownership_transfer_required'] ?? false);
            $lease = VehicleLease::create([
                ...$data,
                'vehicle_id' => $vehicle->id,
                'owner_id_at_start' => $vehicle->owner_id,
                'ownership_type_at_start' => $vehicle->ownership_type ?: 'company_owned',
                'lease_number' => $data['lease_number'] ?? $this->nextLeaseNumber(),
                'status' => 'draft',
                'financial_status' => 'pending',
                'created_user_id' => $userId,
            ]);
            $this->event($lease, 'created', null, 'draft', ['terms' => $lease->only([
                'agreement_number', 'finance_provider_id', 'contract_type',
                'owner_id_at_start', 'ownership_type_at_start',
                'start_date', 'end_date', 'first_payment_date',
                'financed_amount', 'installment_amount', 'installment_count',
                'payment_frequency', 'refundable_deposit', 'deposit_paid_amount',
            ])], $userId);
            return $lease->fresh(['financeProvider', 'ownerAtStart.user', 'vehicle.owner.user']);
        });
    }

    public function updateDraft(VehicleLease $lease, array $data, ?string $userId): array
    {
        return DB::transaction(function () use ($lease, $data, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            if ($lease->status !== 'draft') {
                throw ValidationException::withMessages(['lease' => ['Only a draft lease can be edited.']]);
            }
            $this->validateFinancialTerms($data);
            $this->validateVehicleContractCompatibility($lease->vehicle, $data);
            $data['ownership_transfer_required'] = (bool) ($data['ownership_transfer_required'] ?? false);
            $before = $lease->only(array_keys($data));
            $lease->update($data);
            $this->event($lease, 'draft_updated', 'draft', 'draft', [
                'before' => $before,
                'after' => $lease->fresh()->only(array_keys($data)),
            ], $userId);

            return $this->summary($lease->fresh());
        });
    }

    public function activate(VehicleLease $lease, ?string $userId): array
    {
        DB::transaction(function () use ($lease, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            if ($lease->status !== 'draft') {
                throw ValidationException::withMessages(['lease' => ['Only a draft lease can be activated.']]);
            }
            if (VehicleLease::query()->where('vehicle_id', $lease->vehicle_id)->whereIn('status', ['active', 'expired', 'closure_pending'])->whereKeyNot($lease->id)->exists()) {
                throw ValidationException::withMessages(['vehicle_id' => ['This vehicle already has another open finance or lease contract.']]);
            }
            $this->generateSchedule($lease, $userId);
            $lease->update([
                'status' => 'active',
                'financial_status' => 'active',
                'activated_at' => now(),
            ]);
            $lease->vehicle()->update([
                'agreement_start_date' => $lease->start_date,
                'agreement_end_date' => $lease->end_date,
                'agreement_status' => 'active',
            ]);
            $this->event($lease, 'activated', 'draft', 'active', [
                'schedule_count' => $lease->installment_count,
                'owner_id_unchanged' => $lease->vehicle->owner_id,
                'ownership_type_unchanged' => $lease->vehicle->ownership_type,
            ], $userId);
        });
        return $this->summary($lease->fresh());
    }

    public function recordPayment(VehicleLease $lease, array $data, ?string $userId): array
    {
        return DB::transaction(function () use ($lease, $data, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            if (!in_array($lease->status, ['active', 'expired'], true)) {
                throw ValidationException::withMessages(['lease' => ['Payments can be recorded only for active or expired leases.']]);
            }
            if ($lease->financial_status === 'settled') {
                throw ValidationException::withMessages(['lease' => ['This finance contract is already fully settled.']]);
            }
            $duplicate = VehicleLeasePayment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                if ($duplicate->vehicle_lease_id !== $lease->id) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This payment key belongs to another lease.']]);
                }
                $sameRequest = round((float) $duplicate->amount, 2) === round((float) $data['amount'], 2)
                    && $duplicate->paid_date->toDateString() === Carbon::parse($data['paid_date'])->toDateString()
                    && $duplicate->payment_method === $data['payment_method']
                    && ($duplicate->reference ?: null) === ($data['reference'] ?? null)
                    && ($duplicate->notes ?: null) === ($data['notes'] ?? null);
                if (!$sameRequest) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This payment key was already used with different payment details.'],
                    ]);
                }
                return $this->summary($lease);
            }
            $outstanding = $this->outstanding($lease);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $outstanding) {
                throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the lease outstanding amount.']]);
            }
            $payment = VehicleLeasePayment::create([
                'vehicle_lease_id' => $lease->id,
                'amount' => $amount,
                'paid_date' => $data['paid_date'],
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'recorded',
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $userId,
            ]);
            $this->allocate($lease, $payment, $amount, $userId);
            $this->event($lease, 'payment_recorded', $lease->status, $lease->status, [
                'payment_id' => $payment->id, 'amount' => $amount,
                'paid_date' => $payment->paid_date->toDateString(), 'reference' => $payment->reference,
            ], $userId);
            if ($this->outstanding($lease) <= 0) {
                $fromStatus = $lease->status;
                $nextStatus = $lease->contract_type === 'operating_lease'
                    ? $lease->status
                    : 'closure_pending';
                $lease->update([
                    'status' => $nextStatus,
                    'financial_status' => 'settled',
                    'financially_settled_at' => now(),
                ]);
                $this->event($lease, 'financially_settled', $fromStatus, $nextStatus, [
                    'owner_id_unchanged' => $lease->vehicle->owner_id,
                    'ownership_type_unchanged' => $lease->vehicle->ownership_type,
                    'next_action' => $lease->contract_type === 'operating_lease'
                        ? 'Await physical return or approved lease release.'
                        : ($lease->ownership_transfer_required
                            ? 'Verify discharge/title document and record ownership transfer.'
                            : 'Verify discharge document and close the finance contract.'),
                ], $userId);
            }
            return $this->summary($lease->fresh());
        });
    }

    public function reversePayment(VehicleLease $lease, string $paymentId, string $reason, string $userId): array
    {
        return DB::transaction(function () use ($lease, $paymentId, $reason, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            $payment = VehicleLeasePayment::query()
                ->where('vehicle_lease_id', $lease->id)
                ->lockForUpdate()
                ->findOrFail($paymentId);
            if ($payment->status !== 'recorded') {
                throw ValidationException::withMessages(['payment' => ['Only a recorded payment can be reversed.']]);
            }
            if ($lease->release()->exists() || $lease->closed_at) {
                throw ValidationException::withMessages(['payment' => ['A payment cannot be reversed after release or finance closure.']]);
            }

            $fromStatus = $lease->status;
            $payment->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
            ]);
            $payment->allocations()->update([
                'reversed_at' => now(),
                'reversed_by' => $userId,
            ]);
            $lease->schedules()->withSum([
                'allocations as active_allocations_sum' => fn ($query) => $query->whereNull('reversed_at'),
            ], 'amount')->get()->each(function ($schedule) {
                $paid = round((float) ($schedule->active_allocations_sum ?? 0), 2);
                $schedule->update([
                    'status' => $paid <= 0
                        ? ($schedule->due_date->isPast() ? 'overdue' : 'scheduled')
                        : ($paid >= (float) $schedule->amount_due ? 'paid' : 'partially_paid'),
                ]);
            });
            if ($lease->financial_status === 'settled') {
                $lease->update([
                    'status' => $lease->end_date->isPast() ? 'expired' : 'active',
                    'financial_status' => 'active',
                    'financially_settled_at' => null,
                ]);
            }
            $this->event($lease, 'payment_reversed', $fromStatus, $lease->status, [
                'payment_id' => $payment->id,
                'amount' => (float) $payment->amount,
                'reason' => $reason,
            ], $userId);

            return $this->summary($lease->fresh());
        });
    }

    public function release(VehicleLease $lease, array $data, string $userId): array
    {
        return DB::transaction(function () use ($lease, $data, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            if (!in_array($lease->status, ['active', 'expired', 'closure_pending'], true) || $lease->release()->exists()) {
                throw ValidationException::withMessages(['lease' => ['Only an open, unreleased contract can record a physical vehicle release.']]);
            }
            if (in_array($lease->contract_type, ['vehicle_loan', 'hire_purchase'], true)
                && !in_array($data['release_type'], ['repossession', 'voluntary_surrender'], true)) {
                throw ValidationException::withMessages([
                    'release_type' => ['Bank loans and hire-purchase contracts use finance closure after settlement; only repossession or voluntary surrender is a physical release.'],
                ]);
            }
            $outstanding = $this->outstanding($lease);
            $termination = round((float) ($data['termination_charge'] ?? 0), 2);
            $depositCredit = round((float) ($data['deposit_credit'] ?? 0), 2);
            if ($depositCredit > (float) $lease->deposit_paid_amount) {
                throw ValidationException::withMessages(['deposit_credit' => ['Deposit credit cannot exceed the refundable deposit actually recorded as paid.']]);
            }
            $net = round($outstanding + $termination - $depositCredit, 2);
            $zeroSettlement = abs($net) < 0.01;
            $release = VehicleLeaseRelease::create([
                ...$data,
                'vehicle_lease_id' => $lease->id,
                'outstanding_amount' => $outstanding,
                'termination_charge' => $termination,
                'deposit_credit' => $depositCredit,
                'net_settlement_amount' => $net,
                'settlement_status' => $zeroSettlement ? 'settled' : 'pending',
                'settlement_direction' => $zeroSettlement ? 'none' : null,
                'settlement_amount' => $zeroSettlement ? 0 : null,
                'settlement_method' => $zeroSettlement ? 'not_applicable' : null,
                'settlement_reference' => $zeroSettlement ? 'ZERO-' . $lease->lease_number : null,
                'settled_at' => $zeroSettlement ? $data['effective_at'] : null,
                'settled_by' => $zeroSettlement ? $userId : null,
                'approved_by' => $userId,
            ]);
            $from = $lease->status;
            $lease->update([
                'status' => 'released',
                'completed_at' => $data['effective_at'],
                'financial_status' => $zeroSettlement ? 'settled' : $lease->financial_status,
                'financially_settled_at' => $zeroSettlement ? $data['effective_at'] : $lease->financially_settled_at,
            ]);
            $lease->vehicle()->update([
                'agreement_status' => 'ended',
                'availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_OFFLINE->value,
                'handover_mileage' => $data['odometer'] ?? $lease->vehicle?->handover_mileage,
                'handover_at' => $data['effective_at'],
                'handover_location' => $data['location'] ?? null,
                'handover_notes' => $data['condition_notes'] ?? $data['reason'],
            ]);
            $this->event($lease, 'released', $from, 'released', [
                'release_id' => $release->id, 'release_type' => $release->release_type,
                'outstanding_amount' => $outstanding, 'net_settlement_amount' => $net,
                'reason' => $release->reason,
                'owner_id_unchanged' => $lease->vehicle->owner_id,
            ], $userId);
            return $this->summary($lease->fresh());
        });
    }

    public function settleRelease(VehicleLease $lease, array $data, string $userId): array
    {
        return DB::transaction(function () use ($lease, $data, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            $release = $lease->release()->lockForUpdate()->first();
            if (!$release) {
                throw ValidationException::withMessages(['lease' => ['This lease has no release record to settle.']]);
            }
            $duplicate = VehicleLeaseRelease::query()
                ->where('settlement_idempotency_key', $data['idempotency_key'])
                ->first();
            if ($duplicate) {
                if ($duplicate->id !== $release->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This settlement key belongs to another vehicle release.'],
                    ]);
                }
                $sameRequest = round((float) $duplicate->settlement_amount, 2) === round((float) $data['amount'], 2)
                    && $duplicate->settlement_direction === $data['direction']
                    && $duplicate->settlement_method === $data['payment_method']
                    && $duplicate->settled_at?->equalTo(Carbon::parse($data['settled_at']))
                    && $duplicate->settlement_reference === $data['settlement_reference']
                    && ($duplicate->notes ?: null) === ($data['notes'] ?? null);
                if (!$sameRequest) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This settlement key was already used with different settlement details.'],
                    ]);
                }

                return $this->summary($lease);
            }
            if ($release->settlement_status === 'settled') {
                throw ValidationException::withMessages(['lease' => ['This release settlement is already recorded.']]);
            }
            $net = round((float) $release->net_settlement_amount, 2);
            $expectedDirection = $net > 0 ? 'payable_to_provider' : 'receivable_from_provider';
            if ($data['direction'] !== $expectedDirection) {
                throw ValidationException::withMessages([
                    'direction' => ["This release must be recorded as {$expectedDirection}."],
                ]);
            }
            if (abs(round((float) $data['amount'], 2) - abs($net)) >= 0.01) {
                throw ValidationException::withMessages([
                    'amount' => ['The settlement amount must equal the approved net release settlement.'],
                ]);
            }
            $release->update([
                'settlement_status' => 'settled',
                'settlement_direction' => $data['direction'],
                'settlement_amount' => round((float) $data['amount'], 2),
                'settlement_method' => $data['payment_method'],
                'settlement_idempotency_key' => $data['idempotency_key'],
                'settled_at' => $data['settled_at'],
                'settled_by' => $userId,
                'settlement_reference' => $data['settlement_reference'],
                'notes' => $data['notes'] ?? $release->notes,
            ]);
            $lease->update([
                'financial_status' => 'settled',
                'financially_settled_at' => $data['settled_at'],
            ]);
            $this->event($lease, 'release_settled', 'released', 'released', [
                'release_id' => $release->id,
                'net_settlement_amount' => (float) $release->net_settlement_amount,
                'settlement_direction' => $release->settlement_direction,
                'settlement_amount' => (float) $release->settlement_amount,
                'settlement_method' => $release->settlement_method,
                'settlement_reference' => $release->settlement_reference,
                'settled_at' => $release->settled_at?->toISOString(),
            ], $userId);

            return $this->summary($lease->fresh());
        });
    }

    public function closeFinance(VehicleLease $lease, array $data, string $userId): array
    {
        return DB::transaction(function () use ($lease, $data, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            if ($lease->contract_type === 'operating_lease') {
                throw ValidationException::withMessages(['lease' => ['An operating lease must be physically released/returned, not finance-closed.']]);
            }
            if ($lease->financial_status !== 'settled' || $lease->status !== 'closure_pending') {
                throw ValidationException::withMessages(['lease' => ['The complete finance amount must be settled before closure.']]);
            }
            if ($lease->ownership_transfer_required) {
                throw ValidationException::withMessages(['lease' => ['This contract requires an explicit ownership transfer instead of a no-change closure.']]);
            }
            if ($lease->financially_settled_at
                && Carbon::parse($data['closed_at'])->lt($lease->financially_settled_at)) {
                throw ValidationException::withMessages([
                    'closed_at' => ['Finance closure cannot be dated before the final payment was settled.'],
                ]);
            }
            $document = $this->verifiedClosureDocument($lease, $data['closure_document_id']);
            $lease->update([
                'status' => 'completed',
                'completed_at' => $data['closed_at'],
                'closed_at' => $data['closed_at'],
                'closure_type' => 'finance_discharged',
                'closure_reference' => $data['closure_reference'],
                'closure_document_id' => $document->id,
                'closure_notes' => $data['notes'] ?? null,
                'closed_by' => $userId,
            ]);
            $lease->vehicle()->update([
                'agreement_status' => 'ended',
            ]);
            $this->event($lease, 'finance_closed', 'closure_pending', 'completed', [
                'closure_reference' => $lease->closure_reference,
                'closure_document_id' => $document->id,
                'owner_id_unchanged' => $lease->vehicle->owner_id,
                'ownership_type_unchanged' => $lease->vehicle->ownership_type,
                'availability_unchanged' => $lease->vehicle->availability_status,
            ], $userId);

            return $this->summary($lease->fresh());
        });
    }

    public function transferOwnership(VehicleLease $lease, array $data, string $userId): array
    {
        return DB::transaction(function () use ($lease, $data, $userId) {
            $lease = VehicleLease::query()->lockForUpdate()->findOrFail($lease->id);
            if ($lease->financial_status !== 'settled' || $lease->status !== 'closure_pending') {
                throw ValidationException::withMessages(['lease' => ['Finance must be fully settled before recording ownership transfer.']]);
            }
            if (!$lease->ownership_transfer_required) {
                throw ValidationException::withMessages(['lease' => ['This contract is configured to close without an ownership transfer.']]);
            }
            $document = $this->verifiedClosureDocument($lease, $data['closure_document_id']);
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($lease->vehicle_id);
            $fromOwnerId = $vehicle->owner_id;
            $fromOwnershipType = $vehicle->ownership_type ?: 'company_owned';
            $toOwnerId = $data['new_owner_id'] ?? null;
            $toOwnershipType = $data['new_ownership_type'];
            if ($lease->financially_settled_at
                && Carbon::parse($data['effective_at'])->lt($lease->financially_settled_at)) {
                throw ValidationException::withMessages([
                    'effective_at' => ['Ownership transfer cannot be dated before the final payment was settled.'],
                ]);
            }
            if ($fromOwnerId === $toOwnerId && $fromOwnershipType === $toOwnershipType) {
                throw ValidationException::withMessages([
                    'new_owner_id' => ['The selected legal owner and ownership classification are already current.'],
                ]);
            }

            VehicleOwnershipHistory::create([
                'vehicle_id' => $vehicle->id,
                'vehicle_lease_id' => $lease->id,
                'document_id' => $document->id,
                'from_owner_id' => $fromOwnerId,
                'to_owner_id' => $toOwnerId,
                'from_ownership_type' => $fromOwnershipType,
                'to_ownership_type' => $toOwnershipType,
                'transfer_type' => $data['transfer_type'],
                'effective_at' => $data['effective_at'],
                'reference' => $data['reference'],
                'notes' => $data['notes'] ?? null,
                'performed_by' => $userId,
            ]);
            $vehicle->update([
                'owner_id' => $toOwnerId,
                'ownership_type' => $toOwnershipType,
                'agreement_status' => 'ended',
            ]);
            $lease->update([
                'status' => 'completed',
                'completed_at' => $data['effective_at'],
                'closed_at' => $data['effective_at'],
                'closure_type' => $data['transfer_type'],
                'closure_reference' => $data['reference'],
                'closure_document_id' => $document->id,
                'closure_notes' => $data['notes'] ?? null,
                'closed_by' => $userId,
            ]);
            $this->event($lease, 'ownership_transferred', 'closure_pending', 'completed', [
                'from_owner_id' => $fromOwnerId,
                'to_owner_id' => $vehicle->owner_id,
                'from_ownership_type' => $fromOwnershipType,
                'to_ownership_type' => $vehicle->ownership_type,
                'transfer_reference' => $data['reference'],
                'closure_document_id' => $document->id,
                'availability_unchanged' => $vehicle->availability_status,
            ], $userId);

            return $this->summary($lease->fresh());
        });
    }

    public function renew(VehicleLease $previousLease, array $data, ?string $userId): array
    {
        return DB::transaction(function () use ($previousLease, $data, $userId) {
            $previousLease = VehicleLease::query()->lockForUpdate()->findOrFail($previousLease->id);
            if (!in_array($previousLease->status, ['released', 'completed'], true)) {
                throw ValidationException::withMessages(['lease' => ['Only a physically released or finance-closed contract can be renewed.']]);
            }
            $newLease = $this->create($previousLease->vehicle, $data, $userId);
            $this->event($previousLease, 'renewal_created', $previousLease->status, $previousLease->status, [
                'renewal_lease_id' => $newLease->id,
                'renewal_lease_number' => $newLease->lease_number,
            ], $userId);
            $this->event($newLease, 'renewed_from', 'draft', 'draft', [
                'previous_lease_id' => $previousLease->id,
                'previous_lease_number' => $previousLease->lease_number,
            ], $userId);

            return $this->summary($newLease->fresh());
        });
    }

    public function summary(VehicleLease $lease): array
    {
        $lease->load([
            'vehicle.owner.user',
            'financeProvider',
            'ownerAtStart.user',
            'schedules.allocations',
            'payments.allocations',
            'release',
            'events.performer:id,first_name,last_name',
            'ownershipHistory.fromOwner.user',
            'ownershipHistory.toOwner.user',
            'vehicle.ownershipHistory.fromOwner.user',
            'vehicle.ownershipHistory.toOwner.user',
            'vehicle.ownershipHistory.document',
            'documents',
        ]);
        $schedule = $lease->schedules->sortBy('sequence')->map(function ($item) {
            $paid = round((float) $item->allocations->whereNull('reversed_at')->sum('amount'), 2);
            $balance = max(0, round((float) $item->amount_due - $paid, 2));
            $status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : ($item->due_date->lt(today()) ? 'overdue' : 'scheduled'));
            return [
                'id' => $item->id, 'sequence' => $item->sequence,
                'due_date' => $item->due_date->toDateString(),
                'amount_due' => (float) $item->amount_due, 'paid_amount' => $paid,
                'balance_amount' => $balance, 'status' => $status,
            ];
        })->values();
        $scheduled = round((float) $schedule->sum('amount_due'), 2);
        $paid = round((float) $lease->payments->where('status', 'recorded')->sum('amount'), 2);
        $contractualScheduleBalance = max(0, round($scheduled - $paid, 2));
        $releaseSettlementPending = $lease->release
            && $lease->release->settlement_status !== 'settled';
        $releaseNet = round((float) ($lease->release?->net_settlement_amount ?? 0), 2);
        $contractualOverdue = round((float) $schedule->where('status', 'overdue')->sum('balance_amount'), 2);
        $financiallyClosed = $lease->status === 'released' || $lease->financial_status === 'settled';
        $openPayable = $lease->status === 'released'
            ? ($releaseSettlementPending ? max(0, $releaseNet) : 0)
            : ($lease->financial_status === 'settled' ? 0 : $contractualScheduleBalance);
        $openReceivable = $lease->status === 'released' && $releaseSettlementPending
            ? abs(min(0, $releaseNet))
            : 0;
        return [
            'lease' => $lease,
            'financial_summary' => [
                'scheduled_amount' => $scheduled, 'paid_amount' => $paid,
                'contractual_schedule_balance' => $contractualScheduleBalance,
                'outstanding_amount' => $openPayable,
                'release_receivable_amount' => $openReceivable,
                'contractual_overdue_amount' => $contractualOverdue,
                'overdue_amount' => $financiallyClosed ? 0 : $contractualOverdue,
                'refundable_deposit' => (float) $lease->refundable_deposit,
                'deposit_paid_amount' => (float) $lease->deposit_paid_amount,
                'deposit_available_for_release' => max(0, round((float) $lease->deposit_paid_amount - (float) ($lease->release?->deposit_credit ?? 0), 2)),
                'monthly_run_rate' => $lease->financial_status === 'active'
                    && in_array($lease->status, ['active', 'expired'], true)
                    ? min($this->monthlyCommitment($lease), max(0, round($scheduled - $paid, 2)))
                    : 0,
                'next_due' => $financiallyClosed
                    ? null
                    : $schedule->whereIn('status', ['overdue', 'scheduled', 'partially_paid'])->first(),
            ],
            'schedule' => $schedule,
            'payments' => $lease->payments->sortByDesc('paid_date')->values(),
            'release' => $lease->release,
            'history' => $lease->events,
            'ownership_history' => $lease->vehicle->ownershipHistory,
            'next_action' => $this->nextAction($lease, $openPayable),
        ];
    }

    private function generateSchedule(VehicleLease $lease, ?string $userId): void
    {
        if ($lease->schedules()->exists()) return;
        $months = match ($lease->payment_frequency) {
            'monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12,
            default => 1,
        };
        $principalRemaining = round((float) $lease->financed_amount, 2);
        $balloonPrincipal = min(round((float) $lease->balloon_payment, 2), $principalRemaining);
        $amortizingPrincipal = max(0, round($principalRemaining - $balloonPrincipal, 2));
        $regularPrincipal = round($amortizingPrincipal / (int) $lease->installment_count, 2);
        $firstDueDate = $lease->first_payment_date->copy();
        for ($sequence = 1; $sequence <= (int) $lease->installment_count; $sequence++) {
            $dueDate = $firstDueDate->copy()->addMonthsNoOverflow($months * ($sequence - 1));
            $amount = (float) $lease->installment_amount + ($sequence === (int) $lease->installment_count ? (float) $lease->balloon_payment : 0);
            $principal = $sequence === (int) $lease->installment_count
                ? $principalRemaining
                : min($principalRemaining, $regularPrincipal);
            VehicleLeaseSchedule::create([
                'vehicle_lease_id' => $lease->id, 'sequence' => $sequence,
                'due_date' => $dueDate->toDateString(), 'principal_amount' => $principal,
                'interest_amount' => max(0, round($amount - $principal, 2)),
                'amount_due' => $amount, 'status' => 'scheduled', 'created_user_id' => $userId,
            ]);
            $principalRemaining = max(0, round($principalRemaining - $principal, 2));
        }
    }

    private function allocate(VehicleLease $lease, VehicleLeasePayment $payment, float $amount, ?string $userId): void
    {
        $remaining = $amount;
        $lease->schedules()->withSum([
            'allocations as allocations_sum_amount' => fn ($query) => $query->whereNull('reversed_at'),
        ], 'amount')->orderBy('due_date')->lockForUpdate()->get()
            ->each(function ($schedule) use (&$remaining, $payment, $userId) {
                if ($remaining <= 0) return false;
                $balance = max(0, round((float) $schedule->amount_due - (float) ($schedule->allocations_sum_amount ?? 0), 2));
                if ($balance <= 0) return;
                $allocated = min($remaining, $balance);
                VehicleLeasePaymentAllocation::create([
                    'vehicle_lease_payment_id' => $payment->id,
                    'vehicle_lease_schedule_id' => $schedule->id,
                    'amount' => $allocated, 'allocated_at' => now(), 'created_user_id' => $userId,
                ]);
                $schedule->update([
                    'status' => round((float) ($schedule->allocations_sum_amount ?? 0) + $allocated, 2) >= (float) $schedule->amount_due
                        ? 'paid' : 'partially_paid',
                ]);
                $remaining = round($remaining - $allocated, 2);
            });
    }

    private function outstanding(VehicleLease $lease): float
    {
        $scheduled = (float) $lease->schedules()->sum('amount_due');
        $paid = (float) $lease->payments()->where('status', 'recorded')->sum('amount');
        return max(0, round($scheduled - $paid, 2));
    }

    private function validateFinancialTerms(array $data): void
    {
        $scheduled = round(
            (float) $data['installment_amount'] * (int) $data['installment_count']
            + (float) ($data['balloon_payment'] ?? 0),
            2
        );
        if ($scheduled < round((float) $data['financed_amount'], 2)) {
            throw ValidationException::withMessages([
                'installment_amount' => ['The instalment schedule plus balloon payment cannot be less than the financed amount.'],
            ]);
        }
        $contractDeposit = round((float) ($data['refundable_deposit'] ?? 0), 2);
        $paidDeposit = round((float) ($data['deposit_paid_amount'] ?? 0), 2);
        if ($paidDeposit > $contractDeposit) {
            throw ValidationException::withMessages([
                'deposit_paid_amount' => ['The paid refundable deposit cannot exceed the contractual deposit.'],
            ]);
        }
        if ($paidDeposit > 0 && (empty($data['deposit_paid_date']) || empty($data['deposit_payment_reference']))) {
            throw ValidationException::withMessages([
                'deposit_paid_date' => ['Paid date and payment reference are required when a refundable deposit is recorded as paid.'],
            ]);
        }
        $months = match ($data['payment_frequency']) {
            'monthly' => 1,
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
        };
        $lastDueDate = Carbon::parse($data['first_payment_date'])
            ->addMonthsNoOverflow($months * ((int) $data['installment_count'] - 1));
        if ($lastDueDate->gt(Carbon::parse($data['end_date']))) {
            throw ValidationException::withMessages([
                'installment_count' => ['The final scheduled instalment date cannot fall after the lease end date.'],
            ]);
        }
    }

    private function validateVehicleContractCompatibility(Vehicle $vehicle, array $data): void
    {
        if (!VehicleFinanceProvider::query()->whereKey($data['finance_provider_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'finance_provider_id' => ['Select an active finance provider.'],
            ]);
        }
        if ($data['contract_type'] === 'operating_lease' && !$vehicle->owner_id) {
            throw ValidationException::withMessages([
                'contract_type' => ['An operating lease requires the actual external vehicle owner to be recorded on the vehicle first.'],
            ]);
        }
        if ($data['contract_type'] === 'operating_lease'
            && !in_array($vehicle->ownership_type, ['leased_asset', 'rented_asset'], true)) {
            throw ValidationException::withMessages([
                'contract_type' => ['Set the vehicle ownership type to Leased Asset or Rented Asset before creating an operating lease.'],
            ]);
        }
    }

    private function monthlyCommitment(VehicleLease $lease): float
    {
        $months = match ($lease->payment_frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
            default => 1,
        };

        return round((float) $lease->installment_amount / $months, 2);
    }

    private function verifiedClosureDocument(VehicleLease $lease, string $documentId): Document
    {
        $document = Document::query()
            ->whereKey($documentId)
            ->where('documentable_type', VehicleLease::class)
            ->where('documentable_id', $lease->id)
            ->first();
        if (!$document || $document->status !== 'verified') {
            throw ValidationException::withMessages([
                'closure_document_id' => ['Select a verified settlement, discharge, or title-transfer document attached to this contract.'],
            ]);
        }

        return $document;
    }

    private function nextAction(VehicleLease $lease, float $outstanding): string
    {
        if ($lease->status === 'draft') {
            return 'Review ownership snapshot and finance terms, then activate.';
        }
        if ($lease->status === 'released') {
            return $lease->release?->settlement_status === 'settled'
                ? 'Physical release and settlement are complete.'
                : 'Record the physical release settlement.';
        }
        if ($lease->status === 'completed') {
            return 'Finance contract is closed; vehicle ownership and availability are unchanged unless an explicit transfer was recorded.';
        }
        if ($outstanding > 0) {
            return 'Continue recording instalment payments.';
        }
        if ($lease->contract_type === 'operating_lease') {
            return 'Finance is settled; await approved physical return/release.';
        }
        if ($lease->ownership_transfer_required) {
            return 'Verify the discharge/title document and record explicit ownership transfer.';
        }

        return 'Verify the discharge document and close finance without changing vehicle ownership.';
    }

    private function event(VehicleLease $lease, string $type, ?string $from, ?string $to, array $data, ?string $userId): void
    {
        VehicleLeaseEvent::create([
            'vehicle_lease_id' => $lease->id, 'event_type' => $type,
            'from_status' => $from, 'to_status' => $to, 'data' => $data,
            'occurred_at' => now(), 'performed_by' => $userId,
        ]);
    }

    private function nextLeaseNumber(): string
    {
        return 'VLS-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) Str::uuid(), 0, 6));
    }
}
