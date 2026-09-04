<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_notification_policy_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('event_type', 160);
            $table->string('channel', 20);
            $table->string('recipient_scope', 40);
            $table->unsignedInteger('version');
            $table->string('title_template', 255);
            $table->text('body_template');
            $table->json('allowed_placeholders');
            $table->boolean('mandatory')->default(false);
            $table->unsignedInteger('escalation_after_minutes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->char('policy_checksum', 64);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'event_type', 'channel', 'recipient_scope', 'version'], 'sales_commission_notification_policy_version_unique');
            $table->index(['company_id', 'event_type', 'channel', 'status', 'effective_from'], 'sales_commission_notification_policy_effective_idx');
        });
        DB::statement("ALTER TABLE sales_commission_notification_policy_versions ADD CONSTRAINT sales_commission_notification_policy_checker CHECK (approved_by IS NULL OR approved_by <> created_by)");
        DB::statement("ALTER TABLE sales_commission_notification_policy_versions ADD CONSTRAINT sales_commission_notification_policy_period CHECK (effective_until IS NULL OR effective_until > effective_from)");
        DB::statement("ALTER TABLE sales_commission_notification_policy_versions ADD CONSTRAINT sales_commission_notification_policy_surface CHECK (event_type IN ('sales.commission.held', 'sales.commission.hold_released') AND channel = 'in_app' AND recipient_scope = 'beneficiary')");

        Schema::create('sales_commission_notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('event_type', 160);
            $table->string('channel', 20);
            $table->boolean('enabled');
            $table->foreignUuid('updated_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->unique(['sales_profile_id', 'event_type', 'channel'], 'sales_commission_notification_preference_unique');
        });
        DB::statement("ALTER TABLE sales_commission_notification_preferences ADD CONSTRAINT sales_commission_notification_preference_surface CHECK (event_type IN ('sales.commission.held', 'sales.commission.hold_released') AND channel = 'in_app')");

        Schema::create('sales_commission_notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('source_outbox_event_id')->constrained('domain_outbox_events')->restrictOnDelete();
            $table->foreignUuid('commission_decision_id')->constrained('sales_commission_decisions')->restrictOnDelete();
            $table->foreignUuid('commission_hold_release_id')->nullable()->constrained('sales_commission_hold_releases')->restrictOnDelete();
            $table->foreignUuid('policy_version_id')->nullable()->constrained('sales_commission_notification_policy_versions')->restrictOnDelete();
            $table->foreignUuid('recipient_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('recipient_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 160);
            $table->string('channel', 20)->default('in_app');
            $table->string('recipient_scope', 40)->default('beneficiary');
            $table->string('status', 40);
            $table->string('blocked_code', 80)->nullable();
            $table->text('encrypted_rendered_payload')->nullable();
            $table->char('payload_checksum', 64)->nullable();
            $table->string('idempotency_key', 200)->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->uuid('database_notification_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['source_outbox_event_id', 'channel', 'recipient_scope'], 'sales_commission_notification_source_unique');
            $table->index(['status', 'available_at'], 'sales_commission_notification_delivery_due_idx');
            $table->index(['company_id', 'commission_decision_id', 'created_at'], 'sales_commission_notification_decision_idx');
        });

        Schema::create('sales_commission_notification_delivery_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained('sales_commission_notification_deliveries')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->unsignedInteger('attempt_number');
            $table->string('status', 40);
            $table->string('reason_code', 80)->nullable();
            $table->char('payload_checksum', 64)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['delivery_id', 'event_type', 'attempt_number'], 'sales_commission_notification_delivery_event_unique');
        });
    }

    public function down(): void
    {
        foreach (['sales_commission_notification_delivery_events', 'sales_commission_notification_deliveries',
            'sales_commission_notification_preferences', 'sales_commission_notification_policy_versions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to drop {$table} while governed notification evidence exists.");
            }
        }

        Schema::dropIfExists('sales_commission_notification_delivery_events');
        Schema::dropIfExists('sales_commission_notification_deliveries');
        Schema::dropIfExists('sales_commission_notification_preferences');
        Schema::dropIfExists('sales_commission_notification_policy_versions');
    }
};
