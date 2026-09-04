<?php

use App\Models\Staff;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SENSITIVE_FIELDS = [
        'account_holder_name',
        'bank_name',
        'bank_branch',
        'account_number',
        'routing_number',
        'wallet_identifier',
        'cheque_payee_name',
        'cheque_bank_name',
    ];

    public function up(): void
    {
        $this->assertStaffIdentityIsUnambiguous();

        Schema::table('staff', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->timestamp('employment_ended_at')->nullable()->index();
            $table->text('termination_reason')->nullable();
            $table->foreignUuid('terminated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $this->createActiveStaffIdentityConstraint();

        Schema::create('staff_scope_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('manager_staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('member_staff_id')->constrained('staff')->restrictOnDelete();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['manager_staff_id', 'effective_from', 'effective_until'], 'staff_scope_manager_effective_idx');
            $table->index(['member_staff_id', 'effective_from', 'effective_until'], 'staff_scope_member_effective_idx');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            foreach (self::SENSITIVE_FIELDS as $field) {
                $table->text($field)->nullable()->change();
            }
            $table->char('sensitive_fingerprint', 64)->nullable()->index();
            $table->timestamp('sensitive_encrypted_at')->nullable();
        });

        $this->encryptExistingStaffPaymentMethods();

        Schema::create('staff_payment_method_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('action', 20);
            $table->text('payload')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['staff_id', 'status']);
            $table->index(['requested_by', 'status']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('classification', 30)->default('operational')->index();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('supersedes_id')->nullable()->index();
            $table->timestamp('retention_until')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['classification', 'version', 'supersedes_id', 'retention_until']);
        });

        Schema::dropIfExists('staff_payment_method_changes');
        Schema::dropIfExists('staff_scope_assignments');
        $this->decryptExistingStaffPaymentMethods();

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['sensitive_fingerprint', 'sensitive_encrypted_at']);
            foreach (self::SENSITIVE_FIELDS as $field) {
                $table->string($field, 255)->nullable()->change();
            }
        });

        $this->dropActiveStaffIdentityConstraint();

        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terminated_by');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['employment_ended_at', 'termination_reason']);
        });
    }

    private function encryptExistingStaffPaymentMethods(): void
    {
        DB::table('payment_methods')
            ->whereIn('payable_type', ['staff', Staff::class])
            ->orderBy('id')
            ->get()
            ->each(function (object $method): void {
                $plain = [];
                $encrypted = [];

                foreach (self::SENSITIVE_FIELDS as $field) {
                    $value = $method->{$field};
                    $plain[$field] = $value;
                    $encrypted[$field] = $value === null || $value === '' || str_starts_with((string) $value, 'enc:')
                        ? $value
                        : 'enc:'.Crypt::encryptString((string) $value);
                }

                $encrypted['sensitive_fingerprint'] = $this->fingerprint($plain);
                $encrypted['sensitive_encrypted_at'] = now();
                DB::table('payment_methods')->where('id', $method->id)->update($encrypted);
            });
    }

    private function decryptExistingStaffPaymentMethods(): void
    {
        DB::table('payment_methods')
            ->whereIn('payable_type', ['staff', Staff::class])
            ->orderBy('id')
            ->get()
            ->each(function (object $method): void {
                $plain = [];
                foreach (self::SENSITIVE_FIELDS as $field) {
                    $value = $method->{$field};
                    $plain[$field] = is_string($value) && str_starts_with($value, 'enc:')
                        ? Crypt::decryptString(substr($value, 4))
                        : $value;
                }
                DB::table('payment_methods')->where('id', $method->id)->update($plain);
            });
    }

    private function fingerprint(array $values): string
    {
        return hash('sha256', collect($values)->map(fn ($value) => mb_strtolower(trim((string) $value)))->implode('|'));
    }

    private function createActiveStaffIdentityConstraint(): void
    {
        if (DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX staff_one_active_user_unique ON staff (user_id) WHERE deleted_at IS NULL');
        }
    }

    private function assertStaffIdentityIsUnambiguous(): void
    {
        $duplicates = DB::table('staff')
            ->select('user_id')
            ->whereNull('deleted_at')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot secure Staff identity: duplicate active user mappings require reviewed disposition for user IDs: '
                .$duplicates->take(20)->implode(', ')
            );
        }
    }

    private function dropActiveStaffIdentityConstraint(): void
    {
        if (DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS staff_one_active_user_unique');
        }
    }
};
