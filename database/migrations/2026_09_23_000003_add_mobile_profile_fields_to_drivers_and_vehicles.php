<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->foreignUuid('profile_photo_document_id')->nullable()->constrained('documents')->nullOnDelete();
        });
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignUuid('make_id')->nullable()->constrained('vehicle_makes')->nullOnDelete();
            $table->foreignUuid('model_id')->nullable()->constrained('vehicle_models')->nullOnDelete();
        });

        // Normalize previously approved onboarding records once. Runtime mobile
        // responses then read only operational driver/vehicle/document tables.
        DB::table('driver_onboarding_applications')
            ->where('status', 'approved')
            ->whereNotNull('driver_id')
            ->whereNotNull('vehicle_id')
            ->orderBy('id')
            ->chunk(100, function ($applications): void {
                foreach ($applications as $application) {
                    $payload = is_string($application->payload)
                        ? json_decode($application->payload, true)
                        : (array) $application->payload;
                    DB::table('vehicles')->where('id', $application->vehicle_id)->update([
                        'make_id' => data_get($payload, 'vehicle.make_id'),
                        'model_id' => data_get($payload, 'vehicle.model_id'),
                    ]);
                    $photoId = DB::table('documents')
                        ->where('onboarding_application_id', $application->id)
                        ->where('document_type', 'driver_photo')
                        ->latest('created_at')
                        ->value('id');
                    if ($photoId) {
                        DB::table('drivers')->where('id', $application->driver_id)->update([
                            'profile_photo_document_id' => $photoId,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('drivers', fn (Blueprint $table) => $table->dropConstrainedForeignId('profile_photo_document_id'));
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('model_id');
            $table->dropConstrainedForeignId('make_id');
        });
    }
};
