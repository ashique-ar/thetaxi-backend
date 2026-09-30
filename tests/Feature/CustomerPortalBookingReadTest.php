<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserContext;
use App\Services\UserContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerPortalBookingReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        activity()->disableLogging();
        foreach (['booking_items', 'bookings', 'user_contexts', 'customers', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name'); $table->string('last_name')->nullable();
            $table->string('email'); $table->string('password');
            $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->string('code')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('user_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id');
            $table->string('context_type'); $table->uuid('context_id');
            $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('customer_id');
            $table->uuid('service_type_id'); $table->uuid('vehicle_group_id');
            $table->string('booking_number')->nullable(); $table->string('status');
            $table->string('approval_status')->nullable(); $table->boolean('is_corporate_booking')->default(false);
            $table->decimal('total_actual', 12, 2)->nullable(); $table->string('payment_status')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('booking_items', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('booking_id');
            $table->uuid('vehicle_group_id'); $table->string('status');
            $table->dateTime('from_date')->nullable(); $table->time('from_time')->nullable();
            $table->dateTime('to_date')->nullable(); $table->time('to_time')->nullable();
            $table->json('pickup_location')->nullable(); $table->json('dropoff_location')->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->timestamps(); $table->softDeletes();
        });
    }

    private function customer(): array
    {
        $user = User::create([
            'first_name' => 'Request', 'last_name' => 'Owner',
            'email' => Str::uuid().'@example.test', 'password' => bcrypt('password'), 'is_active' => true,
        ]);
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId, 'user_id' => $user->id, 'code' => 'C'.Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $context = UserContext::create([
            'user_id' => $user->id, 'context_type' => 'customer',
            'context_id' => $customerId, 'is_active' => true,
        ]);
        return [$user, $customerId, $context];
    }

    private function booking(string $customerId): string
    {
        $id = (string) Str::uuid();
        DB::table('bookings')->insert([
            'id' => $id, 'customer_id' => $customerId,
            'service_type_id' => (string) Str::uuid(), 'vehicle_group_id' => (string) Str::uuid(),
            'booking_number' => 'B-'.Str::random(6), 'status' => 'pending',
            'approval_status' => 'pending', 'total_actual' => 99999,
            'payment_status' => 'overdue', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('booking_items')->insert([
            'id' => (string) Str::uuid(), 'booking_id' => $id,
            'vehicle_group_id' => (string) Str::uuid(), 'status' => 'pending',
            'from_date' => now()->addDay(), 'pickup_location' => json_encode(['address' => 'Colombo']),
            'dropoff_location' => json_encode(['address' => 'Kandy']),
            'total_price' => 88888, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_customer_reads_only_owned_requests_without_financial_or_inventory_data(): void
    {
        [$owner, $ownerId, $context] = $this->customer();
        [, $otherId] = $this->customer();
        $ownBooking = $this->booking($ownerId);
        $foreignBooking = $this->booking($otherId);
        $headers = [
            'X-Active-Context-Type' => 'customer',
            'X-Active-Context-Id' => $context->id,
            'X-Active-Portal-Profile' => 'customer',
        ];
        $contexts = \Mockery::mock(UserContextService::class);
        $contexts->shouldReceive('resolveActiveContextFromRequest')->andReturn([
            'id' => (string) $context->id, 'portal_profile' => 'customer',
        ]);
        app()->instance(UserContextService::class, $contexts);

        $list = $this->actingAs($owner, 'api')->withHeaders($headers)
            ->getJson('/api/customer-portal/bookings')->assertOk();
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.id', $ownBooking);
        $this->assertStringNotContainsString('99999', $list->getContent());
        $this->assertStringNotContainsString('88888', $list->getContent());
        $this->assertStringNotContainsString('payment_', $list->getContent());
        $this->assertStringNotContainsString('vehicle_id', $list->getContent());

        $detail = $this->actingAs($owner, 'api')->withHeaders($headers)
            ->getJson('/api/customer-portal/bookings/'.$ownBooking)->assertOk();
        $detail->assertJsonPath('data.id', $ownBooking);
        $detail->assertJsonPath('data.trips.0.pickup', 'Colombo');
        $detail->assertJsonMissingPath('data.trips.0.request_status');
        $detail->assertJsonPath('data.request_status', 'Under review');
        $this->assertStringNotContainsString('99999', $detail->getContent());
        $this->assertStringNotContainsString('88888', $detail->getContent());

        DB::table('bookings')->where('id', $ownBooking)->update([
            'status' => 'cancelled', 'approval_status' => 'rejected', 'updated_at' => now(),
        ]);
        DB::table('booking_items')->where('booking_id', $ownBooking)->update([
            'status' => 'cancelled', 'updated_at' => now(),
        ]);
        $this->actingAs($owner, 'api')->withHeaders($headers)
            ->getJson('/api/customer-portal/bookings/'.$ownBooking)
            ->assertOk()
            ->assertJsonPath('data.request_status', 'Cancelled')
            ->assertJsonPath('data.trips.0.status', 'Cancelled');

        DB::table('bookings')->where('id', $ownBooking)->update([
            'status' => 'completed', 'approval_status' => 'pending', 'updated_at' => now(),
        ]);
        $this->actingAs($owner, 'api')->withHeaders($headers)
            ->getJson('/api/customer-portal/bookings/'.$ownBooking)
            ->assertOk()
            ->assertJsonPath('data.request_status', 'Completed');

        $this->actingAs($owner, 'api')->withHeaders($headers)
            ->getJson('/api/customer-portal/bookings/'.$foreignBooking)->assertNotFound();
        $this->actingAs($owner, 'api')->withHeaders(array_merge($headers, ['X-Active-Context-Id' => (string) Str::uuid()]))
            ->getJson('/api/customer-portal/bookings')->assertForbidden();
    }
}
