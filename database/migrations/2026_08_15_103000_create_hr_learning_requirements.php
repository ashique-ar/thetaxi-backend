<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5.17: "Required learning by role/location, due dates, reminders,
 * certification/expiry, and refresher scheduling." Certification/expiry
 * already exists per enrollment (`hr_learning_enrollments.certificate_expires_at`);
 * this adds only the missing requirement *definition* — which course is
 * required for which Staff type/organization unit, the due window from
 * assignment, and an optional refresher interval. Compliance-status
 * computation, due-date reminders, and scheduled refresher nomination are a
 * distinct, larger follow-up (a scheduler/notification feature) and are
 * deliberately out of scope for this reference-register slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_learning_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('course_id')->constrained('hr_courses')->restrictOnDelete();
            $table->json('applies_to_staff_types')->nullable();
            $table->json('applies_to_organization_unit_ids')->nullable();
            $table->unsignedSmallInteger('due_days');
            $table->unsignedSmallInteger('refresher_interval_days')->nullable();
            $table->string('status', 30)->default('active');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_learning_requirements');
    }
};
