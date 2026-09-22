<?php

use App\Models\Vehicle\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('driver_license_renewals')) {
            $previousByDriver = [];
            DB::table('driver_license_renewals')->orderBy('created_at')->get()->each(function ($renewal) use (&$previousByDriver): void {
                $previousId = $previousByDriver[$renewal->driver_id] ?? null;
                if ($previousId) DB::table('documents')->where('id', $previousId)->update(['status' => 'superseded']);
                DB::table('documents')->insert([
                    'id' => $renewal->id, 'documentable_type' => 'driver', 'documentable_id' => $renewal->driver_id,
                    'document_type' => 'driver_license', 'document_number' => $renewal->new_license_no,
                    'expiry_date' => $renewal->new_expiry, 'disk' => 'public', 'path' => '', 'file_name' => '',
                    'file_size' => 0, 'status' => 'verified', 'replaces_document_id' => $previousId,
                    'metadata' => json_encode(['issued_date' => $renewal->new_issued_at,
                        'previous_license_no' => $renewal->previous_license_no, 'previous_expiry' => $renewal->previous_expiry]),
                    'verified_at' => $renewal->created_at, 'verified_by' => $renewal->recorded_by,
                    'created_user_id' => $renewal->recorded_by, 'created_at' => $renewal->created_at,
                    'updated_at' => $renewal->updated_at, 'deleted_at' => $renewal->deleted_at,
                ]);
                $previousByDriver[$renewal->driver_id] = $renewal->id;
            });
            Schema::drop('driver_license_renewals');
        }

        if (! Schema::hasTable('vehicle_revenue_licenses')) return;

        $links = [];
        DB::table('vehicle_revenue_licenses')->orderBy('created_at')->get()->each(function ($license) use (&$links): void {
            $files = json_decode($license->document_files ?: '[]', true) ?: [];
            $first = $files[0] ?? [];
            DB::table('documents')->insert([
                'id' => $license->id,
                'documentable_type' => (new Vehicle)->getMorphClass(),
                'documentable_id' => $license->vehicle_id,
                'document_type' => 'vehicle_revenue_license',
                'document_number' => $license->license_number ?: $license->id,
                'expiry_date' => $license->expiry_date,
                'disk' => 'public',
                'path' => $first['path'] ?? '',
                'file_name' => $first['name'] ?? basename($first['path'] ?? ''),
                'file_size' => 0,
                'file_type' => null,
                'status' => $license->status === 'renewed' ? 'superseded' : $license->status,
                'metadata' => json_encode([
                    'issued_date' => $license->issued_date,
                    'renewal_reminder_date' => $license->renewal_reminder_date,
                    'renewal_date' => $license->renewal_date,
                    'authority_name' => $license->authority_name,
                    'document_files' => $files,
                    'notes' => $license->notes,
                ]),
                'created_user_id' => $license->created_user_id,
                'updated_user_id' => $license->updated_user_id,
                'created_at' => $license->created_at,
                'updated_at' => $license->updated_at,
                'deleted_at' => $license->deleted_at,
            ]);
            if ($license->renewed_from_id) $links[$license->id] = $license->renewed_from_id;
        });
        foreach ($links as $id => $previousId) DB::table('documents')->where('id', $id)->update(['replaces_document_id' => $previousId]);
        Schema::drop('vehicle_revenue_licenses');
    }

    public function down(): void
    {
        if (! Schema::hasTable('driver_license_renewals')) {
            Schema::create('driver_license_renewals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('driver_id')->constrained('drivers')->cascadeOnDelete();
                $table->string('previous_license_no')->nullable();
                $table->date('previous_expiry')->nullable();
                $table->string('new_license_no');
                $table->date('new_issued_at')->nullable();
                $table->date('new_expiry');
                $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
            DB::table('documents')->where('document_type', 'driver_license')->orderBy('created_at')->get()->each(function ($document): void {
                $meta = json_decode($document->metadata ?: '[]', true) ?: [];
                DB::table('driver_license_renewals')->insert([
                    'id' => $document->id, 'driver_id' => $document->documentable_id,
                    'previous_license_no' => $meta['previous_license_no'] ?? null,
                    'previous_expiry' => $meta['previous_expiry'] ?? null,
                    'new_license_no' => $document->document_number,
                    'new_issued_at' => $meta['issued_date'] ?? null, 'new_expiry' => $document->expiry_date,
                    'recorded_by' => $document->created_user_id, 'created_at' => $document->created_at,
                    'updated_at' => $document->updated_at, 'deleted_at' => $document->deleted_at,
                ]);
            });
            DB::table('documents')->where('document_type', 'driver_license')->delete();
        }

        if (Schema::hasTable('vehicle_revenue_licenses')) return;
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

        DB::table('documents')->where('document_type', 'vehicle_revenue_license')->orderBy('created_at')->get()->each(function ($document): void {
            $meta = json_decode($document->metadata ?: '[]', true) ?: [];
            DB::table('vehicle_revenue_licenses')->insert([
                'id' => $document->id, 'vehicle_id' => $document->documentable_id,
                'license_number' => $document->document_number, 'issued_date' => $meta['issued_date'] ?? null,
                'expiry_date' => $document->expiry_date, 'renewal_reminder_date' => $meta['renewal_reminder_date'] ?? null,
                'renewal_date' => $meta['renewal_date'] ?? null, 'renewed_from_id' => $document->replaces_document_id,
                'authority_name' => $meta['authority_name'] ?? null,
                'document_files' => json_encode($meta['document_files'] ?? []),
                'status' => $document->status === 'superseded' ? 'renewed' : $document->status,
                'notes' => $meta['notes'] ?? null, 'created_user_id' => $document->created_user_id,
                'updated_user_id' => $document->updated_user_id, 'created_at' => $document->created_at,
                'updated_at' => $document->updated_at, 'deleted_at' => $document->deleted_at,
            ]);
        });
        DB::table('documents')->where('document_type', 'vehicle_revenue_license')->delete();
    }
};
