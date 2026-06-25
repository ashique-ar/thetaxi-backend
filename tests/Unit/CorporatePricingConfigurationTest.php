<?php

use App\Models\Corporate\Corporate;
use App\Services\CorporateService;
use Database\Seeders\CorporateDynamicPricingSeeder;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

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

it('clones each source service form and complete package graph', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toContain('$this->cloneServiceBehavior($source, $target')
        ->toContain('private function cloneServiceFormConfig(')
        ->toContain('private function cloneServicePackages(')
        ->toContain('private function clonePackageRates(')
        ->toContain('private function clonePackageReturnRules(')
        ->toContain('does not contain a form configuration to clone')
        ->toContain('does not contain an active package to clone')
        ->toContain('Uuid::uuid5(');
});

it('uses UUID-aware writes for corporate vehicle-group assignments', function () {
    $source = file_get_contents(
        base_path('database/seeders/CorporateDynamicPricingSeeder.php')
    );

    expect($source)
        ->toContain("\$this->syncCorporateVehicleGroups(\$corporate->id, \$vehicleGroups->pluck('id')->all())")
        ->not->toContain("\$corporate->vehicleGroups()->sync(");
});

it('rejects assignments that do not contain three unique groups', function (array $ids) {
    $corporate = \Mockery::mock(Corporate::class);

    (new CorporateService())->assignVehicleGroups($corporate, $ids);
})->with([
    'fewer than three' => [['11111111-1111-1111-1111-111111111111']],
    'duplicate values' => [[
        '11111111-1111-1111-1111-111111111111',
        '11111111-1111-1111-1111-111111111111',
        '22222222-2222-2222-2222-222222222222',
    ]],
    'more than three' => [[
        '11111111-1111-1111-1111-111111111111',
        '22222222-2222-2222-2222-222222222222',
        '33333333-3333-3333-3333-333333333333',
        '44444444-4444-4444-4444-444444444444',
    ]],
])->throws(ValidationException::class);
