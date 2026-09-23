<?php

use App\Models\User;
use App\Services\PermissionEvaluator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses only the selected corporate context when a user belongs to two companies', function () {
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

    $userId = '10000000-0000-4000-8000-000000000001';
    $financeContext = '20000000-0000-4000-8000-000000000001';
    $employeeContext = '20000000-0000-4000-8000-000000000002';
    DB::table('user_contexts')->insert([
        ['id' => $financeContext, 'user_id' => $userId, 'context_type' => 'corporate', 'context_id' => '30000000-0000-4000-8000-000000000001', 'is_active' => true],
        ['id' => $employeeContext, 'user_id' => $userId, 'context_type' => 'corporate', 'context_id' => '30000000-0000-4000-8000-000000000002', 'is_active' => true],
    ]);
    DB::table('roles')->insert([
        ['id' => 1, 'name' => 'Corporate_Master_Admin', 'guard_name' => 'api'],
        ['id' => 2, 'name' => 'Corporate_Employee', 'guard_name' => 'api'],
    ]);
    DB::table('permissions')->insert(['id' => 1, 'name' => 'view_payments', 'guard_name' => 'api']);
    DB::table('user_context_roles')->insert([
        ['user_context_id' => $financeContext, 'role_id' => 1],
        ['user_context_id' => $employeeContext, 'role_id' => 2],
    ]);
    DB::table('role_has_permissions')->insert(['role_id' => 1, 'permission_id' => 1]);

    $user = new User();
    $user->id = $userId;
    $user->exists = true;
    $evaluator = app(PermissionEvaluator::class);

    expect($evaluator->userHasAnyForRequest($user, ['view_payments'], 'corporate', $financeContext))->toBeTrue()
        ->and($evaluator->userHasAnyForRequest($user, ['view_payments'], 'corporate', $employeeContext))->toBeFalse();
});
