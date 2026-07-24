<?php

use App\Models\Service\ServiceType;
use App\Services\Pricing\PricingContextPolicyService;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Schema::dropIfExists('service_packages');
    Schema::dropIfExists('service_types');
    Schema::create('service_types', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('code');
        $table->string('name');
        $table->string('slug')->nullable();
        $table->string('context')->default('portal');
        $table->string('owner_type')->default('');
        $table->string('owner_id')->default('');
        $table->integer('priority')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('service_packages', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('service_type_id');
        $table->string('code');
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function () {
    Schema::dropIfExists('service_packages');
    Schema::dropIfExists('service_types');
    Mockery::close();
});

function pricingPolicyForMode(string $mode): PricingContextPolicyService
{
    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')
        ->with(PricingContextPolicyService::SETTING_KEY, PricingContextPolicyService::MODE_INHERIT_WEBSITE)
        ->andReturn($mode);

    return new PricingContextPolicyService($settings);
}

it('uses Website as the live effective context for internal pricing by default', function () {
    $policy = pricingPolicyForMode(PricingContextPolicyService::MODE_INHERIT_WEBSITE);
    $request = Request::create('/pricing', 'POST', [
        'context' => 'portal',
        'pricing_context' => 'portal',
    ]);

    $policy->applyToRequest($request);

    expect($policy->effectiveContext('portal'))->toBe('public')
        ->and($policy->effectiveContext('corporate'))->toBe('corporate')
        ->and($request->input('context'))->toBe('public')
        ->and($request->input('pricing_context'))->toBe('public')
        ->and($policy->describe()['effective_contexts'])->toBe([
            'public' => 'public',
            'portal' => 'public',
            'corporate' => 'corporate',
        ]);
});

it('keeps Internal independent when separate management is selected', function () {
    $policy = pricingPolicyForMode(PricingContextPolicyService::MODE_SEPARATE);
    $request = Request::create('/service-types', 'GET', ['context' => 'all']);
    $policy->applyToRequest($request);

    expect($policy->effectiveContext('portal'))->toBe('portal')
        ->and($policy->internalUsesWebsitePricing())->toBeFalse()
        ->and($request->input('context'))->toBe('all');
});

it('maps a legacy Internal service type to its Website definition for calculation', function () {
    $websiteId = (string) Str::uuid();
    $internalId = (string) Str::uuid();
    $websitePackageId = (string) Str::uuid();
    $internalPackageId = (string) Str::uuid();

    DB::table('service_types')->insert([
        [
            'id' => $websiteId,
            'code' => 'hourly_hire',
            'name' => 'Hourly Hire',
            'slug' => 'hourly-hire',
            'context' => 'public',
            'owner_type' => '',
            'owner_id' => '',
            'priority' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $internalId,
            'code' => 'hourly_hire',
            'name' => 'Hourly Hire',
            'slug' => 'hourly-hire',
            'context' => 'portal',
            'owner_type' => '',
            'owner_id' => '',
            'priority' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('service_packages')->insert([
        [
            'id' => $websitePackageId,
            'service_type_id' => $websiteId,
            'code' => 'half_day',
            'name' => 'Half Day',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $internalPackageId,
            'service_type_id' => $internalId,
            'code' => 'half_day',
            'name' => 'Half Day',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $policy = pricingPolicyForMode(PricingContextPolicyService::MODE_INHERIT_WEBSITE);
    $normalized = $policy->normalizeCalculationParams([
            'service_type_id' => $internalId,
            'pricing_context' => 'portal',
            'package_id' => $internalPackageId,
        ]);
    $request = Request::create('/pricing', 'POST', [
        'service_type_id' => $internalId,
        'pricing_context' => 'portal',
        'service_package_id' => $internalPackageId,
        'applicable_context' => 'portal',
        'fallback_context' => 'portal',
    ]);
    $policy->applyToRequest($request);

    expect($normalized)->toMatchArray([
        'service_type_id' => $websiteId,
        'pricing_context' => 'public',
        'requested_pricing_context' => 'portal',
        'requested_service_type_id' => $internalId,
        'pricing_service_type_id' => $websiteId,
        'package_id' => $websitePackageId,
        'requested_service_package_id' => $internalPackageId,
        'pricing_service_package_id' => $websitePackageId,
    ])->and($policy->effectiveServiceType(ServiceType::findOrFail($internalId))->id)->toBe($websiteId)
        ->and($request->input('service_type_id'))->toBe($websiteId)
        ->and($request->input('service_package_id'))->toBe($websitePackageId)
        ->and($request->input('pricing_context'))->toBe('public')
        ->and($request->input('applicable_context'))->toBe('public')
        ->and($request->input('fallback_context'))->toBe('public');

    expect(fn () => $policy->assertServiceTypeIsWritable(ServiceType::findOrFail($internalId)))
        ->toThrow(HttpException::class);
});

it('fails safely when inherited Internal pricing has no Website definition', function () {
    $internalId = (string) Str::uuid();
    DB::table('service_types')->insert([
        'id' => $internalId,
        'code' => 'internal_only',
        'name' => 'Internal Only',
        'slug' => 'internal-only',
        'context' => 'portal',
        'owner_type' => '',
        'owner_id' => '',
        'priority' => 10,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $policy = pricingPolicyForMode(PricingContextPolicyService::MODE_INHERIT_WEBSITE);

    expect(fn () => $policy->normalizeCalculationParams([
        'service_type_id' => $internalId,
        'pricing_context' => 'portal',
    ]))->toThrow(HttpException::class);
});
