<?php

use App\Models\User;
use App\Models\UserContext;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Http\Middleware\EnsureCorporateContext;
use App\Services\CorporatePortalPermission;
use App\Services\PermissionEvaluator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses only the selected corporate context when a user belongs to two companies', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->softDeletes();
    });
    Schema::create('user_contexts', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('context_type');
        $table->uuid('context_id');
        $table->boolean('is_active');
        $table->softDeletes();
    });
    DB::statement(
        'CREATE UNIQUE INDEX user_contexts_user_type_context_unique '
        . 'ON user_contexts (user_id, context_type, context_id) WHERE deleted_at IS NULL'
    );
    Schema::create('roles', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    Schema::create('permissions', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    Schema::create('user_context_roles', function (Blueprint $table) {
        $table->increments('id');
        $table->uuid('user_context_id');
        $table->unsignedBigInteger('role_id');
    });
    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('permission_id');
    });
    Schema::create('user_context_permission_grants', function (Blueprint $table) {
        $table->uuid('user_context_id');
        $table->unsignedBigInteger('permission_id');
    });
    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->string('model_id');
    });
    Schema::create('corporates', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->boolean('is_active');
        $table->softDeletes();
    });
    Schema::create('corporate_employees', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('corporate_id');
        $table->boolean('is_active');
        $table->softDeletes();
    });

    $userId = '10000000-0000-4000-8000-000000000001';
    $financeContext = '20000000-0000-4000-8000-000000000001';
    $employeeContext = '20000000-0000-4000-8000-000000000002';
    DB::table('users')->insert(['id' => $userId]);
    DB::table('user_contexts')->insert([
        ['id' => $financeContext, 'user_id' => $userId, 'context_type' => 'corporate', 'context_id' => '30000000-0000-4000-8000-000000000001', 'is_active' => true],
        ['id' => $employeeContext, 'user_id' => $userId, 'context_type' => 'corporate', 'context_id' => '30000000-0000-4000-8000-000000000002', 'is_active' => true],
    ]);
    DB::table('corporates')->insert([
        ['id' => '30000000-0000-4000-8000-000000000001', 'is_active' => true],
        ['id' => '30000000-0000-4000-8000-000000000002', 'is_active' => true],
    ]);
    DB::table('corporate_employees')->insert([
        ['id' => '30000000-0000-4000-8000-000000000001', 'user_id' => $userId, 'corporate_id' => '30000000-0000-4000-8000-000000000001', 'is_active' => true],
        ['id' => '30000000-0000-4000-8000-000000000002', 'user_id' => $userId, 'corporate_id' => '30000000-0000-4000-8000-000000000002', 'is_active' => true],
    ]);
    DB::table('roles')->insert([
        ['id' => 1, 'name' => 'Corporate_Master_Admin', 'guard_name' => 'api'],
        ['id' => 2, 'name' => 'Corporate_Employee', 'guard_name' => 'api'],
    ]);
    DB::table('permissions')->insert([
        ['id' => 1, 'name' => 'view_payments', 'guard_name' => 'api'],
        ['id' => 2, 'name' => 'manage_employees', 'guard_name' => 'api'],
        ['id' => 3, 'name' => 'manage-roles', 'guard_name' => 'api'],
        ['id' => 4, 'name' => 'global_only_permission', 'guard_name' => 'api'],
        ['id' => 5, 'name' => 'bookings.create', 'guard_name' => 'api'],
        ['id' => 6, 'name' => 'bookings.view', 'guard_name' => 'api'],
    ]);
    DB::table('user_context_roles')->insert([
        ['user_context_id' => $financeContext, 'role_id' => 1],
        ['user_context_id' => $employeeContext, 'role_id' => 2],
    ]);
    DB::table('role_has_permissions')->insert(['role_id' => 1, 'permission_id' => 1]);
    DB::table('model_has_permissions')->insert([
        ['permission_id' => 1, 'model_type' => User::class, 'model_id' => $userId],
        ['permission_id' => 4, 'model_type' => User::class, 'model_id' => $userId],
    ]);
    DB::table('user_context_permission_grants')->insert([
        ['user_context_id' => $financeContext, 'permission_id' => 2],
        ['user_context_id' => $financeContext, 'permission_id' => 3],
        ['user_context_id' => $financeContext, 'permission_id' => 5],
        ['user_context_id' => $financeContext, 'permission_id' => 6],
    ]);

    $user = new User();
    $user->id = $userId;
    $user->exists = true;
    $evaluator = app(PermissionEvaluator::class);

    expect($evaluator->userHasAnyForRequest($user, ['view_payments'], 'corporate', $financeContext))->toBeTrue()
        ->and($evaluator->userHasAnyForRequest($user, ['view_payments'], 'corporate', $employeeContext))->toBeFalse();

    $context = new UserContext(['id' => $financeContext, 'is_active' => true]);
    $context->setRelation('user', $user);
    $employee = new CorporateEmployee(['is_active' => true]);
    $employee->setRelation('userContext', $context);
    $employee->setRelation('corporate', new Corporate(['is_active' => true]));

    expect(CorporatePortalPermission::employeeAllows($employee, 'manage_employees'))->toBeTrue()
        ->and(CorporatePortalPermission::employeeAllows($employee, 'view_payments'))->toBeTrue()
        ->and(CorporatePortalPermission::employeeAllows($employee, 'manage-roles'))->toBeTrue()
        ->and(CorporatePortalPermission::employeeAllowsAny($employee, ['create_bookings', 'bookings.create']))->toBeTrue();

    $context->id = $employeeContext;
    expect(CorporatePortalPermission::employeeAllows($employee, 'manage_employees'))->toBeFalse();

    $request = Request::create('/api/corporate/workspace', 'GET', [], [], [], [
        'HTTP_X_ACTIVE_CONTEXT_TYPE' => 'corporate',
        'HTTP_X_ACTIVE_CONTEXT_ID' => $financeContext,
    ]);
    $request->setUserResolver(fn () => $user);
    (new EnsureCorporateContext())->handle($request, function (Request $request) use ($financeContext, $employeeContext, $user, $evaluator): \Symfony\Component\HttpFoundation\Response {
        expect($request->attributes->get('resolved_active_context_type'))->toBe('corporate')
            ->and($request->attributes->get('resolved_active_context_id'))->toBe($financeContext)
            ->and(CorporatePortalPermission::allows($request, 'view_payments'))->toBeTrue()
            ->and(CorporatePortalPermission::allows($request, 'manage_employees'))->toBeTrue()
            ->and(CorporatePortalPermission::allows($request, 'bookings.view'))->toBeTrue()
            ->and(CorporatePortalPermission::allows($request, 'global_only_permission'))->toBeFalse()
            ->and($evaluator->userHasAnyForRequest(
                $user,
                ['global_only_permission'],
                $request->attributes->get('resolved_active_context_type'),
                $request->attributes->get('resolved_active_context_id'),
            ))->toBeFalse();

        return response('', 204);
    });

    expect(fn () => DB::table('user_contexts')->insert([
        'id' => '20000000-0000-4000-8000-000000000003',
        'user_id' => $userId,
        'context_type' => 'corporate',
        'context_id' => '30000000-0000-4000-8000-000000000001',
        'is_active' => true,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
