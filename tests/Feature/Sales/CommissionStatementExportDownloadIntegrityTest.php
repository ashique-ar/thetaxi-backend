<?php

use App\Models\Sales\SalesCommissionStatementExport;
use App\Models\Sales\SalesProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('downloads only the scoped export path after verifying its saved size and checksum', function () {
    [$actor, $company] = hr_seed_admin_actor(['name' => 'Export Integrity Company']);
    $actor->givePermissionTo(['sales.commission-statements.view-all', 'sales.commission-statements.export']);
    $staffId = DB::table('staff')->where('user_id', $actor->id)->value('id');
    $profileId = (string) Str::uuid();
    $cycleId = (string) Str::uuid();
    $assignmentId = (string) Str::uuid();
    $statementId = (string) Str::uuid();
    $now = now();

    DB::table('sales_profiles')->insert([
        'id' => $profileId, 'company_id' => $company->id, 'staff_id' => $staffId,
        'sales_code' => 'EXPORT-INTEGRITY', 'effective_from' => $now->copy()->subYear(),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_cycle_versions')->insert([
        'id' => $cycleId, 'company_id' => $company->id, 'code' => 'EXPORT-CYCLE', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'earning_period_rule' => 'monthly', 'cutoff_day' => 25,
        'finalization_day' => 26, 'approval_deadline_day' => 27, 'settlement_day' => 28,
        'holiday_rule' => 'next_business_day', 'effective_from' => $now->copy()->subYear(),
        'status' => 'approved', 'created_by' => $actor->id, 'approved_by' => $actor->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_cycle_assignments')->insert([
        'id' => $assignmentId, 'company_id' => $company->id, 'cycle_version_id' => $cycleId,
        'scope_type' => 'company', 'precedence' => 1, 'effective_from' => $now->copy()->subYear(),
        'status' => 'approved', 'created_by' => $actor->id, 'approved_by' => $actor->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_statements')->insert([
        'id' => $statementId, 'company_id' => $company->id, 'staff_id' => $staffId,
        'sales_profile_id' => $profileId, 'cycle_version_id' => $cycleId, 'cycle_assignment_id' => $assignmentId,
        'statement_number' => 'SCS-EXPORT-INTEGRITY', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'cutoff_at' => $now, 'timezone' => 'Asia/Colombo', 'status' => 'approved', 'prepared_by' => $actor->id,
        'prepared_at' => $now, 'generation_idempotency_key' => (string) Str::uuid(),
        'generation_payload_checksum' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now,
    ]);

    $exportId = (string) Str::uuid();
    $content = "statement,amount\nSCS-EXPORT-INTEGRITY,1250\n";
    $path = 'commission-statements/'.$company->id.'/'.$staffId.'/'.$exportId.'.csv';
    Storage::fake('sales_private');
    Storage::disk('sales_private')->put($path, $content);
    $export = SalesCommissionStatementExport::query()->create([
        'id' => $exportId, 'statement_id' => $statementId, 'format' => 'csv', 'disk' => 'sales_private',
        'path' => $path, 'file_name' => 'SCS-EXPORT-INTEGRITY.csv', 'file_checksum' => hash('sha256', $content),
        'file_size' => strlen($content), 'generated_by' => $actor->id, 'generated_at' => $now,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $download = actingAs($actor, 'api')->get('/api/sales/commission-statement-exports/'.$export->id.'/download');
    ob_start();
    $download->sendContent();
    $streamedContent = ob_get_clean();
    expect($download->getStatusCode())->toBe(200)
        ->and($download->headers->get('content-disposition'))->toContain($export->file_name)
        ->and($streamedContent)->toBe($content);
    expect(DB::table('sales_commission_statement_exports')->where('id', $export->id)->value('last_downloaded_by'))
        ->toBe($actor->id);

    Storage::disk('sales_private')->put($path, 'tampered export bytes');
    DB::table('sales_commission_statement_exports')->where('id', $export->id)->update([
        'last_downloaded_at' => null, 'last_downloaded_by' => null,
    ]);
    actingAs($actor, 'api')->get('/api/sales/commission-statement-exports/'.$export->id.'/download')->assertStatus(409);
    expect(DB::table('sales_commission_statement_exports')->where('id', $export->id)->value('last_downloaded_at'))->toBeNull();

    Storage::disk('sales_private')->put($path, $content);
    DB::table('sales_commission_statement_exports')->where('id', $export->id)
        ->update(['path' => 'commission-statements/other-company/other-staff/private.csv']);
    actingAs($actor, 'api')->get('/api/sales/commission-statement-exports/'.$export->id.'/download')->assertNotFound();
});
