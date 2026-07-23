<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Move all booking item-related fields from bookings to booking_items table.
     * This includes:
     * - vehicle_group_id, vehicle_id, driver_id, service_type_id
     * - from_date, to_date, from_time, to_time
     * - pickup/dropoff locations and coordinates
     * - is_self_driven
     * 
     * The bookings table should only contain booking-level metadata.
     */
    public function up(): void
    {
        // First, ensure booking_items table has all necessary columns
        Schema::table('booking_items', function (Blueprint $table) {
            // Add location coordinate fields if they don't exist
            if (!Schema::hasColumn('booking_items', 'pickup_latitude')) {
                $table->decimal('pickup_latitude', 10, 7)->nullable()->after('dropoff_location');
            }
            if (!Schema::hasColumn('booking_items', 'pickup_longitude')) {
                $table->decimal('pickup_longitude', 10, 7)->nullable()->after('pickup_latitude');
            }
            if (!Schema::hasColumn('booking_items', 'pickup_landmark')) {
                $table->string('pickup_landmark')->nullable()->after('pickup_longitude');
            }
            if (!Schema::hasColumn('booking_items', 'dropoff_latitude')) {
                $table->decimal('dropoff_latitude', 10, 7)->nullable()->after('pickup_landmark');
            }
            if (!Schema::hasColumn('booking_items', 'dropoff_longitude')) {
                $table->decimal('dropoff_longitude', 10, 7)->nullable()->after('dropoff_latitude');
            }
            if (!Schema::hasColumn('booking_items', 'dropoff_landmark')) {
                $table->string('dropoff_landmark')->nullable()->after('dropoff_longitude');
            }
            if (!Schema::hasColumn('booking_items', 'is_self_driven')) {
                $table->boolean('is_self_driven')->default(false)->after('dropoff_landmark');
            }
        });

        // Migrate existing data from bookings to booking_items
        $this->migrateBookingDataToItems();

        // Remove columns from bookings table
        Schema::table('bookings', function (Blueprint $table) {
            // Drop indexes first (only if they exist)
            if (Schema::hasColumn('bookings', 'vehicle_id')) {
                $table->dropIndex(['vehicle_id']);
            }
            if (Schema::hasColumn('bookings', 'driver_id')) {
                $table->dropIndex(['driver_id']);
            }
            if (Schema::hasColumn('bookings', 'service_type_id')) {
                $table->dropIndex(['service_type_id']);
            }
            if (Schema::hasColumn('bookings', 'vehicle_group_id')) {
                $table->dropIndex(['vehicle_group_id']);
            }
            if (Schema::hasColumn('bookings', 'pickup_latitude') && Schema::hasColumn('bookings', 'pickup_longitude')) {
                $table->dropIndex(['pickup_latitude', 'pickup_longitude']);
            }
            if (Schema::hasColumn('bookings', 'dropoff_latitude') && Schema::hasColumn('bookings', 'dropoff_longitude')) {
                $table->dropIndex(['dropoff_latitude', 'dropoff_longitude']);
            }
            
            // Drop the columns (only if they exist)
            $columnsToDrop = [];
            $possibleColumns = [
                'vehicle_group_id',
                'vehicle_id',
                'driver_id',
                'service_type_id',
                'from_date',
                'to_date',
                'from_time',
                'to_time',
                'pickup_location',
                'dropoff_location',
                'pickup_latitude',
                'pickup_longitude',
                'pickup_landmark',
                'dropoff_latitude',
                'dropoff_longitude',
                'dropoff_landmark',
                'is_self_driven',
            ];
            
            foreach ($possibleColumns as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $columnsToDrop[] = $column;
                }
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Migrate existing booking data to booking_items
     */
    private function migrateBookingDataToItems(): void
    {
        // Check if the columns still exist in bookings table
        $hasVehicleGroupId = Schema::hasColumn('bookings', 'vehicle_group_id');
        $hasServiceTypeId = Schema::hasColumn('bookings', 'service_type_id');
        
        // If columns don't exist, skip migration (already done or manually removed)
        if (!$hasVehicleGroupId && !$hasServiceTypeId) {
            Log::info('Booking columns already removed, skipping data migration');
            return;
        }

        // Get all bookings that don't have booking items yet
        $bookingsWithoutItems = DB::table('bookings')
            ->leftJoin('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
            ->whereNull('booking_items.id')
            ->where('bookings.deleted_at', null)
            ->select('bookings.*')
            ->get();

        foreach ($bookingsWithoutItems as $booking) {
            // Only create booking item if booking has essential data
            $vehicleGroupId = $hasVehicleGroupId ? ($booking->vehicle_group_id ?? null) : null;
            $serviceTypeId = $hasServiceTypeId ? ($booking->service_type_id ?? null) : null;
            
            if ($vehicleGroupId || $serviceTypeId) {
                DB::table('booking_items')->insert([
                    'id' => (string) Str::uuid(),
                    'booking_id' => $booking->id,
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                    'vehicle_id' => Schema::hasColumn('bookings', 'vehicle_id') ? ($booking->vehicle_id ?? null) : null,
                    'driver_id' => Schema::hasColumn('bookings', 'driver_id') ? ($booking->driver_id ?? null) : null,
                    'quantity' => 1,
                    'unit_price' => $booking->base_amount ?? 0,
                    'total_price' => $booking->base_amount ?? 0,
                    'from_date' => Schema::hasColumn('bookings', 'from_date') ? ($booking->from_date ?? null) : null,
                    'to_date' => Schema::hasColumn('bookings', 'to_date') ? ($booking->to_date ?? null) : null,
                    'from_time' => Schema::hasColumn('bookings', 'from_time') ? ($booking->from_time ?? null) : null,
                    'to_time' => Schema::hasColumn('bookings', 'to_time') ? ($booking->to_time ?? null) : null,
                    'pickup_location' => Schema::hasColumn('bookings', 'pickup_location') ? ($booking->pickup_location ?? null) : null,
                    'dropoff_location' => Schema::hasColumn('bookings', 'dropoff_location') ? ($booking->dropoff_location ?? null) : null,
                    'pickup_latitude' => Schema::hasColumn('bookings', 'pickup_latitude') ? ($booking->pickup_latitude ?? null) : null,
                    'pickup_longitude' => Schema::hasColumn('bookings', 'pickup_longitude') ? ($booking->pickup_longitude ?? null) : null,
                    'pickup_landmark' => Schema::hasColumn('bookings', 'pickup_landmark') ? ($booking->pickup_landmark ?? null) : null,
                    'dropoff_latitude' => Schema::hasColumn('bookings', 'dropoff_latitude') ? ($booking->dropoff_latitude ?? null) : null,
                    'dropoff_longitude' => Schema::hasColumn('bookings', 'dropoff_longitude') ? ($booking->dropoff_longitude ?? null) : null,
                    'dropoff_landmark' => Schema::hasColumn('bookings', 'dropoff_landmark') ? ($booking->dropoff_landmark ?? null) : null,
                    'is_self_driven' => Schema::hasColumn('bookings', 'is_self_driven') ? ($booking->is_self_driven ?? false) : false,
                    'currency' => $booking->currency ?? 'LKR',
                    'exchange_rate' => '1.000000',
                    'status' => $booking->status ?? 'pending',
                    'requires_approval' => $booking->requires_approval ?? false,
                    'approved_at' => $booking->confirmed_at ?? null,
                    'approved_by' => $booking->approval_by ?? null,
                    'item_type' => 'vehicle_group',
                    'pricing_breakdown' => $booking->pricing_snapshot ?? null,
                    'metadata' => json_encode([
                        'migrated_from_booking' => true,
                        'migration_date' => now()->toISOString(),
                    ]),
                    'created_at' => $booking->created_at ?? now(),
                    'updated_at' => $booking->updated_at ?? now(),
                ]);
            }
        }

        // Update existing booking items with location data if they don't have it
        if (Schema::hasColumn('bookings', 'pickup_latitude')) {
            $existingItems = DB::table('booking_items')
                ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                ->whereNull('booking_items.pickup_latitude')
                ->whereNotNull('bookings.pickup_latitude')
                ->select('booking_items.id as item_id', 'bookings.*')
                ->get();

            foreach ($existingItems as $item) {
                DB::table('booking_items')
                    ->where('id', $item->item_id)
                    ->update([
                        'pickup_latitude' => $item->pickup_latitude,
                        'pickup_longitude' => $item->pickup_longitude,
                        'pickup_landmark' => $item->pickup_landmark,
                        'dropoff_latitude' => $item->dropoff_latitude,
                        'dropoff_longitude' => $item->dropoff_longitude,
                        'dropoff_landmark' => $item->dropoff_landmark,
                        'is_self_driven' => $item->is_self_driven ?? false,
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add columns to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->uuid('service_type_id')->nullable()->index()->after('log_code');
            $table->uuid('vehicle_group_id')->nullable()->index()->after('service_type_id');
            $table->uuid('vehicle_id')->nullable()->index()->after('vehicle_group_id');
            $table->uuid('driver_id')->nullable()->index()->after('vehicle_id');
            
            $table->dateTime('from_date')->nullable()->after('booking_date');
            $table->dateTime('to_date')->nullable()->after('from_date');
            $table->time('from_time')->nullable()->after('to_date');
            $table->time('to_time')->nullable()->after('from_time');
            
            $table->json('pickup_location')->nullable()->after('third_party_ref');
            $table->json('dropoff_location')->nullable()->after('pickup_location');
            $table->decimal('pickup_latitude', 10, 7)->nullable()->after('dropoff_location');
            $table->decimal('pickup_longitude', 10, 7)->nullable()->after('pickup_latitude');
            $table->string('pickup_landmark')->nullable()->after('pickup_longitude');
            $table->decimal('dropoff_latitude', 10, 7)->nullable()->after('pickup_landmark');
            $table->decimal('dropoff_longitude', 10, 7)->nullable()->after('dropoff_latitude');
            $table->string('dropoff_landmark')->nullable()->after('dropoff_longitude');
            
            $table->boolean('is_self_driven')->default(false)->after('dropoff_landmark');
            
            // Re-add indexes
            $table->index(['pickup_latitude', 'pickup_longitude']);
            $table->index(['dropoff_latitude', 'dropoff_longitude']);
        });

        // Restore data from booking_items
        $this->restoreDataFromBookingItems();

        // Remove columns from booking_items
        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_latitude',
                'pickup_longitude',
                'pickup_landmark',
                'dropoff_latitude',
                'dropoff_longitude',
                'dropoff_landmark',
                'is_self_driven',
            ]);
        });
    }

    /**
     * Restore data from booking_items back to bookings
     */
    private function restoreDataFromBookingItems(): void
    {
        // Get first booking item for each booking
        $bookingItems = DB::table('booking_items')
            ->select(
                'booking_id',
                'service_type_id',
                'vehicle_group_id',
                'vehicle_id',
                'driver_id',
                'from_date',
                'to_date',
                'from_time',
                'to_time',
                'pickup_location',
                'dropoff_location',
                'pickup_latitude',
                'pickup_longitude',
                'pickup_landmark',
                'dropoff_latitude',
                'dropoff_longitude',
                'dropoff_landmark',
                'is_self_driven'
            )
            ->whereIn('id', function ($query) {
                $query->select(DB::raw('MIN(id)'))
                    ->from('booking_items')
                    ->groupBy('booking_id');
            })
            ->get();

        foreach ($bookingItems as $item) {
            DB::table('bookings')
                ->where('id', $item->booking_id)
                ->update([
                    'service_type_id' => $item->service_type_id,
                    'vehicle_group_id' => $item->vehicle_group_id,
                    'vehicle_id' => $item->vehicle_id,
                    'driver_id' => $item->driver_id,
                    'from_date' => $item->from_date,
                    'to_date' => $item->to_date,
                    'from_time' => $item->from_time,
                    'to_time' => $item->to_time,
                    'pickup_location' => $item->pickup_location,
                    'dropoff_location' => $item->dropoff_location,
                    'pickup_latitude' => $item->pickup_latitude,
                    'pickup_longitude' => $item->pickup_longitude,
                    'pickup_landmark' => $item->pickup_landmark,
                    'dropoff_latitude' => $item->dropoff_latitude,
                    'dropoff_longitude' => $item->dropoff_longitude,
                    'dropoff_landmark' => $item->dropoff_landmark,
                    'is_self_driven' => $item->is_self_driven,
                ]);
        }
    }
};
