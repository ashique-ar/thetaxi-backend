<?php

use App\Models\NonSoftDeletableModel;

it('does not apply soft-delete or user-tracking behavior to commission tables without those columns', function () {
    $models = [
        App\Models\Sales\SalesCommissionAccountingDelivery::class,
        App\Models\Sales\SalesCommissionBusinessCalendar::class,
        App\Models\Sales\SalesCommissionBusinessCalendarDate::class,
        App\Models\Sales\SalesCommissionCycleAssignment::class,
        App\Models\Sales\SalesCommissionCycleVersion::class,
        App\Models\Sales\SalesCommissionDecision::class,
        App\Models\Sales\SalesCommissionDispute::class,
        App\Models\Sales\SalesCommissionPayout::class,
        App\Models\Sales\SalesCommissionPayoutAllocation::class,
        App\Models\Sales\SalesCommissionPlanAssignment::class,
        App\Models\Sales\SalesCommissionPlanFamily::class,
        App\Models\Sales\SalesCommissionPlanTier::class,
        App\Models\Sales\SalesCommissionPlanVersion::class,
        App\Models\Sales\SalesCommissionRecoveryCase::class,
        App\Models\Sales\SalesCommissionRecoveryDecision::class,
        App\Models\Sales\SalesCommissionStaffOverride::class,
        App\Models\Sales\SalesCommissionStatement::class,
        App\Models\Sales\SalesCommissionStatementAdjustment::class,
        App\Models\Sales\SalesCommissionStatementEvent::class,
        App\Models\Sales\SalesCommissionStatementExport::class,
        App\Models\Sales\SalesCommissionStatementLine::class,
    ];

    foreach ($models as $model) {
        expect(is_subclass_of($model, NonSoftDeletableModel::class))->toBeTrue();
        expect(is_subclass_of($model, App\Models\BaseModel::class))->toBeFalse();
    }

    $base = file_get_contents(app_path('Models/NonSoftDeletableModel.php'));
    expect($base)->toContain('extends Model', 'performDeleteOnModel')
        ->not->toContain('SoftDeletes', 'deleted_at', 'created_user_id', 'updated_user_id');
});
