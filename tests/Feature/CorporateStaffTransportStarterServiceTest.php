<?php

use App\Models\Corporate\Corporate;
use App\Services\CorporateStaffTransportStarterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    activity()->disableLogging();
    foreach (['corporate_transport_routes', 'corporate_transport_shifts', 'corporate_transport_programs', 'corporates'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('corporates', function (Blueprint $table) {
        $table->uuid('id')->primary(); $table->string('name'); $table->string('contact_email')->nullable();
        $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('corporate_transport_programs', function (Blueprint $table) {
        $table->uuid('id')->primary(); $table->uuid('corporate_id'); $table->string('name'); $table->string('status');
        $table->string('timezone'); $table->string('default_opt_mode'); $table->unsignedInteger('cutoff_minutes_before');
        $table->date('start_date')->nullable(); $table->date('end_date')->nullable(); $table->text('description')->nullable();
        $table->json('settings')->nullable(); $table->boolean('is_active'); $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable(); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('corporate_transport_shifts', function (Blueprint $table) {
        $table->uuid('id')->primary(); $table->uuid('program_id'); $table->string('name'); $table->time('pickup_time');
        $table->time('dropoff_time')->nullable(); $table->json('operating_days')->nullable(); $table->unsignedInteger('cutoff_minutes_before')->nullable();
        $table->boolean('is_active'); $table->uuid('created_user_id')->nullable(); $table->uuid('updated_user_id')->nullable();
        $table->timestamps(); $table->softDeletes();
    });
    Schema::create('corporate_transport_routes', function (Blueprint $table) {
        $table->uuid('id')->primary(); $table->uuid('program_id'); $table->string('name'); $table->string('direction');
        $table->uuid('service_type_id')->nullable(); $table->uuid('vehicle_group_id')->nullable(); $table->json('origin_location')->nullable();
        $table->json('destination_location')->nullable(); $table->unsignedInteger('capacity')->nullable(); $table->boolean('is_active');
        $table->uuid('created_user_id')->nullable(); $table->uuid('updated_user_id')->nullable(); $table->timestamps(); $table->softDeletes();
    });
});

it('provisions editable starter data once and preserves company changes on rerun', function () {
    $corporate = Corporate::create([
        'name' => 'Starter Test Corporate',
        'contact_email' => 'starter@example.test',
        'is_active' => true,
    ]);

    $starter = app(CorporateStaffTransportStarterService::class);
    $firstResult = $starter->provision($corporate);
    $program = $corporate->transportPrograms()->firstOrFail();
    $program->update(['name' => 'Our Employee Transport']);

    $result = $starter->provision($corporate);
    $program->refresh();

    expect($firstResult)->toBe(['program_created' => true, 'shifts_created' => 4, 'routes_created' => 2])
        ->and($corporate->transportPrograms()->count())->toBe(1)
        ->and($program->name)->toBe('Our Employee Transport')
        ->and($program->shifts()->count())->toBe(4)
        ->and(\App\Models\Corporate\CorporateTransportRoute::withInactive()->where('program_id', $program->id)->count())->toBe(2)
        ->and($program->routes()->count())->toBe(2)
        ->and($result)->toBe([
            'program_created' => false,
            'shifts_created' => 0,
            'routes_created' => 0,
        ]);
});
