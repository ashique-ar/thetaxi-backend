<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($tableName)) {
            return;
        }

        if (! $schema->hasColumn($tableName, 'attribute_changes')) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->json('attribute_changes')->nullable()->after('causer_id');
            });
        }

        $database = DB::connection($connection);

        $database->table($tableName)
            ->whereNotNull('properties')
            ->orderBy('id')
            ->eachById(function (object $activity) use ($database, $tableName): void {
                $properties = $this->decodeJson($activity->properties);
                $changes = array_intersect_key($properties, array_flip(['attributes', 'old']));

                if ($changes === []) {
                    return;
                }

                $remainingProperties = array_diff_key($properties, array_flip(['attributes', 'old']));

                $database->table($tableName)
                    ->where('id', $activity->id)
                    ->update([
                        'attribute_changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                        'properties' => $remainingProperties === []
                            ? null
                            : json_encode($remainingProperties, JSON_THROW_ON_ERROR),
                    ]);
            });

        if ($schema->hasColumn($tableName, 'batch_uuid')) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->dropColumn('batch_uuid');
            });
        }
    }

    public function down(): void
    {
        $tableName = config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($tableName)) {
            return;
        }

        if (! $schema->hasColumn($tableName, 'batch_uuid')) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->uuid('batch_uuid')->nullable()->after('properties');
            });
        }

        if (! $schema->hasColumn($tableName, 'attribute_changes')) {
            return;
        }

        $database = DB::connection($connection);

        $database->table($tableName)
            ->whereNotNull('attribute_changes')
            ->orderBy('id')
            ->eachById(function (object $activity) use ($database, $tableName): void {
                $properties = $this->decodeJson($activity->properties);
                $changes = $this->decodeJson($activity->attribute_changes);

                $database->table($tableName)
                    ->where('id', $activity->id)
                    ->update([
                        'properties' => json_encode(array_merge($properties, $changes), JSON_THROW_ON_ERROR),
                    ]);
            });

        $schema->table($tableName, function (Blueprint $table): void {
            $table->dropColumn('attribute_changes');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
};
