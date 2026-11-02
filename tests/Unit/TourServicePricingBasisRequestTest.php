<?php

namespace Tests\Unit;

use App\Http\Requests\ServiceType\CreateServiceTypeRequest;
use App\Http\Requests\ServiceType\UpdateServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Models\Service\ServiceType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TourServicePricingBasisRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('service_types', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->string('context');
            $table->string('slug')->nullable();
            $table->string('pricing_basis')->default('vehicle_group');
            $table->softDeletes();
        });
        Schema::create('booking_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('service_type_id');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
    }

    public function test_create_accepts_class_basis_and_does_not_expose_tour_sales_enablement(): void
    {
        $request = CreateServiceTypeRequest::create('/service-types', 'POST', [
            'code' => 'tour', 'name' => 'Tours', 'pricing_basis' => 'vehicle_class', 'tour_booking_enabled' => true,
        ]);
        $validator = $this->validator($request);

        self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
        self::assertSame('vehicle_class', $validator->validated()['pricing_basis'] ?? null);
        self::assertArrayNotHasKey('tour_booking_enabled', $validator->validated());
    }

    public function test_invalid_basis_is_rejected_for_create_and_update(): void
    {
        $requests = [
            CreateServiceTypeRequest::create('/service-types', 'POST', ['code' => 'tour', 'name' => 'Tours', 'pricing_basis' => 'cheapest_group']),
            $this->updateRequest(['pricing_basis' => 'cheapest_group']),
        ];
        foreach ($requests as $request) {
            $validator = $this->validator($request);
            self::assertFalse($validator->passes());
            self::assertArrayHasKey('pricing_basis', $validator->errors()->toArray());
        }
    }

    public function test_omitting_basis_from_an_update_preserves_the_existing_selection(): void
    {
        $request = $this->updateRequest(['description' => 'Updated description'], 'vehicle_class');
        $validator = $this->validator($request);

        self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
        self::assertArrayNotHasKey('pricing_basis', $validator->validated());
        self::assertSame('vehicle_class', $request->route('service_type')->pricing_basis);
    }

    public function test_unused_service_can_change_basis(): void
    {
        $validator = $this->validator($this->updateRequest(['pricing_basis' => 'vehicle_class']));

        self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
        self::assertSame('vehicle_class', $validator->validated()['pricing_basis'] ?? null);
    }

    public function test_historical_booking_prevents_changing_basis_even_if_deleted_and_inactive(): void
    {
        DB::table('booking_items')->insert([
            'id' => 'historical-item', 'service_type_id' => 'service-a', 'is_active' => false, 'deleted_at' => now(),
        ]);
        $validator = $this->validator($this->updateRequest(['pricing_basis' => 'vehicle_class']));

        self::assertFalse($validator->passes());
        self::assertArrayHasKey('pricing_basis', $validator->errors()->toArray());
    }

    public function test_existing_bookings_do_not_block_unchanged_basis_or_unrelated_updates(): void
    {
        DB::table('booking_items')->insert(['id' => 'existing-item', 'service_type_id' => 'service-a']);
        foreach ([['pricing_basis' => 'vehicle_class'], ['name' => 'Updated tour name']] as $payload) {
            $validator = $this->validator($this->updateRequest($payload, 'vehicle_class'));
            self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
        }
    }

    public function test_other_services_bookings_do_not_block_a_basis_change(): void
    {
        DB::table('booking_items')->insert(['id' => 'other-item', 'service_type_id' => 'service-b']);
        $validator = $this->validator($this->updateRequest(['pricing_basis' => 'vehicle_class']));

        self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
    }

    public function test_resource_defaults_legacy_services_to_group_and_returns_class_selection(): void
    {
        foreach ([null => 'vehicle_group', 'vehicle_class' => 'vehicle_class'] as $basis => $expected) {
            $service = new ServiceType();
            $service->forceFill(['id' => 'service-a', 'pricing_basis' => $basis ?: null]);
            $data = (new ServiceTypeResource($service))->toArray(Request::create('/service-types'));

            self::assertSame($expected, $data['pricing_basis'] ?? null);
        }
    }

    private function updateRequest(array $payload, string $basis = 'vehicle_group'): UpdateServiceTypeRequest
    {
        $service = new ServiceType();
        $service->forceFill(['id' => 'service-a', 'context' => 'portal', 'pricing_basis' => $basis]);
        $request = UpdateServiceTypeRequest::create('/service-types/service-a', 'PUT', $payload);
        $route = new Route('PUT', '/service-types/{service_type}', fn () => null);
        $route->bind($request);
        $route->setParameter('service_type', $service);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function validator(FormRequest $request): \Illuminate\Validation\Validator
    {
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        return $validator;
    }
}
