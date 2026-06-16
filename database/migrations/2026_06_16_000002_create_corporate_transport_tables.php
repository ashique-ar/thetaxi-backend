<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_transport_programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->string('name');
            $table->string('status', 30)->default('active');
            $table->string('timezone', 64)->default('Asia/Colombo');
            $table->string('default_opt_mode', 20)->default('opt_out');
            $table->unsignedInteger('cutoff_minutes_before')->default(720);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('description')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['corporate_id', 'status', 'is_active'], 'corp_transport_program_scope_idx');
        });

        Schema::create('corporate_transport_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('program_id');
            $table->string('name', 120);
            $table->time('pickup_time');
            $table->time('dropoff_time')->nullable();
            $table->json('operating_days')->nullable();
            $table->unsignedInteger('cutoff_minutes_before')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_id', 'is_active']);
        });

        Schema::create('corporate_transport_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('program_id');
            $table->string('name', 150);
            $table->string('direction', 30)->default('pickup');
            $table->uuid('service_type_id')->nullable();
            $table->uuid('vehicle_group_id')->nullable();
            $table->json('origin_location')->nullable();
            $table->json('destination_location')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_id', 'direction', 'is_active'], 'corp_transport_route_scope_idx');
        });

        Schema::create('corporate_transport_route_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('route_id');
            $table->uuid('shift_id')->nullable();
            $table->uuid('corporate_employee_id');
            $table->uuid('pickup_location_id')->nullable();
            $table->uuid('dropoff_location_id')->nullable();
            $table->unsignedInteger('route_order')->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['route_id', 'shift_id', 'is_active'], 'corp_transport_member_route_shift_idx');
            $table->index(['corporate_employee_id', 'is_active'], 'corp_transport_member_employee_idx');
        });

        Schema::create('corporate_transport_participations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('program_id');
            $table->uuid('route_id');
            $table->uuid('shift_id');
            $table->uuid('corporate_employee_id');
            $table->date('service_date');
            $table->string('direction', 30)->default('pickup');
            $table->string('status', 40)->default('included');
            $table->uuid('booking_id')->nullable();
            $table->uuid('booking_item_id')->nullable();
            $table->string('booking_stop_id')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->uuid('changed_by_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_id', 'service_date'], 'corp_transport_participation_program_date_idx');
            $table->index(['route_id', 'shift_id', 'service_date', 'direction'], 'corp_transport_participation_occurrence_idx');
            $table->index(['corporate_employee_id', 'service_date'], 'corp_transport_participation_employee_date_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX corp_transport_participation_unique_active ON corporate_transport_participations (route_id, shift_id, corporate_employee_id, service_date, direction) WHERE deleted_at IS NULL'
        );

        Schema::create('corporate_transport_generation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('program_id')->nullable();
            $table->uuid('route_id')->nullable();
            $table->uuid('shift_id')->nullable();
            $table->date('service_date')->nullable();
            $table->string('direction', 30)->nullable();
            $table->uuid('booking_id')->nullable();
            $table->string('status', 40);
            $table->unsignedInteger('included_count')->default(0);
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_id', 'service_date', 'status'], 'corp_transport_generation_log_scope_idx');
        });

        $permissions = ['staff-transport.view', 'staff-transport.manage', 'staff-transport.override', 'staff-transport.generate'];
        $now = now();
        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'api',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['Corporate_Master_Admin', 'Transport_Coordinator'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'api')->value('id');
            if ($roleId) {
                DB::table('permissions')
                    ->where('guard_name', 'api')
                    ->whereIn('name', $permissions)
                    ->pluck('id')
                    ->each(fn ($permissionId) => DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]));
            }
        }

        $employeeRoleId = DB::table('roles')->where('name', 'Corporate_Employee')->where('guard_name', 'api')->value('id');
        $viewPermissionId = DB::table('permissions')->where('name', 'staff-transport.view')->where('guard_name', 'api')->value('id');
        if ($employeeRoleId && $viewPermissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $viewPermissionId,
                'role_id' => $employeeRoleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_transport_generation_logs');
        Schema::dropIfExists('corporate_transport_participations');
        Schema::dropIfExists('corporate_transport_route_members');
        Schema::dropIfExists('corporate_transport_routes');
        Schema::dropIfExists('corporate_transport_shifts');
        Schema::dropIfExists('corporate_transport_programs');

        DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', ['staff-transport.view', 'staff-transport.manage', 'staff-transport.override', 'staff-transport.generate'])
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
