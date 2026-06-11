<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('agreement_start_date')->nullable()->after('assignment_policy');
            $table->date('agreement_end_date')->nullable()->after('agreement_start_date');
            $table->string('agreement_status')->default('pending')->after('agreement_end_date')->index();
            $table->unsignedInteger('initial_mileage')->nullable()->after('agreement_status');
            $table->unsignedInteger('current_mileage')->nullable()->after('initial_mileage');
            $table->unsignedInteger('handover_mileage')->nullable()->after('current_mileage');
            $table->timestamp('handover_at')->nullable()->after('handover_mileage');
            $table->string('handover_location')->nullable()->after('handover_at');
            $table->text('handover_notes')->nullable()->after('handover_location');
            $table->json('actual_vehicle_images')->nullable()->after('thumbnail');
        });

        Schema::table('vehicle_insurances', function (Blueprint $table) {
            $table->date('renewal_reminder_date')->nullable()->after('end_date')->index();
            $table->date('renewal_date')->nullable()->after('renewal_reminder_date');
            $table->string('status')->default('active')->after('renewal_reminder_date')->index();
            $table->uuid('renewed_from_id')->nullable()->after('status')->index();
            $table->json('document_files')->nullable()->after('premium_amount');
            $table->text('remarks')->nullable()->after('document_files');
        });

        Schema::create('vehicle_revenue_licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id')->index();
            $table->string('license_number')->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->date('renewal_reminder_date')->nullable()->index();
            $table->date('renewal_date')->nullable();
            $table->uuid('renewed_from_id')->nullable()->index();
            $table->string('authority_name')->nullable();
            $table->json('document_files')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_revenue_licenses');

        Schema::table('vehicle_insurances', function (Blueprint $table) {
            $table->dropIndex(['renewal_reminder_date']);
            $table->dropIndex(['status']);
            $table->dropIndex(['renewed_from_id']);
            $table->dropColumn(['renewal_reminder_date', 'renewal_date', 'status', 'renewed_from_id', 'document_files', 'remarks']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'agreement_start_date',
                'agreement_end_date',
                'agreement_status',
                'initial_mileage',
                'current_mileage',
                'handover_mileage',
                'handover_at',
                'handover_location',
                'handover_notes',
                'actual_vehicle_images',
            ]);
        });
    }
};
