<?php

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDailyResult;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Hr\Attendance\AttendanceRawEvent;
use App\Models\Hr\HrEmployeeNumberAlias;
use App\Models\Hr\HrEmployeeNumberSequence;
use App\Models\Hr\HrEmployeeRecord;
use App\Models\Hr\HrEmployeeTimelineEvent;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Hr\HrEpfEtfContributionPolicy;
use App\Models\Hr\HrGratuityPolicy;
use App\Models\Hr\HrPeopleDuplicateReview;
use App\Models\Hr\HrPeopleExport;
use App\Models\Hr\HrPeopleIdentityLink;
use App\Models\Hr\HrPeopleImportJob;
use App\Models\Hr\HrRehireCase;
use App\Models\Hr\HrReportingLine;
use App\Models\Hr\HrStaffProfileVersion;
use App\Models\Hr\Leave\LeaveBalanceEntry;
use App\Models\Hr\PayrollInputFact;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('aligns every HR model fillable field and delete scope with its migrated table', function () {
    $models = [
        HrEmployeeNumberAlias::class => [false, false],
        HrEmployeeNumberSequence::class => [false, false],
        HrEmployeeRecord::class => [false, false],
        HrEmployeeTimelineEvent::class => [false, false],
        HrEmploymentAssignment::class => [true, true],
        HrEmploymentSpell::class => [true, true],
        HrEpfEtfContributionPolicy::class => [true, true],
        HrGratuityPolicy::class => [true, true],
        HrPeopleDuplicateReview::class => [true, true],
        HrPeopleExport::class => [true, true],
        HrPeopleIdentityLink::class => [true, true],
        HrPeopleImportJob::class => [true, true],
        HrRehireCase::class => [false, false],
        HrReportingLine::class => [false, false],
        HrStaffProfileVersion::class => [false, false],
        LeaveBalanceEntry::class => [false, false],
        PayrollInputFact::class => [false, false],
        AttendanceConnector::class => [false, true],
        AttendanceDevice::class => [false, true],
        AttendanceRawEvent::class => [false, true],
        AttendanceDailyResult::class => [false, true],
    ];

    foreach ($models as $modelClass => [$usesSoftDeletes, $hasDeletedAt]) {
        $model = new $modelClass;
        $table = $model->getTable();

        expect(Schema::hasTable($table))->toBeTrue();
        foreach ($model->getFillable() as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("{$modelClass} fillable column {$column} is missing from {$table}.");
        }
        expect(Schema::hasColumn($table, 'created_at'))->toBeTrue()
            ->and(Schema::hasColumn($table, 'updated_at'))->toBeTrue()
            ->and(Schema::hasColumn($table, 'deleted_at'))->toBe($hasDeletedAt)
            ->and(array_key_exists(SoftDeletingScope::class, $model->getGlobalScopes()))->toBe($usesSoftDeletes);
    }
});
