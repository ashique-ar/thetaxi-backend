<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_decision_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('decision_key', 160);
            $table->unsignedInteger('version');
            $table->json('value');
            $table->string('status', 20);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('reason');
            $table->char('request_checksum', 64);
            $table->uuid('idempotency_key');
            $table->uuid('prepared_by')->index();
            $table->uuid('supersedes_id')->nullable()->index();
            $table->uuid('approval_idempotency_key')->nullable();
            $table->char('approval_request_checksum', 64)->nullable();
            $table->uuid('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'decision_key', 'version'], 'tenant_decision_company_key_version_unique');
            $table->unique(['company_id', 'idempotency_key'], 'tenant_decision_company_idempotency_unique');
            $table->unique(['company_id', 'approval_idempotency_key'], 'tenant_decision_approval_idempotency_unique');
            $table->index(['company_id', 'decision_key', 'status', 'effective_from'], 'tenant_decision_effective_lookup');
        });

        DB::table('website_settings')->whereNotNull('company_id')->where('type', 'like', 'decision.%')->whereNull('deleted_at')->orderBy('id')->each(function ($setting): void {
            $payload = json_decode((string) $setting->value, true);
            if (! is_array($payload) || ! is_array($payload['value'] ?? null) || empty($payload['updated_by'])) return;
            $id = (string) Str::uuid();
            DB::table('tenant_decision_versions')->insert([
                'id' => $id, 'company_id' => $setting->company_id, 'decision_key' => substr($setting->type, 9), 'version' => 1,
                'value' => json_encode($payload['value'], JSON_THROW_ON_ERROR), 'status' => $payload['status'] ?? 'draft',
                'effective_from' => null, 'effective_until' => null,
                'reason' => $payload['reason'] ?? 'Imported from the pre-versioned tenant decision projection.',
                'request_checksum' => hash('sha256', (string) $setting->value),
                'idempotency_key' => $payload['idempotency_key'] ?? (string) Str::uuid(), 'prepared_by' => $payload['updated_by'],
                'approved_by' => $payload['approved_by'] ?? null, 'approved_at' => $payload['approved_at'] ?? null,
                'created_at' => $setting->created_at ?? now(), 'updated_at' => $setting->updated_at ?? now(),
            ]);
            $payload['version_id'] = $id;
            $payload['version'] = 1;
            DB::table('website_settings')->where('id', $setting->id)->update(['value' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        });
    }

    public function down(): void
    {
        $unsafeHistory = DB::table('tenant_decision_versions')->where(function ($query): void {
            $query->whereNotNull('effective_from')->orWhere('version', '>', 1)->orWhereNotIn('status', ['approved', 'draft']);
        })->exists();
        if ($unsafeHistory) throw new RuntimeException('Rollback refused: effective-dated or superseded tenant decision history must be retained.');
        DB::table('website_settings')->where('type', 'like', 'decision.%')->whereNull('deleted_at')->orderBy('id')->each(function ($setting): void {
            $payload = json_decode((string) $setting->value, true);
            if (! is_array($payload)) return;
            unset($payload['version_id'], $payload['version']);
            DB::table('website_settings')->where('id', $setting->id)->update(['value' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        });
        Schema::dropIfExists('tenant_decision_versions');
    }
};
