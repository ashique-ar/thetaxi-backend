<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 255);
            $table->string('pay_frequency', 30);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_organization_change_events')
            && DB::table('hr_organization_change_events')->where('aggregate_type', 'payroll_group')->exists()) {
            throw new \LogicException('Refusing to remove retained HR payroll-group governance history. Disable the feature without rolling back used schema.');
        }

        Schema::dropIfExists('hr_payroll_groups');
    }
};
