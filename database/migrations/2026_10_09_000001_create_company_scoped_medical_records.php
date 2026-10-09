<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documents') && ! Schema::hasColumn('documents', 'metadata')) {
            Schema::table('documents', fn (Blueprint $table) => $table->json('metadata')->nullable());
        }

        Schema::create('medical_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        foreach ([
            ['medical_certificate', 'Medical Certificate'],
            ['health_checkup', 'Health Check'],
            ['fitness_certificate', 'Fitness Certificate'],
            ['vaccination_record', 'Vaccination Record'],
            ['injury_report', 'Injury Report'],
            ['lab_report', 'Lab Report'],
            ['other', 'Other'],
        ] as [$code, $name]) {
            DB::table('medical_categories')->insert([
                'id' => (string) Str::uuid(),
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('medical_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            $table->foreignUuid('medical_category_id')->constrained('medical_categories')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('record_number', 80);
            $table->date('issued_date');
            $table->date('valid_until')->nullable();
            $table->string('issuing_authority', 255)->nullable();
            $table->string('status', 30)->default('active');
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'record_number']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'status', 'valid_until']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('medical_records') && DB::table('medical_records')->exists()) {
            throw new RuntimeException('Medical-record rollback refused while records exist; reconcile and retain them before rollback.');
        }

        if (Schema::hasTable('documents') && DB::table('documents')->where('documentable_type', 'medical_record')->exists()) {
            throw new RuntimeException('Medical-record rollback refused while medical documents exist; reconcile and retain them before rollback.');
        }

        Schema::dropIfExists('medical_records');
        Schema::dropIfExists('medical_categories');
    }
};
