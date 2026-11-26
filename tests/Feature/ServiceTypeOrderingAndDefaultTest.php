<?php

use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Resources\ServiceTypeResource;
use App\Models\Service\ServiceType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    activity()->disableLogging();
    Schema::dropIfExists('website_settings');
    Schema::create('website_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::dropIfExists('business_settings');
    Schema::create('business_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::dropIfExists('service_types');
    Schema::create('service_types', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('code');
        $table->string('context')->default('public');
        $table->integer('priority')->default(0);
        $table->boolean('is_default')->default(false);
        $table->boolean('is_portal_hidden')->default(false);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_internal')->default(false);
        $table->json('form_config')->nullable();
        $table->json('workflow_config')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

it('reorders service types and sets a single default per context', function () {
    $pub1 = ServiceType::create(['id' => (string) Str::uuid(), 'name' => 'Airport', 'code' => 'airport', 'context' => 'public', 'priority' => 1, 'is_default' => true, 'is_active' => true]);
    $pub2 = ServiceType::create(['id' => (string) Str::uuid(), 'name' => 'Ride Now', 'code' => 'ride_now', 'context' => 'public', 'priority' => 2, 'is_default' => false, 'is_active' => true]);
    $corp1 = ServiceType::create(['id' => (string) Str::uuid(), 'name' => 'Corp Transfer', 'code' => 'corp_transfer', 'context' => 'corporate', 'priority' => 1, 'is_default' => false, 'is_active' => true]);
    $corp2 = ServiceType::create(['id' => (string) Str::uuid(), 'name' => 'Corp Day', 'code' => 'corp_day', 'context' => 'corporate', 'priority' => 2, 'is_default' => true, 'is_active' => true]);

    $controller = app(ServiceTypeController::class);

    $reorderReq = Request::create('/api/service-types/reorder', 'POST', [
        'service_type_ids' => [$pub2->id, $pub1->id],
        'context' => 'public',
    ]);
    $controller->reorder($reorderReq);

    expect($pub2->fresh()->priority)->toBe(1)
        ->and($pub1->fresh()->priority)->toBe(2);

    $controller->setDefault($pub2->fresh());

    expect($pub2->fresh()->is_default)->toBeTrue()
        ->and($pub1->fresh()->is_default)->toBeFalse()
        ->and($corp2->fresh()->is_default)->toBeTrue();

    $controller->setDefault($corp1->fresh());

    expect($corp1->fresh()->is_default)->toBeTrue()
        ->and($corp2->fresh()->is_default)->toBeFalse()
        ->and($pub2->fresh()->is_default)->toBeTrue();

    $resource = (new ServiceTypeResource($pub2->fresh()))->toArray(new Request());
    expect($resource['is_default'])->toBeTrue();

    $controller->toggleVisibility($pub2->fresh());
    expect($pub2->fresh()->is_portal_hidden)->toBeTrue()
        ->and($pub2->fresh()->is_default)->toBeFalse();

    $controller->toggleVisibility($pub2->fresh());
    expect($pub2->fresh()->is_portal_hidden)->toBeFalse();
});
