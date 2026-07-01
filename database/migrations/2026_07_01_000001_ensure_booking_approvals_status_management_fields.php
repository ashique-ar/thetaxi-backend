<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = $this->tableName();

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'processed_by')) {
                $table->uuid('processed_by')->nullable()->after('approver_id');
            }

            if (!Schema::hasColumn($tableName, 'approval_type')) {
                $table->string('approval_type')->default('manager')->after('priority');
            }

            if (!Schema::hasColumn($tableName, 'notes')) {
                $table->text('notes')->nullable()->after('comments');
            }

            if (!Schema::hasColumn($tableName, 'conditions')) {
                $table->json('conditions')->nullable()->after('notes');
            }

            if (!Schema::hasColumn($tableName, 'requested_at')) {
                $table->timestamp('requested_at')->nullable()->after('conditions');
            }

            if (!Schema::hasColumn($tableName, 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('requested_at');
            }
        });

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'processed_by') && !$this->indexExists($tableName, 'booking_approvals_processed_by_index')) {
                $table->index('processed_by');
            }

            if (Schema::hasColumn($tableName, 'approval_type') && !$this->indexExists($tableName, 'booking_approvals_approval_type_index')) {
                $table->index('approval_type');
            }
        });
    }

    public function down(): void
    {
        $tableName = $this->tableName();

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if ($this->indexExists($tableName, 'booking_approvals_processed_by_index')) {
                $table->dropIndex('booking_approvals_processed_by_index');
            }

            if ($this->indexExists($tableName, 'booking_approvals_approval_type_index')) {
                $table->dropIndex('booking_approvals_approval_type_index');
            }
        });

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $columns = array_values(array_filter([
                Schema::hasColumn($tableName, 'processed_by') ? 'processed_by' : null,
                Schema::hasColumn($tableName, 'approval_type') ? 'approval_type' : null,
                Schema::hasColumn($tableName, 'notes') ? 'notes' : null,
                Schema::hasColumn($tableName, 'conditions') ? 'conditions' : null,
                Schema::hasColumn($tableName, 'requested_at') ? 'requested_at' : null,
                Schema::hasColumn($tableName, 'processed_at') ? 'processed_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function tableName(): string
    {
        return Schema::getConnection()->getDriverName() === 'pgsql'
            ? 'public.booking_approvals'
            : 'booking_approvals';
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $indexName);
    }
};
