<?php

namespace Tests\Unit;

use App\Http\Requests\Vehicle\VehiclePricingSlabDefinition\CreateVehiclePricingSlabDefinitionRequest;
use App\Http\Requests\Vehicle\VehiclePricingSlabDefinition\UpdateVehiclePricingSlabDefinitionRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class VehiclePricingSlabDefinitionRequestTest extends TestCase
{
    #[DataProvider('durationUnits')]
    public function test_minimum_is_required_for_each_duration_unit(
        string $type,
        string $minimumKey,
        string $maximumKey
    ): void {
        foreach ($this->requests() as $request) {
            $validator = $this->validatorFor($request, ['type' => $type]);

            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey($minimumKey, $validator->errors()->toArray());
            $this->assertArrayNotHasKey($maximumKey, $validator->errors()->toArray());
        }
    }

    #[DataProvider('durationUnits')]
    public function test_maximum_is_optional_for_an_open_ended_slab(
        string $type,
        string $minimumKey,
        string $maximumKey
    ): void {
        foreach ($this->requests() as $request) {
            $validator = $this->validatorFor($request, [
                'type' => $type,
                $minimumKey => 10,
                $maximumKey => null,
            ]);

            $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
        }
    }

    #[DataProvider('durationUnits')]
    public function test_maximum_cannot_be_lower_than_minimum(
        string $type,
        string $minimumKey,
        string $maximumKey
    ): void {
        foreach ($this->requests() as $request) {
            $validator = $this->validatorFor($request, [
                'type' => $type,
                $minimumKey => 10,
                $maximumKey => 9,
            ]);

            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey($maximumKey, $validator->errors()->toArray());
        }
    }

    public function test_inactive_duration_fields_are_cleared_before_validation(): void
    {
        foreach ($this->requests() as $request) {
            $this->prepare($request, [
                'type' => 'hours',
                'min_minutes' => 15,
                'max_minutes' => 30,
                'min_hours' => 2,
                'max_hours' => null,
                'min_days' => 1,
                'max_days' => 3,
            ]);

            $this->assertSame(2, $request->input('min_hours'));
            $this->assertNull($request->input('max_hours'));
            $this->assertNull($request->input('min_minutes'));
            $this->assertNull($request->input('max_minutes'));
            $this->assertNull($request->input('min_days'));
            $this->assertNull($request->input('max_days'));
        }
    }

    #[DataProvider('legacyPricingTypes')]
    public function test_legacy_pricing_types_preserve_optional_hour_package_boundaries(string $type): void
    {
        foreach ($this->requests() as $request) {
            $this->prepare($request, [
                'type' => $type,
                'min_minutes' => 15,
                'max_minutes' => 30,
                'min_hours' => 6,
                'max_hours' => 8,
                'min_days' => 1,
                'max_days' => 2,
            ]);

            $this->assertSame(6, $request->input('min_hours'));
            $this->assertSame(8, $request->input('max_hours'));
            $this->assertNull($request->input('min_minutes'));
            $this->assertNull($request->input('max_minutes'));
            $this->assertNull($request->input('min_days'));
            $this->assertNull($request->input('max_days'));
        }
    }

    #[DataProvider('legacyPricingTypes')]
    public function test_legacy_pricing_types_allow_one_range_less_fallback(string $type): void
    {
        foreach ($this->requests() as $request) {
            $validator = $this->validatorFor($request, [
                'type' => $type,
                'min_hours' => null,
                'max_hours' => null,
            ]);

            $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
        }
    }

    public static function durationUnits(): array
    {
        return [
            'minutes' => ['minutes', 'min_minutes', 'max_minutes'],
            'hours' => ['hours', 'min_hours', 'max_hours'],
            'days' => ['days', 'min_days', 'max_days'],
            'per day' => ['per_day', 'min_days', 'max_days'],
        ];
    }

    public static function legacyPricingTypes(): array
    {
        return [
            'flat rate' => ['flat_rate'],
            'per km' => ['per_km'],
        ];
    }

    /** @return array<int, FormRequest> */
    private function requests(): array
    {
        return [
            new CreateVehiclePricingSlabDefinitionRequest(),
            new UpdateVehiclePricingSlabDefinitionRequest(),
        ];
    }

    private function validatorFor(FormRequest $request, array $payload): Validator
    {
        $this->prepare($request, $payload);
        $durationKeys = array_flip([
            'type',
            'min_minutes',
            'max_minutes',
            'min_hours',
            'max_hours',
            'min_days',
            'max_days',
        ]);
        $rules = array_intersect_key($request->rules(), $durationKeys);
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));

        return $factory->make($request->all(), $rules);
    }

    private function prepare(FormRequest $request, array $payload): void
    {
        $request->initialize([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']);
        (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
    }
}
