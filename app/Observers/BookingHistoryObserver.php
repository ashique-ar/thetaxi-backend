<?php

namespace App\Observers;

use App\Models\Booking\BookingHistoryEvent;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingStatus;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Vehicle\VehicleAssignment;
use App\Services\BookingHistoryService;
use Illuminate\Database\Eloquent\Model;

class BookingHistoryObserver
{
    private array $before = [];

    public function updating(Model $model): void
    {
        $this->before[spl_object_id($model)] = $model->getOriginal();
    }

    public function created(Model $model): void
    {
        if ($model instanceof BookingActivity) {
            app(BookingHistoryService::class)->recordBookingActivity($model);
            return;
        }
        if ($model instanceof BookingStatus) {
            app(BookingHistoryService::class)->recordBookingStatus($model);
            return;
        }
        if ($model instanceof FinancialAuditEvent) {
            app(BookingHistoryService::class)->recordFinancialAuditEvent($model);
            return;
        }
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        if ($model instanceof BookingActivity && !app(BookingHistoryService::class)->shouldRecordBookingActivity($model)) {
            unset($this->before[spl_object_id($model)]);
            return;
        }
        $this->record($model, 'updated', $this->before[spl_object_id($model)] ?? []);
        unset($this->before[spl_object_id($model)]);
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof BookingActivity && !app(BookingHistoryService::class)->shouldRecordBookingActivity($model)) return;
        $this->record($model, 'deleted', $model->getOriginal());
    }

    public function restored(Model $model): void
    {
        if ($model instanceof BookingActivity && !app(BookingHistoryService::class)->shouldRecordBookingActivity($model)) return;
        $this->record($model, 'restored', $model->getOriginal());
    }

    private function record(Model $model, string $operation, array $before = []): void
    {
        if ($model instanceof BookingHistoryEvent) return;
        app(BookingHistoryService::class)->recordModelChange($model, $operation, $before);
    }
}
