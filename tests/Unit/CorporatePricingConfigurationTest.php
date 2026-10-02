<?php

use App\Models\Corporate\Corporate;
use App\Services\CorporateService;
use Database\Seeders\CorporateDynamicPricingSeeder;
use Illuminate\Validation\ValidationException;


it('defines every corporate pricing source explicitly', function () {
    $mapping = (new ReflectionClass(CorporateDynamicPricingSeeder::class))
        ->getReflectionConstant('SERVICE_MAP')
        ->getValue();

    expect(collect($mapping)->mapWithKeys(
        fn (array $service) => [$service['code'] => $service['sources']]
    )->all())->toBe([
        'on_meter' => ['day_rental'],
        'ride_now' => ['ride_now'],
        'point_to_point' => ['point_to_point', 'potint_to_point'],
        'hourly_package' => ['ride_now'],
        'tour' => ['ride_now'],
        'special_night' => ['ride_now'],
        'airport_transfer' => ['airport_transfers'],
        'transport_contract' => ['corporate'],
    ]);
});

it('does not expose corporate keywords in corporate service codes or names', function () {
    $mapping = (new ReflectionClass(CorporateDynamicPricingSeeder::class))
        ->getReflectionConstant('SERVICE_MAP')
        ->getValue();

    foreach ($mapping as $service) {
        expect(strtolower($service['code']))->not->toContain('corp');
        expect(strtolower($service['name']))->not->toContain('corporate');
    }
});

it('does not compare UUID pricing owner IDs with empty strings', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );
    $cloneDefinitions = Str::between(
        $source,
        'private function cloneDefinitions(',
        'private function cloneVehiclePricing('
    );

    expect($cloneDefinitions)
        ->toContain("->whereNull('owner_id')")
        ->not->toContain("orWhere('owner_id', '')");
});

it('allows sparse public pricing overrides while requiring slab coverage per group', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toMatch('/\'vehicle_group_pricing\',\s*\'slab_definition_id\',[\s\S]*?\$userId,\s*false\s*\)/')
        ->toMatch('/\'vehicle_group_common_rate_pricing\',\s*\'common_rate_definition_id\',[\s\S]*?\$userId,\s*false\s*\)/')
        ->toContain('bool $requireEveryPrice = true')
        ->toContain('if (!$requireEveryPrice)')
        ->toContain("\$pricingTable === 'vehicle_group_pricing' && \$definitionMap !== [] && \$clonedCount === 0");
});

it('allows calculation graphs that use common rates without active slabs', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toContain('if ($calculationMap === [] || ($slabMap === [] && $commonRateMap === []))')
        ->not->toContain('if ($slabMap === [] || $commonRateMap === [] || $calculationMap === [])');
});

it('recovers inactive public calculation definitions for the corporate copy', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );
    $cloneDefinitions = Str::between(
        $source,
        'private function cloneDefinitions(',
        'private function cloneVehiclePricing('
    );

    expect($cloneDefinitions)
        ->toContain("\$table === 'vehicle_pricing_calculation_definitions' && \$rows->isEmpty()")
        ->toContain("\$payload['status'] = 'active'");
});

it('generates a calculation from available public pricing inputs when none exists', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toContain('private function createCalculationFromPricingInputs(')
        ->toContain('private function calculationInputForRate(')
        ->toContain("\$calculationMap = \$this->createCalculationFromPricingInputs(")
        ->toContain("'vehicle_delivery_rate_per_km' => 'delivery_distance'")
        ->toContain("'extra_km_rate' => 'extra_km'")
        ->toContain("'insurance_rate', 'driver_allowance' => 'number_of_days'");
});

it('keeps definitions common and scopes only vehicle prices per corporate', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );
    $cloneDefinitions = Str::between(
        $source,
        'private function cloneDefinitions(',
        'private function cloneVehiclePricing('
    );
    $cloneVehiclePricing = Str::between(
        $source,
        'private function cloneVehiclePricing(',
        'private function clonePayload('
    );

    expect($cloneDefinitions)
        ->toContain("\$payload['owner_type'] = null")
        ->toContain("\$payload['owner_id'] = null");
    expect($cloneVehiclePricing)
        ->toContain("\$payload['owner_type'] = 'corporate'")
        ->toContain("\$payload['owner_id'] = \$corporateId");
});

it('builds shared slabs and corporate rates from packages when definitions are missing', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toContain('private function createPackageBasedSlabsAndPricing(')
        ->toContain("\$slabMap = \$this->createPackageBasedSlabsAndPricing(")
        ->toContain("'owner_type' => null")
        ->toContain("'owner_type' => 'corporate'")
        ->toContain("'rate' => \$packageRate->base_rate");
});

it('clones each source service form and complete package graph', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toContain('$this->cloneServiceBehavior($source, $target')
        ->toContain('private function resolveServiceFormConfig(')
        ->toContain('private function persistServiceFormConfig(')
        ->toContain('private function cloneServicePackages(')
        ->toContain('private function clonePackageRates(')
        ->toContain('private function clonePackageReturnRules(')
        ->toContain('does not contain a form configuration to clone')
        ->toContain('DefaultFormConfigService::getDefaults($source->code)')
        ->toContain('Uuid::uuid5(');
});

it('keeps package-less public services package-less in the corporate catalog', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );
    $clonePackages = Str::between(
        $source,
        'private function cloneServicePackages(',
        'private function clonePackageRates('
    );

    expect($clonePackages)
        ->not->toContain('does not contain an active package to clone')
        ->toContain("\$stalePackages->update(['deleted_at' => now(), 'is_active' => false");
});

it('uses UUID-aware writes for corporate vehicle-group assignments', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toContain("\$this->syncCorporateVehicleGroups(\$corporate->id, \$vehicleGroups->pluck('id')->all())")
        ->not->toContain("\$corporate->vehicleGroups()->sync(");
});

it('rejects an assignment with no vehicle groups', function () {
    $corporate = \Mockery::mock(Corporate::class);

    (new CorporateService())->assignVehicleGroups($corporate, []);
})->throws(ValidationException::class, 'At least one unique active vehicle group must be assigned.');

it('does not impose a fixed vehicle-group assignment count', function () {
    $source = file_get_contents(base_path('app/Services/CorporateService.php'));

    expect($source)
        ->toContain('if (empty($vehicleGroupIds))')
        ->not->toContain('count($vehicleGroupIds) !== 3')
        ->not->toContain('Exactly three unique active vehicle groups must be assigned.');
});

it('keeps the corporate assignment catalogue aligned with active-group validation', function () {
    $portalService = file_get_contents(
        base_path('../portal-thetaxi/src/app/modules/corporate/services/corporate.service.ts')
    );
    $controller = file_get_contents(
        base_path('app/Http/Controllers/Api/Corporate/CorporateController.php')
    );

    expect($portalService)
        ->toContain("{ per_page: 200, is_active: true }");
    expect($controller)
        ->toContain("'vehicle_group_ids.*.exists' => 'A selected vehicle group is inactive, deleted, or no longer available.'");
});

it('excludes inactive assignments from the corporate booking relationship', function () {
    $source = file_get_contents(base_path('app/Models/Corporate/Corporate.php'));
    $relationship = Str::between($source, 'public function vehicleGroups()', 'public function serviceTypes()');

    expect($relationship)
        ->toContain("belongsToMany(VehicleGroup::class, 'corporate_vehicle_groups'")
        ->not->toContain('withInactive()');
});

it('protects active vehicle-group removal inside the service transaction', function () {
    $service = file_get_contents(base_path('app/Services/CorporateService.php'));
    $removal = Str::between($service, 'public function removeVehicleGroup(', 'public function assignServiceTypes(');
    $controller = file_get_contents(base_path('app/Http/Controllers/Api/Corporate/CorporateController.php'));
    $controllerRemoval = Str::between($controller, 'public function removeVehicleGroup(', 'public function serviceTypes(');

    expect($removal)
        ->toContain('DB::transaction(')
        ->toContain('->lockForUpdate()')
        ->toContain("->where('is_active', true)")
        ->toContain("->whereNull('deleted_at')")
        ->toContain('A corporate must retain at least one active vehicle group.')
        ->toContain('The vehicle group is not assigned to this corporate.');
    expect($controllerRemoval)->not->toContain('->count() <= 1');
});
