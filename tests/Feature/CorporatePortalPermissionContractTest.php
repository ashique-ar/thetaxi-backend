<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

it('protects corporate profile, staff transport management and personal calendar endpoints separately', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/corporate/workspace', 'permission:corporate.view'],
        ['GET', 'api/corporate/booking-configuration', 'ensure.corporate'],
        ['GET', 'api/corporate/profile', 'permission:manage_employees'],
        ['GET', 'api/corporate/service-types', 'permission:create_bookings|bookings.create|staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/vehicle-groups', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/departments', 'permission:manage_departments|manage_divisions|manage_employees|view_reports|view_all_bookings'],
        ['GET', 'api/corporate/staff-transport/employees', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/bookings/stats', 'permission:view_reports|corporate.view|bookings.view|bookings.create|create_bookings|view_all_bookings'],
        ['GET', 'api/corporate/bookings/my', 'permission:bookings.view|bookings.create|create_bookings'],
        ['POST', 'api/corporate/bookings', 'permission:create_bookings|bookings.create'],
        ['GET', 'api/corporate/bookings/{id}', 'permission:bookings.view|bookings.create|create_bookings|view_all_bookings|approve_bookings'],
        ['POST', 'api/corporate/bookings/{id}/recurring/cancel', 'permission:bookings.create|create_bookings|view_all_bookings'],
        ['GET', 'api/corporate/staff-transport/programs', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings'],
        ['GET', 'api/corporate/staff-transport/my-calendar', 'permission:staff-transport.view|staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings|staff-transport.self.view'],
        ['POST', 'api/corporate/staff-transport/my-calendar/{participation}/status', 'permission:staff-transport.self.view|staff-transport.manage|staff-transport.override'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission)
            ->and($route->gatherMiddleware())->toContain('ensure.corporate');
    }
});

it('protects monthly corporate billing routes with internal context and matching grants', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $contracts = [
        ['GET', 'api/admin/corporates/{corporate}/billing/terms', 'permission:corporates.view|corporates.manage'],
        ['POST', 'api/admin/corporates/{corporate}/billing/terms', 'permission:corporates.manage'],
        ['PATCH', 'api/admin/corporates/{corporate}/billing/terms/{term}/end', 'permission:corporates.manage'],
        ['POST', 'api/admin/corporates/{corporate}/billing/preview', 'permission:bookings.view'],
        ['POST', 'api/admin/corporates/{corporate}/billing/generate', 'permission:bookings.update'],
        ['POST', 'api/admin/corporates/{corporate}/billing/settlements/{settlement}/issue', 'permission:bookings.update'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = $routes->first(fn ($candidate) =>
            $candidate->uri() === $uri && in_array($method, $candidate->methods(), true)
        );

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission)
            ->and($route->gatherMiddleware())->toContain('ensure.internal');
    }
});

it('allows corporate employees with booking create permission to fetch corporate service types', function (): void {
    $createdSchema = false;
    if (DB::getDriverName() === 'sqlite' && ! Schema::hasTable('users')) {
        $createdSchema = true;
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->softDeletes();
        });
        Schema::create('user_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('context_type');
            $table->uuid('context_id');
            $table->boolean('is_active');
            $table->softDeletes();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('user_context_roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('user_context_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        Schema::create('user_context_permission_grants', function (Blueprint $table): void {
            $table->uuid('user_context_id');
            $table->unsignedBigInteger('permission_id');
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->string('model_id');
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->string('model_id');
        });
        Schema::create('corporates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->boolean('is_active');
            $table->softDeletes();
        });
        Schema::create('corporate_employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('corporate_id');
            $table->boolean('is_active');
            $table->softDeletes();
        });
        Schema::create('service_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->integer('priority')->nullable();
            $table->boolean('is_internal')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('corporate_service_types', function (Blueprint $table): void {
            $table->uuid('corporate_id');
            $table->uuid('service_type_id');
            $table->boolean('is_active');
            $table->timestamps();
        });
    }

    try {
        $corporateId = '30000000-0000-4000-8000-000000000001';
        $employeeUserId = '10000000-0000-4000-8000-000000000001';
        $employeeRecordId = '30000000-0000-4000-8000-000000000011';
        $employeeContextId = '20000000-0000-4000-8000-000000000001';
        $blockedUserId = '10000000-0000-4000-8000-000000000002';
        $blockedRecordId = '30000000-0000-4000-8000-000000000012';
        $blockedContextId = '20000000-0000-4000-8000-000000000002';
        $serviceTypeId = '40000000-0000-4000-8000-000000000001';

        DB::table('corporates')->insert(['id' => $corporateId, 'is_active' => true]);
        DB::table('users')->insert([
            ['id' => $employeeUserId],
            ['id' => $blockedUserId],
        ]);
        DB::table('corporate_employees')->insert([
            ['id' => $employeeRecordId, 'user_id' => $employeeUserId, 'corporate_id' => $corporateId, 'is_active' => true],
            ['id' => $blockedRecordId, 'user_id' => $blockedUserId, 'corporate_id' => $corporateId, 'is_active' => true],
        ]);
        DB::table('user_contexts')->insert([
            ['id' => $employeeContextId, 'user_id' => $employeeUserId, 'context_type' => 'corporate', 'context_id' => $employeeRecordId, 'is_active' => true],
            ['id' => $blockedContextId, 'user_id' => $blockedUserId, 'context_type' => 'corporate', 'context_id' => $blockedRecordId, 'is_active' => true],
        ]);
        $employeeRoleId = DB::table('roles')->insertGetId(['name' => 'Corporate_'.$corporateId.'_Employee', 'guard_name' => 'api']);
        $blockedRoleId = DB::table('roles')->insertGetId(['name' => 'Corporate_'.$corporateId.'_HR_Viewer', 'guard_name' => 'api']);
        $createBookingsPermId = DB::table('permissions')->where('name', 'create_bookings')->where('guard_name', 'api')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'create_bookings', 'guard_name' => 'api']);
        $bookingsCreatePermId = DB::table('permissions')->where('name', 'bookings.create')->where('guard_name', 'api')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'bookings.create', 'guard_name' => 'api']);
        $manageEmployeesPermId = DB::table('permissions')->where('name', 'manage_employees')->where('guard_name', 'api')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'manage_employees', 'guard_name' => 'api']);

        DB::table('user_context_roles')->insert([
            ['user_context_id' => $employeeContextId, 'role_id' => $employeeRoleId],
            ['user_context_id' => $blockedContextId, 'role_id' => $blockedRoleId],
        ]);
        DB::table('role_has_permissions')->insert([
            ['role_id' => $employeeRoleId, 'permission_id' => $createBookingsPermId],
            ['role_id' => $employeeRoleId, 'permission_id' => $bookingsCreatePermId],
            ['role_id' => $blockedRoleId, 'permission_id' => $manageEmployeesPermId],
        ]);
        DB::table('service_types')->insert([
            'id' => $serviceTypeId,
            'code' => 'corp-city-ride',
            'name' => 'Corporate City Ride',
            'priority' => 1,
            'is_internal' => false,
            'is_active' => true,
        ]);
        DB::table('corporate_service_types')->insert([
            'corporate_id' => $corporateId,
            'service_type_id' => $serviceTypeId,
            'is_active' => true,
        ]);

        $employeeUser = new User();
        $employeeUser->id = $employeeUserId;
        $employeeUser->exists = true;

        $this->actingAs($employeeUser, 'api')->getJson('/api/corporate/service-types', [
            'X-Active-Context-Type' => 'corporate',
            'X-Active-Context-Id' => $employeeContextId,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $serviceTypeId);

        $blockedUser = new User();
        $blockedUser->id = $blockedUserId;
        $blockedUser->exists = true;

        $this->actingAs($blockedUser, 'api')->getJson('/api/corporate/service-types', [
            'X-Active-Context-Type' => 'corporate',
            'X-Active-Context-Id' => $blockedContextId,
        ])->assertForbidden();
    } finally {
        if ($createdSchema) {
            foreach ([
                'corporate_service_types',
                'service_types',
                'corporate_employees',
                'corporates',
                'model_has_roles',
                'model_has_permissions',
                'user_context_permission_grants',
                'role_has_permissions',
                'user_context_roles',
                'permissions',
                'roles',
                'user_contexts',
                'users',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }
});
