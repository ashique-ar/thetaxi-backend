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
        'corp_on_meter' => ['day_rental'],
        'corp_ride_now' => ['ride_now'],
        'corp_point_to_point' => ['point_to_point', 'potint_to_point'],
        'corp_hourly_package' => ['ride_now'],
        'corp_tour' => ['ride_now'],
        'corp_special_night' => ['ride_now'],
        'corp_airport_transfer' => ['airport_transfers'],
        'corp_ctc' => ['corporate'],
    ]);
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
