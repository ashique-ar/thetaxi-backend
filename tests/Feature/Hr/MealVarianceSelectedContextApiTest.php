<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('requires the selected Staff company before handling a meal variance', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.meals.reconcile');
    config(['hr.features.meals' => true]);

    $firstStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $company = Company::create(['name' => 'Selected meal variance company']);
    $staff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $company->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);

    $program = (string) Str::uuid();
    $vendor = (string) Str::uuid();
    $menu = (string) Str::uuid();
    $order = (string) Str::uuid();
    DB::table('hr_meal_program_versions')->insert([
        'id' => $program, 'company_id' => $company->id, 'location_code' => 'HQ', 'code' => 'LUNCH', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'service_days' => '[]', 'meal_period' => 'lunch', 'order_cutoff_local' => '10:00',
        'eligibility_rules' => '{}', 'subsidy_rules' => '{}', 'cancellation_rules' => '{}', 'employee_recovery_mode' => 'payroll',
        'finance_treatment' => '{}', 'effective_from' => today()->toDateString(), 'status' => 'active',
        'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_meal_vendors')->insert([
        'id' => $vendor, 'company_id' => $company->id, 'code' => 'V1', 'name' => 'Vendor', 'location_coverage' => '[]',
        'currency' => 'LKR', 'effective_from' => today()->toDateString(), 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_meal_menus')->insert([
        'id' => $menu, 'program_version_id' => $program, 'vendor_id' => $vendor, 'code' => 'M1', 'name' => 'Menu',
        'available_from' => today()->toDateString(), 'available_until' => today()->addDay()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_meal_vendor_orders')->insert([
        'id' => $order, 'company_id' => $company->id, 'program_version_id' => $program, 'vendor_id' => $vendor,
        'menu_id' => $menu, 'service_date' => today()->toDateString(), 'location_code' => 'HQ', 'delivery_due_at' => now(),
        'status' => 'draft', 'subtotal' => 0, 'tax_amount' => 0, 'delivery_fee' => 0, 'total_amount' => 0,
        'employer_amount' => 0, 'employee_amount' => 0, 'currency' => 'LKR', 'order_checksum' => str_repeat('a', 64),
        'prepared_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload = ['reason_code' => 'delivery', 'reason' => 'Delivery variance', 'lines' => [[
        'adjustment_type' => 'wastage', 'quantity_delta' => 0, 'supplier_amount_delta' => 0,
        'employer_amount_delta' => 0, 'employee_amount_delta' => 0, 'resolution_snapshot' => [], 'reason' => 'Recorded',
    ]]];

    actingAs($admin, 'api')->postJson("/api/hr/meals/vendor-orders/{$order}/variances", $payload)->assertForbidden();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson("/api/hr/meals/vendor-orders/{$order}/variances", $payload)->assertStatus(409);

    expect(DB::table('hr_meal_variance_cases')->exists())->toBeFalse()
        ->and($firstStaff->company_id)->not->toBe($company->id);
});
