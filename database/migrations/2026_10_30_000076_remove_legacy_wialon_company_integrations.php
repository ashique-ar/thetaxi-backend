<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wialon_integrations')) {
            return;
        }

        $legacyRows = DB::table('wialon_integrations')->orderBy('created_at')->orderBy('id')->get();
        if ($legacyRows->isEmpty()) {
            Schema::drop('wialon_integrations');

            return;
        }

        $settings = DB::table('wialon_installation_settings')->where('id', 'installation')->first();
        $source = $settings ?: $legacyRows->first();
        foreach ($legacyRows as $row) {
            if (decrypt($row->token) !== decrypt($source->token)
                || $row->base_url !== $source->base_url
                || (bool) $row->enabled !== (bool) $source->enabled) {
                throw new RuntimeException('Wialon company configurations have different credentials, endpoints, or enabled states. Reconcile them into the installation settings before retrying.');
            }
        }

        if (! $settings) {
            DB::table('wialon_installation_settings')->insert([
                'id' => 'installation',
                'token' => $source->token,
                'base_url' => $source->base_url,
                'resource_ids' => $this->mergedJson($legacyRows, 'resource_ids'),
                'group_ids' => $this->mergedJson($legacyRows, 'group_ids'),
                'unit_ids' => $this->mergedJson($legacyRows, 'unit_ids'),
                'enabled' => $source->enabled,
                'created_at' => $source->created_at,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('wialon_installation_settings')->where('id', 'installation')->update([
                'resource_ids' => $this->mergedJson(collect([$settings])->concat($legacyRows), 'resource_ids'),
                'group_ids' => $this->mergedJson(collect([$settings])->concat($legacyRows), 'group_ids'),
                'unit_ids' => $this->mergedJson(collect([$settings])->concat($legacyRows), 'unit_ids'),
                'updated_at' => now(),
            ]);
        }

        Schema::drop('wialon_integrations');
    }

    private function mergedJson(\Illuminate\Support\Collection $rows, string $column): string
    {
        return json_encode($rows->flatMap(static function (object $row) use ($column): array {
            $values = $row->{$column} ? json_decode($row->{$column}, true, 512, JSON_THROW_ON_ERROR) : [];

            return is_array($values) ? $values : [];
        })->unique()->values()->all(), JSON_THROW_ON_ERROR);
    }

    public function down(): void
    {
        throw new RuntimeException('Company-scoped Wialon integrations cannot be restored after consolidation.');
    }
};
